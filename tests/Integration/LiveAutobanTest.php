<?php
declare(strict_types=1);

namespace Tests\Integration;

use Chat\DB\Connection;
use Chat\Moderation\SanctionService;
use Chat\Moderation\ViolationReporter;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDb;

/**
 * Этап S4: боевой режим автонома.
 * Включается ТОЛЬКО конфигурацией (mode='live'); по умолчанию — тень.
 * Проверяются защиты: иммунитет стаффа, circuit-breaker, kill-switch,
 * запрет снятия permanent-санкции системы кем-либо кроме владельца платформы.
 */
final class LiveAutobanTest extends TestCase
{
    private array $owner;
    private array $member;
    private int $roomId;

    protected function setUp(): void
    {
        TestDb::reset();
        ViolationReporter::flushRules();
        Connection::getInstance()->execute(
            'REPLACE INTO sanction_rules (rule_key, value_json) VALUES
             (\'mode\', \'"live"\'),
             (\'autonomy_state\', \'"active"\'),
             (\'circuit_breaker\', \'{"max_system_sanctions_per_min":3}\'),
             (\'escalation\', \'{"stopword":{"start":"1h","threshold":5,"ladder":["1h","24h","7d","30d","permanent"]},"bruteforce":{"start":"3h","window_min":15,"attempts":10,"ladder":["3h","24h","7d","30d","permanent"]},"flood":{"start":"3h","threshold":5,"ladder":["3h","24h","7d","30d","permanent"]}}\')'
        );

        $this->owner  = TestDb::user();
        $this->member = TestDb::user();
        $this->roomId = TestDb::room((int) $this->owner['id']);
        TestDb::addMember($this->roomId, (int) $this->member['id']);
    }

    public function testLiveStopwordAppliesRealRoomBan(): void
    {
        $decision = ViolationReporter::report([
            'trigger_code' => 'stopword', 'target_user_id' => (int) $this->member['id'],
            'room_id' => $this->roomId,
        ]);

        $this->assertSame('live', $decision['mode']);
        $this->assertSame('ban_room', $decision['would_sanction']);

        // реальная санкция: роль banned + событие системы + активное ограничение
        $row = TestDb::fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [$this->roomId, (int) $this->member['id']]
        );
        $this->assertSame('banned', $row['room_role']);

        $event = TestDb::fetchOne('SELECT * FROM moderation_events ORDER BY id DESC LIMIT 1');
        $this->assertSame('ban_room', $event['act']);
        $this->assertSame('system', $event['origin']);
        $this->assertSame('system', $event['actor_role']);
        $this->assertSame('stopword', $event['trigger_code']);
        $this->assertStringContainsString('Автобан', (string) $event['reason']);
        $this->assertSame('1h', $event['duration_type']);
        $this->assertTrue(SanctionService::isRestricted('ban_room', (int) $this->member['id'], $this->roomId));

        // события для клиентов ушли через мост (автоном без WS-контекста)
        $outbox = Connection::getInstance()->fetchAll('SELECT event_type FROM ws_outbox ORDER BY id');
        $this->assertSame(['banned_from_room', 'user_left'], array_column($outbox, 'event_type'));
    }

    public function testLiveBruteforceAppliesGlobalBan(): void
    {
        $decision = ViolationReporter::report([
            'trigger_code' => 'bruteforce', 'target_user_id' => (int) $this->member['id'],
            'target_ip' => '203.0.113.9',
        ]);

        $this->assertSame('live', $decision['mode']);
        $user = TestDb::fetchOne('SELECT is_banned FROM users WHERE id = ?', [(int) $this->member['id']]);
        $this->assertSame(1, (int) $user['is_banned']);

        $event = TestDb::fetchOne('SELECT * FROM moderation_events ORDER BY id DESC LIMIT 1');
        $this->assertSame('ban_global', $event['act']);
        $this->assertSame('3h', $event['duration_type']);
        $this->assertSame('203.0.113.9', $event['target_ip']);
    }

    /** §9.1: автоном не трогает персонал — даже в боевом режиме. */
    public function testLiveStaffImmunity(): void
    {
        $moderator = TestDb::user('moderator');
        TestDb::addMember($this->roomId, (int) $moderator['id']);

        $decision = ViolationReporter::report([
            'trigger_code' => 'stopword', 'target_user_id' => (int) $moderator['id'],
            'room_id' => $this->roomId,
        ]);

        $this->assertSame('live_refused', $decision['mode']);
        $row = TestDb::fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [$this->roomId, (int) $moderator['id']]
        );
        $this->assertNotSame('banned', $row['room_role']);

        $shadow = TestDb::fetchOne('SELECT details FROM moderation_shadow_log ORDER BY id DESC LIMIT 1');
        $this->assertStringContainsString('отказ движка', (string) $shadow['details']);
    }

    /** §9.3: предохранитель — всплеск автосанкций ставит автоном на паузу. */
    public function testCircuitBreakerPausesAutonomy(): void
    {
        // порог 3/мин (из setUp): три реальные санкции проходят
        for ($i = 0; $i < 3; $i++) {
            $victim = TestDb::user();
            TestDb::addMember($this->roomId, (int) $victim['id']);
            $d = ViolationReporter::report([
                'trigger_code' => 'stopword', 'target_user_id' => (int) $victim['id'],
                'room_id' => $this->roomId,
            ]);
            $this->assertSame('live', $d['mode']);
        }

        // четвёртая — предохранитель: паузим, не баним
        ViolationReporter::flushRules();
        $d4 = ViolationReporter::report([
            'trigger_code' => 'stopword', 'target_user_id' => (int) $this->member['id'],
            'room_id' => $this->roomId,
        ]);
        $this->assertSame('paused', $d4['mode']);

        $state = TestDb::fetchOne("SELECT value_json FROM sanction_rules WHERE rule_key = 'autonomy_state'");
        $this->assertSame('"paused"', $state['value_json']);

        $row = TestDb::fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [$this->roomId, (int) $this->member['id']]
        );
        $this->assertNotSame('banned', $row['room_role'], 'после срабатывания предохранителя баны не выдаются');

        // и пока пауза не снята вручную — только тень
        ViolationReporter::flushRules();
        $d5 = ViolationReporter::report([
            'trigger_code' => 'stopword', 'target_user_id' => (int) $this->member['id'],
            'room_id' => $this->roomId,
        ]);
        $this->assertSame('shadow', $d5['mode']);
    }

    /** §9.6: kill-switch — autonomy_state='paused' возвращает в тень одним флагом. */
    public function testKillSwitchForcesShadow(): void
    {
        ViolationReporter::setRule('autonomy_state', 'paused');
        ViolationReporter::flushRules();

        $decision = ViolationReporter::report([
            'trigger_code' => 'stopword', 'target_user_id' => (int) $this->member['id'],
            'room_id' => $this->roomId,
        ]);

        $this->assertSame('shadow', $decision['mode']);
        $row = TestDb::fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [$this->roomId, (int) $this->member['id']]
        );
        $this->assertNotSame('banned', $row['room_role']);
    }

