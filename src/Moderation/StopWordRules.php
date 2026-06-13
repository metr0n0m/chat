<?php
declare(strict_types=1);

namespace Chat\Moderation;

use Chat\DB\Connection;

/**
 * Правила хранения стоп-слов (S5b) — чистая логика без HTTP/exit.
 * Валидация, вставка с обработкой дубля, выборка, удаление.
 * Проверку прав (кто может для какой области) делает вызывающий код (SanctionPanel):
 * глобальные — platform_owner, комнатные — владелец комнаты.
 */
final class StopWordRules
{
    private const DURATIONS = ['1h', '3h', '24h', '7d', '30d', 'permanent'];

    /** @return list<array> */
    public static function listGlobal(): array
    {
        return Connection::getInstance()->fetchAll(
            "SELECT id, pattern, duration, created_at FROM stop_words
             WHERE scope = 'global' ORDER BY pattern"
        );
    }

    /** @return list<array> */
    public static function listRoom(int $roomId): array
    {
        return Connection::getInstance()->fetchAll(
            "SELECT id, pattern, duration, created_at FROM stop_words
             WHERE scope = 'room' AND room_id = ? ORDER BY pattern",
            [$roomId]
        );
    }

    /**
     * @return array{added: true}|array{error: string, code: int}
     */
    public static function add(string $scope, ?int $roomId, string $pattern, string $duration, int $createdBy): array
    {
        $pattern = trim($pattern);
        if (mb_strlen($pattern) < 2 || mb_strlen($pattern) > 255) {
            return ['error' => 'Стоп-слово должно быть от 2 до 255 символов.', 'code' => 400];
        }
        if (!in_array($duration, self::DURATIONS, true)) {
            $duration = '1h';
        }

        try {
            Connection::getInstance()->execute(
                'INSERT INTO stop_words (scope, room_id, pattern, duration, created_by)
                 VALUES (?, ?, ?, ?, ?)',
                [$scope, $roomId, $pattern, $duration, $createdBy]
            );
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return ['error' => 'Такое стоп-слово уже есть в списке.', 'code' => 409];
            }
            throw $e;
        }
        StopWordDetector::flushCache();
        return ['added' => true];
    }

    /** @return array{scope: string, room_id: ?int}|null */
    public static function find(int $id): ?array
    {
        $row = Connection::getInstance()->fetchOne(
            'SELECT scope, room_id FROM stop_words WHERE id = ?',
            [$id]
        );
        if (!$row) {
            return null;
        }
        return ['scope' => (string) $row['scope'], 'room_id' => $row['room_id'] !== null ? (int) $row['room_id'] : null];
    }

    public static function remove(int $id): void
    {
        Connection::getInstance()->execute('DELETE FROM stop_words WHERE id = ?', [$id]);
        StopWordDetector::flushCache();
    }
}
