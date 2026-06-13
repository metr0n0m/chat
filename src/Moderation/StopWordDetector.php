<?php
declare(strict_types=1);

namespace Chat\Moderation;

use Chat\DB\Connection;

/**
 * Детектор стоп-слов (S3, теневой режим).
 * Списки — в таблице stop_words: глобальный (platform_owner) и
 * по-комнатный (owner комнаты). Совпадение — регистронезависимое вхождение.
 *
 * В тени сообщение НЕ блокируется и НЕ изменяется — только рапорт
 * в теневой журнал. Блокировка/санкция — этап S4.
 */
final class StopWordDetector
{
    /** @var array<string, array{patterns: list<string>, loaded_at: float}> */
    private static array $cache = [];
    private const CACHE_TTL_SEC = 30.0;

    public static function scan(int $roomId, int $userId, string $rawText): void
    {
        $patterns = array_merge(self::patterns('global', null), self::patterns('room', $roomId));
        if ($patterns === []) {
            return;
        }

        $haystack = mb_strtolower($rawText);
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && mb_strpos($haystack, mb_strtolower($pattern)) !== false) {
                ViolationReporter::report([
                    'trigger_code'   => 'stopword',
                    'target_user_id' => $userId,
                    'room_id'        => $roomId,
                    'details'        => 'совпадение: «' . mb_substr($pattern, 0, 100) . '»',
                ]);
                return; // одного совпадения достаточно — один рапорт на сообщение
            }
        }
    }

    /** Сброс кэша списков (для тестов и после правки списков). */
    public static function flushCache(): void
    {
        self::$cache = [];
    }

    /** @return list<string> */
    private static function patterns(string $scope, ?int $roomId): array
    {
        $key = $scope === 'global' ? 'global' : 'room:' . $roomId;
        $now = microtime(true);

        if (isset(self::$cache[$key]) && $now - self::$cache[$key]['loaded_at'] < self::CACHE_TTL_SEC) {
            return self::$cache[$key]['patterns'];
        }

        $rows = $scope === 'global'
            ? Connection::getInstance()->fetchAll("SELECT pattern FROM stop_words WHERE scope = 'global'")
            : Connection::getInstance()->fetchAll("SELECT pattern FROM stop_words WHERE scope = 'room' AND room_id = ?", [$roomId]);

        $patterns = array_values(array_map(static fn(array $r): string => (string) $r['pattern'], $rows));
        self::$cache[$key] = ['patterns' => $patterns, 'loaded_at' => $now];
        return $patterns;
    }
}
