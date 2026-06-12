<?php
declare(strict_types=1);

namespace Tests\Integration;

use Chat\Chat\RoomController;
use Chat\Moderation\SanctionService;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDb;

/**
 * Этап S1 движка санкций: единая точка apply/lift.
 * Проверяется тройная согласованность каждой операции:
 * старые поля (muted_until/room_role/is_banned) + журнал moderation_events
 * + горячая таблица active_restrictions — в одной транзакции.
 */
final class SanctionServiceTest extends TestCase
{
    private array $owner;
    private array $member;
    private int $roomId;

    protected function setUp(): void
    {
        TestDb::reset();
        $this->owner  = TestDb::user();
        $this->member = TestDb::user();
        $this->roomId = TestDb::room((int) $this->owner['id']);
        TestDb::addMember($this->roomId, (int) $this->member['id']);
    }

    private function manage(array $actor, array $data): array
    {
        return RoomController::manage($this->roomId, (int) $actor['id'], $actor, $data);
    }

    private function lastEvent(): ?array
    {
        return TestDb::fetchOne('SELECT * FROM moderation_events ORDER BY id DESC LIMIT 1');
    }

    public function testMuteWritesEventAndRestriction(): void
    {
        $result = $this->manage($this->owner, [
            'action' => 'mute', 'target_user_id' => (int) $this->member['id'],
            'minutes' => 30, 'reason' => 'флуд в чате',
        ]);
        $this->assertTrue($result['muted'] ?? false, json_encode($result, JSON_UNESCAPED_UNICODE));

        $event = $this->lastEvent();
        $this->assertSame('mute', $event['act']);
        $this->assertSame('realtime', $event['origin']);
        $this->assertSame((int) $this->owner['id'], (int) $event['actor_id']);
        $this->assertSame('owner', $event['actor_role'], 'Роль актора должна резолвиться по базе (AccessContext)');
        $this->assertSame((int) $this->member['id'], (int) $event['target_user_id']);
        $this->assertSame('room', $event['scope']);
        $this->assertSame('1h', $event['duration_type']);
        $this->assertSame('флуд в чате', $event['reason']);

        $this->assertTrue(SanctionService::isRestricted('mute', (int) $this->member['id'], $this->roomId));
    }

    public function testUnmuteWritesLiftEventLinkedToParent(): void
    {
        $this->manage($this->owner, [
            'action' => 'mute', 'target_user_id' => (int) $this->member['id'],
            'minutes' => 30, 'reason' => 'причина',
        ]);
        $muteEventId = (int) $this->lastEvent()['id'];

        $result = $this->manage($this->owner, [
            'action' => 'unmute', 'target_user_id' => (int) $this->member['id'],
        ]);
        $this->assertTrue($result['unmuted'] ?? false);

        $event = $this->lastEvent();
        $this->assertSame('unmute', $event['act']);
        $this->assertSame($muteEventId, (int) $event['parent_event_id'], 'Снятие должно ссылаться на событие выдачи');

        $this->assertFalse(SanctionService::isRestricted('mute', (int) $this->member['id'], $this->roomId));
    }

    public function testRoomBanWritesEventWithPreviousRole(): void
    {
        $result = $this->manage($this->owner, [
            'action' => 'ban', 'target_user_id' => (int) $this->member['id'],
        ]);
        $this->assertTrue($result['banned'] ?? false);

        $event = $this->lastEvent();
        $this->assertSame('ban_room', $event['act']);
        $this->assertSame('member', $event['previous_room_role']);
        $this->assertSame('permanent', $event['duration_type']);
        $this->assertTrue(SanctionService::isRestricted('ban_room', (int) $this->member['id'], $this->roomId));
    }

    public function testKickWritesEventWithoutRestriction(): void
    {
        $result = $this->manage($this->owner, [
            'action' => 'kick', 'target_user_id' => (int) $this->member['id'],
        ]);
        $this->assertTrue($result['kicked'] ?? false);

        $event = $this->lastEvent();
        $this->assertSame('kick', $event['act']);
        $this->assertSame('member', $event['previous_room_role']);

        $count = TestDb::fetchOne('SELECT COUNT(*) AS c FROM active_restrictions');
        $this->assertSame(0, (int) $count['c'], 'kick — разовое действие, не активное ограничение');
    }

