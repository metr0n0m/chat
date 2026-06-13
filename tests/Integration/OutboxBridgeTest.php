<?php
declare(strict_types=1);

namespace Tests\Integration;

use Chat\DB\Connection;
use Chat\Moderation\SanctionService;
use Chat\WebSocket\ConnectionManager;
use Chat\WebSocket\Outbox;
use Chat\WebSocket\OutboxDispatcher;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDb;

/**
 * Мост S2 (HTTP→WS): постановка событий в ws_outbox и их доставка.
 * Санкции с каналом 'http' должны рождать те же типизированные события,
 * что и живой WS-путь (контракт EventRouter).
 */
final class OutboxBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::reset();
        Connection::getInstance()->execute('TRUNCATE TABLE ws_outbox');
    }

    private function outboxRows(): array
    {
        return Connection::getInstance()->fetchAll('SELECT * FROM ws_outbox ORDER BY id');
    }

    public function testEnqueueWritesRow(): void
    {
        Outbox::toUser(7, 'unmuted_in_room', ['room_id' => 3, 'target_user_id' => 7]);

        $rows = $this->outboxRows();
        $this->assertCount(1, $rows);
        $this->assertSame('user', $rows[0]['audience']);
        $this->assertSame(7, (int) $rows[0]['target_id']);
        $payload = json_decode($rows[0]['payload_json'], true);
        $this->assertSame('unmuted_in_room', $payload['event']);
        $this->assertSame(3, $payload['room_id']);
    }

    public function testHttpUnmuteEmitsSameEventsAsWsPath(): void
    {
        $owner  = TestDb::user();
        $member = TestDb::user();
        $roomId = TestDb::room((int) $owner['id']);
        TestDb::addMember($roomId, (int) $member['id']);
        TestDb::muteMember($roomId, (int) $member['id'], 60);
        // зеркалим мьют в новую модель, как это делает apply()
        SanctionService::apply([
            'type' => 'mute', 'actor_id' => (int) $owner['id'],
            'target_user_id' => (int) $member['id'], 'room_id' => $roomId,
            'minutes' => 60, 'reason' => 'тест', 'channel' => 'ws',
        ]);
        Connection::getInstance()->execute('TRUNCATE TABLE ws_outbox');

        // как UserManager::roomUnmute (PHP-FPM)
        $result = SanctionService::lift([
            'type' => 'unmute', 'actor_id' => (int) $owner['id'],
            'target_user_id' => (int) $member['id'], 'room_id' => $roomId,
            'channel' => 'http',
        ]);
        $this->assertArrayHasKey('event_id', $result);

        $rows = $this->outboxRows();
        $this->assertCount(2, $rows, json_encode($rows, JSON_UNESCAPED_UNICODE));

        $this->assertSame('user', $rows[0]['audience']);
        $this->assertSame('unmuted_in_room', $rows[0]['event_type']);
        $this->assertSame((int) $member['id'], (int) $rows[0]['target_id']);

        $this->assertSame('room_staff', $rows[1]['audience']);
        $this->assertSame('room_updated', $rows[1]['event_type']);
        $this->assertSame($roomId, (int) $rows[1]['target_id']);
        $this->assertSame((int) $member['id'], (int) $rows[1]['exclude_user_id']);
        $staffPayload = json_decode($rows[1]['payload_json'], true);
        $this->assertTrue($staffPayload['data']['unmuted']);
    }

    public function testHttpGlobalBanEmitsForceLogoutAndKillsSessions(): void
    {
        $admin  = TestDb::user('admin');
        $target = TestDb::user();
        Connection::getInstance()->execute(
            "INSERT INTO sessions (user_id, token_hash, ip_ua_hash, expires_at)
             VALUES (?, REPEAT('a', 64), REPEAT('b', 64), DATE_ADD(NOW(), INTERVAL 1 DAY))",
            [(int) $target['id']]
        );

        SanctionService::apply([
            'type' => 'ban_global', 'actor_id' => (int) $admin['id'],
            'target_user_id' => (int) $target['id'], 'hours' => 24,
            'reason' => 'нарушение', 'channel' => 'http',
        ]);

        $sessions = Connection::getInstance()->fetchOne(
            'SELECT COUNT(*) AS c FROM sessions WHERE user_id = ?', [(int) $target['id']]
        );
        $this->assertSame(0, (int) $sessions['c'], 'I-9: глобальный бан рвёт сессии');

        $rows = $this->outboxRows();
        $this->assertCount(1, $rows);
        $this->assertSame('force_logout', $rows[0]['event_type']);
        $this->assertSame((int) $target['id'], (int) $rows[0]['target_id']);
    }

    public function testWsChannelDoesNotUseOutbox(): void
    {
        $owner  = TestDb::user();
        $member = TestDb::user();
        $roomId = TestDb::room((int) $owner['id']);
        TestDb::addMember($roomId, (int) $member['id']);

        SanctionService::apply([
            'type' => 'mute', 'actor_id' => (int) $owner['id'],
            'target_user_id' => (int) $member['id'], 'room_id' => $roomId,
            'minutes' => 30, 'reason' => 'x', 'channel' => 'ws',
        ]);

        $this->assertCount(0, $this->outboxRows(), 'WS-канал шлёт события сам, мост не нужен');
    }

    public function testDispatcherProcessesAndDeletesRows(): void
    {
        Outbox::toUser(1, 'unmuted_in_room', ['room_id' => 1]);
        Outbox::toRoom(2, 'numer_destroyed', ['room_id' => 2]);
        Outbox::toRoomStaff(3, 'room_updated', ['room_id' => 3, 'data' => []]);

        // ConnectionManager без живых соединений: доставка — no-op, но очередь должна очиститься
        $processed = OutboxDispatcher::dispatch(new ConnectionManager());

        $this->assertSame(3, $processed);
        $this->assertCount(0, $this->outboxRows());
    }
}