    /** §8.2: цель только по IP — бан отложен, фиксируем в тени. */
    public function testIpOnlyTargetIsNotBannedInLive(): void
    {
        $decision = ViolationReporter::report([
            'trigger_code' => 'bruteforce', 'target_ip' => '198.51.100.20',
        ]);

        $this->assertSame('live_skipped', $decision['mode']);
        $shadow = TestDb::fetchOne('SELECT details FROM moderation_shadow_log ORDER BY id DESC LIMIT 1');
        $this->assertStringContainsString('IP', (string) $shadow['details']);
    }

    /** §4: permanent-санкцию системы снимает только владелец платформы. */
    public function testSystemPermanentLiftOnlyByPlatformOwner(): void
    {
        // бессрочная санкция системы (без minutes)
        $applied = SanctionService::apply([
            'type' => 'ban_global', 'target_user_id' => (int) $this->member['id'],
            'origin' => 'system', 'trigger_code' => 'spoof',
            'reason' => 'Автобан бессрочно: подмена сессии.',
        ]);
        $this->assertArrayHasKey('event_id', $applied);

        $admin = TestDb::user('admin');
        $deniedForAdmin = SanctionService::lift([
            'type' => 'unban_global', 'actor_id' => (int) $admin['id'],
            'target_user_id' => (int) $this->member['id'],
        ]);
        $this->assertArrayHasKey('error', $deniedForAdmin);
        $this->assertTrue(SanctionService::isRestricted('ban_global', (int) $this->member['id']));

        $po = TestDb::user('platform_owner');
        $allowedForOwner = SanctionService::lift([
            'type' => 'unban_global', 'actor_id' => (int) $po['id'],
            'target_user_id' => (int) $this->member['id'],
        ]);
        $this->assertArrayHasKey('event_id', $allowedForOwner);
        $this->assertFalse(SanctionService::isRestricted('ban_global', (int) $this->member['id']));
    }

    /** Эскалация в бою считается по реальным санкциям системы. */
    public function testLiveEscalationCountsRealSystemSanctions(): void
    {
        // 5 реальных систем-событий stopword за 30 дней → следующая ступень (24h)
        for ($i = 0; $i < 5; $i++) {
            Connection::getInstance()->execute(
                "INSERT INTO moderation_events (act, origin, actor_role, target_user_id, scope, room_id, trigger_code)
                 VALUES ('ban_room', 'system', 'system', ?, 'room', ?, 'stopword')",
                [(int) $this->member['id'], $this->roomId]
            );
        }
        // поднимаем порог предохранителя, чтобы тест не зацепил его
        ViolationReporter::setRule('circuit_breaker', ['max_system_sanctions_per_min' => 100]);
        ViolationReporter::flushRules();

        $decision = ViolationReporter::report([
            'trigger_code' => 'stopword', 'target_user_id' => (int) $this->member['id'],
            'room_id' => $this->roomId,
        ]);

        $this->assertSame('live', $decision['mode']);
        $this->assertSame('24h', $decision['would_duration']);
    }
}
