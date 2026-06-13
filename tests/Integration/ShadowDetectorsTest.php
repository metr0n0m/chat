<?php
declare(strict_types=1);

namespace Tests\Integration;

use Chat\Chat\MessageController;
use Chat\DB\Connection;
use Chat\Moderation\BruteForceGuard;
use Chat\Moderation\FloodDetector;
use Chat\Moderation\StopWordDetector;
use Chat\Moderation\ViolationReporter;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDb;

/**
 * Этап S3: автодетекторы в теневом режиме.
 * Детекторы только пишут «что бы сделала система» в moderation_shadow_log;
 * поведение приложения (логин, отправка сообщений) не меняется.
 */
final class ShadowDetectorsTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::reset();
        ViolationReporter::flushRules();
        StopWordDetector::flushCache();
        FloodDetector::flush();
        // фиксируем конфиг теста независимо от посева миграции
        Connection::getInstance()->execute(
            'REPLACE INTO sanction_rules (rule_key, value_json) VALUES
             (\'mode\', \'"shadow"\'),
             (\'escalation\', \'{"stopword":{"start":"1h","threshold":5,"ladder":["1h","24h","7d","30d","permanent"]},"bruteforce":{"start":"3h","window_min":15,"attempts":10,"ladder":["3h","24h","7d","30d","permanent"]},"flood":{"start":"3h","threshold":5,"ladder":["3h","24h","7d","30d","permanent"]}}\')'
        );
    }

    private function shadowRows(string $trigger): array
    {
        return Connection::getInstance()->fetchAll(
            'SELECT * FROM moderation_shadow_log WHERE trigger_code = ? ORDER BY id',
            [$trigger]
        );
    }

    // ── brute-force ─────────────────────────────────────────────────────────

    public function testBruteForceReportsAtThresholdOnce(): void
    {
        $user = TestDb::user();
        for ($i = 0; $i < 12; $i++) {
            BruteForceGuard::onFailure((int) $user['id'], '203.0.113.5');
        }

        // ровно на пороге 10 — по одному рапорту на каждый ключ (acc и ip), без дублей на 11-й и 12-й
        $rows = $this->shadowRows('bruteforce');
        $this->assertCount(2, $rows, json_encode($rows, JSON_UNESCAPED_UNICODE));
        $this->assertSame('ban_global', $rows[0]['would_sanction']);
        $this->assertSame('3h', $rows[0]['would_duration']);

        $this->assertSame(12, BruteForceGuard::failCount('acc:' . (int) $user['id']));
        $this->assertSame(12, BruteForceGuard::failCount('ip:203.0.113.5'));
    }

    public function testSuccessfulLoginClearsCounters(): void
    {
        $user = TestDb::user();
        BruteForceGuard::onFailure((int) $user['id'], '203.0.113.5');
        BruteForceGuard::onFailure((int) $user['id'], '203.0.113.5');
        BruteForceGuard::onSuccess((int) $user['id'], '203.0.113.5');

        $this->assertSame(0, BruteForceGuard::failCount('acc:' . (int) $user['id']));
        $this->assertSame(0, BruteForceGuard::failCount('ip:203.0.113.5'));
    }

    public function testUnknownAccountStillCountsPerIp(): void
    {
        for ($i = 0; $i < 10; $i++) {
            BruteForceGuard::onFailure(null, '198.51.100.7');
        }
        $rows = $this->shadowRows('bruteforce');
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['target_user_id']);
        $this->assertSame('198.51.100.7', $rows[0]['target_ip']);
    }

    // ── stop-words ──────────────────────────────────────────────────────────

    public function testStopWordReportedButMessageNotBlocked(): void
    {
        $owner  = TestDb::user();
        $author = TestDb::user();
        $roomId = TestDb::room((int) $owner['id']);
        TestDb::addMember($roomId, (int) $author['id']);
        Connection::getInstance()->execute(
            "INSERT INTO stop_words (scope, room_id, pattern, created_by) VALUES ('global', NULL, 'запрещёнка', ?)",
            [(int) $owner['id']]
        );

        $actor = $author + ['nick_color' => '#fff', 'text_color' => '#eee', 'nickname' => null, 'custom_status' => null];
        $result = MessageController::send($roomId, (int) $author['id'], $actor, [
            'content' => 'тут есть ЗАПРЕЩЁНКА в тексте',
        ]);

        $this->assertArrayNotHasKey('error', $result, 'Тень не блокирует сообщение');
        $this->assertArrayHasKey('id', $result);

        $rows = $this->shadowRows('stopword');
        $this->assertCount(1, $rows);
        $this->assertSame((int) $author['id'], (int) $rows[0]['target_user_id']);
        $this->assertSame($roomId, (int) $rows[0]['room_id']);
        $this->assertSame('ban_room', $rows[0]['would_sanction']);
        $this->assertSame('1h', $rows[0]['would_duration']);
    }

    public function testRoomStopWordAppliesOnlyToItsRoom(): void
    {
        $owner  = TestDb::user();
        $roomA  = TestDb::room((int) $owner['id']);
        $roomB  = TestDb::room((int) $owner['id']);
        Connection::getInstance()->execute(
            "INSERT INTO stop_words (scope, room_id, pattern, created_by) VALUES ('room', ?, 'локальное', ?)",
            [$roomA, (int) $owner['id']]
        );

        StopWordDetector::scan($roomB, (int) $owner['id'], 'локальное слово в другой комнате');
        $this->assertCount(0, $this->shadowRows('stopword'));

        StopWordDetector::scan($roomA, (int) $owner['id'], 'локальное слово в своей комнате');
        $this->assertCount(1, $this->shadowRows('stopword'));
    }

    // ── flood ───────────────────────────────────────────────────────────────

    public function testFloodReportsAtThresholdOncePerWindow(): void
    {
        $user = TestDb::user();
        for ($i = 0; $i < 8; $i++) {
            FloodDetector::onRateLimitHit((int) $user['id'], 5);
        }

        $rows = $this->shadowRows('flood');
        $this->assertCount(1, $rows);
        $this->assertSame('ban_room', $rows[0]['would_sanction']);
        $this->assertSame(5, (int) $rows[0]['room_id']);
    }

    // ── эскалация по рецидиву ───────────────────────────────────────────────

    public function testEscalationClimbsLadderByPriorShadowHits(): void
    {
        $user = TestDb::user();
        // 5 прошлых теневых срабатываний stopword (threshold=5) → следующее уже на ступени 2 (24h)
        for ($i = 0; $i < 5; $i++) {
            ViolationReporter::report(['trigger_code' => 'stopword', 'target_user_id' => (int) $user['id']]);
        }
        $decision = ViolationReporter::report(['trigger_code' => 'stopword', 'target_user_id' => (int) $user['id']]);

        $this->assertSame('24h', $decision['would_duration']);
        $this->assertSame('shadow', $decision['mode']);
    }
}
