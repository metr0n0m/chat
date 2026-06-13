<?php
declare(strict_types=1);

namespace Chat\Moderation;

use Chat\DB\Connection;

/**
 * Защита логина от перебора паролей (S3, теневой режим).
 * Закрывает оба вектора (SANCTIONS_ENGINE.md §6.1):
 *   targeted — много паролей к одному аккаунту: счётчик 'acc:<id>';
 *   spray    — один пароль по многим аккаунтам с одного IP: счётчик 'ip:<ip>'.
 *
 * Счётчики в БД (login_attempts), т.к. PHP-FPM не хранит состояние.
 * После 5 неудач — прогрессивная задержка ответа (потолок 2 секунды).
 * На пороге (10 неудач / 15 минут, из sanction_rules) — рапорт детектора
 * в теневой журнал. Реального бана нет до этапа S4.
 */
final class BruteForceGuard
{
    private const DELAY_FREE_FAILS = 5;
    private const MAX_DELAY_MS     = 2000;

    public static function onFailure(?int $accountId, string $ip): void
    {
        $rules     = ViolationReporter::rules()['escalation']['bruteforce'] ?? [];
        $windowMin = max(1, (int) ($rules['window_min'] ?? 15));
        $attempts  = max(2, (int) ($rules['attempts'] ?? 10));

        $counts = [];
        foreach (self::keys($accountId, $ip) as $key) {
            $counts[$key] = self::bump($key, $windowMin);
        }
        if ($counts === []) {
            return;
        }

        foreach ($counts as $key => $count) {
            // строго на пороге — одно срабатывание на окно, без дублей
            if ($count === $attempts) {
                ViolationReporter::report([
                    'trigger_code'   => 'bruteforce',
                    'target_user_id' => str_starts_with($key, 'acc:') ? $accountId : null,
                    'target_ip'      => $ip !== '' ? $ip : null,
                    'details'        => $key . ': ' . $count . ' неудач за ' . $windowMin . ' мин',
                ]);
            }
        }

        // Прогрессивная задержка — удорожает перебор уже сейчас, в тени
        $worst = max($counts);
        if ($worst > self::DELAY_FREE_FAILS) {
            $delayMs = min(self::MAX_DELAY_MS, ($worst - self::DELAY_FREE_FAILS) * 250);
            usleep($delayMs * 1000);
        }
    }

    public static function onSuccess(?int $accountId, string $ip): void
    {
        $keys = self::keys($accountId, $ip);
        if ($keys === []) {
            return;
        }
        Connection::getInstance()->execute(
            'DELETE FROM login_attempts WHERE attempt_key IN ('
            . implode(',', array_fill(0, count($keys), '?')) . ')',
            $keys
        );
    }

    /** Текущее число неудач в окне для ключа (для тестов/диагностики). */
    public static function failCount(string $key): int
    {
        $row = Connection::getInstance()->fetchOne(
            'SELECT fail_count FROM login_attempts WHERE attempt_key = ?',
            [$key]
        );
        return (int) ($row['fail_count'] ?? 0);
    }

    /** @return string[] */
    private static function keys(?int $accountId, string $ip): array
    {
        $keys = [];
        if ($accountId !== null && $accountId > 0) {
            $keys[] = 'acc:' . $accountId;
        }
        if ($ip !== '') {
            $keys[] = 'ip:' . mb_substr($ip, 0, 60);
        }
        return $keys;
    }

    /** Инкремент с автосбросом окна. Возвращает счётчик после инкремента. */
    private static function bump(string $key, int $windowMin): int
    {
        $db = Connection::getInstance();
        $db->execute(
            'INSERT INTO login_attempts (attempt_key, fail_count, window_start, last_attempt_at)
             VALUES (?, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                fail_count      = IF(window_start < DATE_SUB(NOW(), INTERVAL ? MINUTE), 1, fail_count + 1),
                window_start    = IF(window_start < DATE_SUB(NOW(), INTERVAL ? MINUTE), NOW(), window_start),
                last_attempt_at = NOW()',
            [$key, $windowMin, $windowMin]
        );
        return self::failCount($key);
    }
}