    public function testGlobalBanAppliesAtomically(): void
    {
        $admin = TestDb::user('admin');
        $result = SanctionService::apply([
            'type'           => 'ban_global',
            'actor_id'       => (int) $admin['id'],
            'target_user_id' => (int) $this->member['id'],
            'hours'          => 24,
            'reason'         => 'нарушение правил',
        ]);
        $this->assertArrayHasKey('event_id', $result);

        $user = TestDb::fetchOne('SELECT is_banned, banned_by, banned_until, ban_reason FROM users WHERE id = ?', [(int) $this->member['id']]);
        $this->assertSame(1, (int) $user['is_banned']);
        $this->assertSame((int) $admin['id'], (int) $user['banned_by']);
        $this->assertNotNull($user['banned_until']);

        $event = $this->lastEvent();
        $this->assertSame('ban_global', $event['act']);
        $this->assertSame('global', $event['scope']);
        $this->assertSame('24h', $event['duration_type']);
        $this->assertSame('admin', $event['actor_role']);
        $this->assertTrue(SanctionService::isRestricted('ban_global', (int) $this->member['id']));
    }

    public function testGlobalUnbanLiftsAndClearsLegacyFields(): void
    {
        $admin = TestDb::user('admin');
        SanctionService::apply([
            'type' => 'ban_global', 'actor_id' => (int) $admin['id'],
            'target_user_id' => (int) $this->member['id'], 'hours' => 24, 'reason' => 'x',
        ]);
        $banEventId = (int) $this->lastEvent()['id'];

        $result = SanctionService::lift([
            'type' => 'unban_global', 'actor_id' => (int) $admin['id'],
            'target_user_id' => (int) $this->member['id'],
        ]);
        $this->assertArrayHasKey('event_id', $result);

        $user = TestDb::fetchOne('SELECT is_banned, banned_at, banned_until, ban_reason FROM users WHERE id = ?', [(int) $this->member['id']]);
        $this->assertSame(0, (int) $user['is_banned']);
        $this->assertNull($user['banned_until']);

        $event = $this->lastEvent();
        $this->assertSame('unban_global', $event['act']);
        $this->assertSame($banEventId, (int) $event['parent_event_id']);
        $this->assertFalse(SanctionService::isRestricted('ban_global', (int) $this->member['id']));
    }

    /** I-3 теперь закрыт и для mute: platform_owner нельзя заткнуть даже админу. */
    public function testMuteOfPlatformOwnerIsBlockedByEngine(): void
    {
        $po = TestDb::user('platform_owner');
        TestDb::addMember($this->roomId, (int) $po['id']);
        $admin = TestDb::user('admin');

        $result = $this->manage($admin, [
            'action' => 'mute', 'target_user_id' => (int) $po['id'],
            'minutes' => 30, 'reason' => 'попытка',
        ]);

        $this->assertArrayHasKey('error', $result);
        $row = TestDb::fetchOne(
            'SELECT muted_until FROM room_members WHERE room_id = ? AND user_id = ?',
            [$this->roomId, (int) $po['id']]
        );
        $this->assertNull($row['muted_until']);
    }

    public function testSelfSanctionIsBlockedByEngineDirectly(): void
    {
        $result = SanctionService::apply([
            'type' => 'mute', 'actor_id' => (int) $this->owner['id'],
            'target_user_id' => (int) $this->owner['id'],
            'room_id' => $this->roomId, 'minutes' => 30, 'reason' => 'сам себя',
        ]);
        $this->assertArrayHasKey('error', $result);
    }

    public function testExpireLapsedConvertsRestrictionToExpiredEvent(): void
    {
        $this->manage($this->owner, [
            'action' => 'mute', 'target_user_id' => (int) $this->member['id'],
            'minutes' => 30, 'reason' => 'скоро истечёт',
        ]);
        $muteEventId = (int) $this->lastEvent()['id'];

        // Принудительно состариваем ограничение
        \Chat\DB\Connection::getInstance()->execute(
            'UPDATE active_restrictions SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE)'
        );

        $count = SanctionService::expireLapsed();
        $this->assertSame(1, $count);

        $event = $this->lastEvent();
        $this->assertSame('restriction_expired', $event['act']);
        $this->assertSame('system', $event['origin']);
        $this->assertSame('system', $event['actor_role']);
        $this->assertSame($muteEventId, (int) $event['parent_event_id']);

        $left = TestDb::fetchOne('SELECT COUNT(*) AS c FROM active_restrictions');
        $this->assertSame(0, (int) $left['c']);
    }

    public function testAdminRoomUnbanPathLiftsRestriction(): void
    {
        // Бан, затем снятие напрямую через движок (как это делает UserManager::roomUnban)
        $this->manage($this->owner, ['action' => 'ban', 'target_user_id' => (int) $this->member['id']]);

        $result = SanctionService::lift([
            'type' => 'unban_room', 'actor_id' => (int) $this->owner['id'],
            'target_user_id' => (int) $this->member['id'], 'room_id' => $this->roomId,
        ]);
        $this->assertArrayHasKey('event_id', $result);

        $row = TestDb::fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [$this->roomId, (int) $this->member['id']]
        );
        $this->assertNull($row, 'Запись banned должна быть удалена (как в прежнем roomUnban)');
        $this->assertFalse(SanctionService::isRestricted('ban_room', (int) $this->member['id'], $this->roomId));
    }
}
