<?php
declare(strict_types=1);

namespace Tests\Integration;

use Chat\Chat\RoomController;
use Chat\DB\Connection;
use Chat\Moderation\SanctionService;
use Chat\Moderation\SanctionStats;
use Chat\Moderation\ViolationReporter;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDb;

/**
 * S5a: читающий слой мониторинга санкций (журнал, теневой журнал, статистика, IP-интел).
 */
final class SanctionStatsTest extends TestCase
{
    private array $owner;
    private array $admin;
    private array $memberA;
    private array $memberB;
    private int $roomId;

    protected function setUp(): void
    {
        TestDb::reset();
        ViolationReporter::flushRules();

        $this->owner   = TestDb::user();
        $this->admin   = TestDb::user('admin');
        $this->memberA = TestDb::user();
        $this->memberB = TestDb::user();
        $this->roomId  = TestDb::room((int) $this->owner['id']);
        foreach ([$this->memberA, $this->memberB] as $u) {
            TestDb::addMember($this->roomId, (int) $u['id']);
        }
    }

    private function manage(array $actor, array $data): array
    {
        return RoomController::manage($this->roomId, (int) $actor['id'], $actor, $data);
    }

    public function testEventsFeedReturnsActorAndTargetNames(): void
    {
        $this->manage($this->owner, [
            'action' => 'mute', 'target_user_id' => (int) $this->memberA['id'],
            'minutes' => 30, 'reason' => 'флуд',
        ]);

        $feed = SanctionStats::events([]);
        $this->assertCount(1, $feed['events']);
        $e = $feed['events'][0];
        $this->assertSame('mute', $e['act']);
        $this->assertSame($this->owner['username'], $e['actor_username']);
        $this->assertSame($this->memberA['username'], $e['target_username']);
        $this->assertFalse($feed['has_more']);
    }

    public function testEventsFilterByAct(): void
    {
        $this->manage($this->owner, ['action' => 'mute', 'target_user_id' => (int) $this->memberA['id'], 'minutes' => 30, 'reason' => 'x']);
        $this->manage($this->owner, ['action' => 'ban', 'target_user_id' => (int) $this->memberB['id']]);

        $bans = SanctionStats::events(['act' => 'ban_room']);
        $this->assertCount(1, $bans['events']);
        $this->assertSame('ban_room', $bans['events'][0]['act']);
    }

    public function testEventsRestrictRoomScopesToOwnersRoom(): void
    {
        $otherOwner = TestDb::user();
        $otherRoom  = TestDb::room((int) $otherOwner['id']);
        $victim     = TestDb::user();
        TestDb::addMember($otherRoom, (int) $victim['id']);

        // событие в чужой комнате
        RoomController::manage($otherRoom, (int) $otherOwner['id'], $otherOwner, [
            'action' => 'mute', 'target_user_id' => (int) $victim['id'], 'minutes' => 30, 'reason' => 'x',
        ]);
        // событие в нашей комнате
        $this->manage($this->owner, ['action' => 'mute', 'target_user_id' => (int) $this->memberA['id'], 'minutes' => 30, 'reason' => 'y']);

        $scoped = SanctionStats::events([], $this->roomId);
        $this->assertCount(1, $scoped['events']);
        $this->assertSame($this->roomId, (int) $scoped['events'][0]['room_id']);
    }

    public function testStatsAggregatesByTriggerAndActor(): void
    {
        $this->manage($this->owner, ['action' => 'mute', 'target_user_id' => (int) $this->memberA['id'], 'minutes' => 30, 'reason' => 'x']);
        $this->manage($this->admin, ['action' => 'ban', 'target_user_id' => (int) $this->memberB['id']]);

        $stats = SanctionStats::stats(30);

        // ручные действия попадают в '(ручное)'
        $triggers = array_column($stats['by_trigger'], 'count', 'trigger_code');
        $this->assertSame(2, (int) ($triggers['(ручное)'] ?? 0));

        // топ модераторов содержит обоих
        $actors = array_column($stats['top_actors'], 'count', 'actor_username');
        $this->assertSame(1, (int) ($actors[$this->owner['username']] ?? 0));
        $this->assertSame(1, (int) ($actors[$this->admin['username']] ?? 0));

        // активные ограничения: 1 мьют + 1 бан комнаты
        $this->assertSame(1, $stats['active_restrictions']['mute']);
        $this->assertSame(1, $stats['active_restrictions']['ban_room']);

        $this->assertSame('shadow', $stats['autonomy']['mode']);
    }

    public function testShadowLogReadback(): void
    {
        // дефолтная конфигурация в тени
        Connection::getInstance()->execute(
            "REPLACE INTO sanction_rules (rule_key, value_json) VALUES
             ('mode', '\"shadow\"'),
             ('escalation', '{\"stopword\":{\"threshold\":5,\"ladder\":[\"1h\",\"24h\"]}}')"
        );
        ViolationReporter::flushRules();
        ViolationReporter::report(['trigger_code' => 'stopword', 'target_user_id' => (int) $this->memberA['id'], 'room_id' => $this->roomId]);

        $feed = SanctionStats::shadow([]);
        $this->assertCount(1, $feed['shadow']);
        $this->assertSame('stopword', $feed['shadow'][0]['trigger_code']);
        $this->assertSame($this->memberA['username'], $feed['shadow'][0]['target_username']);
    }

    public function testIpIntelFlagsSharedIp(): void
    {
        // забаненный аккаунт с target_ip = X
        SanctionService::apply([
            'type' => 'ban_global', 'actor_id' => (int) $this->admin['id'],
            'target_user_id' => (int) $this->memberA['id'], 'hours' => 24,
            'reason' => 'нарушение', 'target_ip' => '203.0.113.50',
        ]);
        // активный аккаунт, действовавший как актор с того же actor_ip = X
        Connection::getInstance()->execute(
            "INSERT INTO moderation_events (act, origin, actor_id, actor_role, actor_ip, target_user_id, scope, room_id)
             VALUES ('mute', 'realtime', ?, 'owner', '203.0.113.50', ?, 'room', ?)",
            [(int) $this->memberB['id'], (int) $this->memberA['id'], $this->roomId]
        );

        $alerts = SanctionStats::ipIntel(30);
        $this->assertCount(1, $alerts);
        $this->assertSame('203.0.113.50', $alerts[0]['ip']);
        $this->assertContains($this->memberA['username'], $alerts[0]['banned']);
        $this->assertContains($this->memberB['username'], $alerts[0]['others']);
    }

    public function testIpIntelEmptyWhenNoOverlap(): void
    {
        SanctionService::apply([
            'type' => 'ban_global', 'actor_id' => (int) $this->admin['id'],
            'target_user_id' => (int) $this->memberA['id'], 'hours' => 24,
            'reason' => 'x', 'target_ip' => '203.0.113.51',
        ]);
        $this->assertSame([], SanctionStats::ipIntel(30));
    }
}
