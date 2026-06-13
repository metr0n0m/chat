<?php
declare(strict_types=1);

namespace Chat\Moderation;

/**
 * Детектор флуда (S3, теневой режим).
 * Считает срывы rate-limit по пользователю в скользящем окне 60 секунд
 * (память WS-процесса; сброс при рестарте допустим для теневой калибровки).
 * На пороге (sanction_rules: flood.threshold) — один рапорт на окно.
 */
final class FloodDetector
{
    private const WINDOW_SEC = 60;

    /** @var array<int, array{count: int, window_start: float}> */
    private static array $hits = [];

    public static function onRateLimitHit(int $userId, ?int $roomId): void
    {
        $now   = microtime(true);
        $entry = self::$hits[$userId] ?? null;

        if ($entry === null || $now - $entry['window_start'] > self::WINDOW_SEC) {
            $entry = ['count' => 0, 'window_start' => $now];
        }
        $entry['count']++;
        self::$hits[$userId] = $entry;

        $threshold = max(2, (int) (ViolationReporter::rules()['escalation']['flood']['threshold'] ?? 5));
        if ($entry['count'] === $threshold) {
            ViolationReporter::report([
                'trigger_code'   => 'flood',
                'target_user_id' => $userId,
                'room_id'        => $roomId,
                'details'        => $entry['count'] . ' срывов rate-limit за ' . self::WINDOW_SEC . ' с',
            ]);
        }
    }

    /** Сброс счётчиков (для тестов). */
    public static function flush(): void
    {
        self::$hits = [];
    }
}
