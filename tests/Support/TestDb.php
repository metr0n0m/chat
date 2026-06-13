<?php
declare(strict_types=1);

namespace Tests\Support;

use Chat\DB\Connection;

/**
 * Помощник для подготовки данных в тестовой базе.
 */
final class TestDb
{
    private static int $seq = 0;

    /** Очищает изменяемые таблицы между тестами. */
    public static function reset(): void
    {
        $db = Connection::getInstance();
        $db->execute('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'active_restrictions', 'moderation_events',
            'messages', 'invitations', 'room_members', 'rooms',
            'sessions', 'users', 'stop_words', 'login_attempts',
            'ws_outbox', 'moderation_shadow_log',
        ] as $table) {
            $db->execute('TRUNCATE TABLE `' . $table . '`');
        }
        $db->execute('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Создаёт пользователя и возвращает массив в форме WS-сессии
     * (как $session в EventRouter / $actor в RoomController::manage).
     */
    public static function user(string $globalRole = 'user', ?string $username = null): array
    {
        self::$seq++;
        $username ??= 'u' . self::$seq . '_' . substr($globalRole, 0, 8);
        $db = Connection::getInstance();
        $db->execute(
            'INSERT INTO users (username, email, password_hash, global_role) VALUES (?, ?, ?, ?)',
            [$username, $username . '@test.local', password_hash('secret123', PASSWORD_DEFAULT), $globalRole]
        );
        $id = (int) $db->lastInsertId();
        return [
            'id'              => $id,
            'username'        => $username,
            'global_role'     => $globalRole,
            'avatar_url'      => null,
            'can_create_room' => 0,
        ];
    }

    /** Создаёт публичную комнату с владельцем. Возвращает id комнаты. */
    public static function room(int $ownerId, string $type = 'public', string $category = 'user'): int
    {
        $db = Connection::getInstance();
        $db->execute(
            "INSERT INTO rooms (name, type, room_category, owner_id) VALUES (?, ?, ?, ?)",
            ['Тест #' . ++self::$seq, $type, $category, $ownerId]
        );
        $roomId = (int) $db->lastInsertId();
        $db->execute(
            "INSERT INTO room_members (room_id, user_id, room_role) VALUES (?, ?, 'owner')",
            [$roomId, $ownerId]
        );
        return $roomId;
    }

    /** Добавляет участника комнаты с заданной ролью. */
    public static function addMember(int $roomId, int $userId, string $role = 'member', ?int $joinedSecondsAgo = null): void
    {
        $db = Connection::getInstance();
        $db->execute(
            'INSERT INTO room_members (room_id, user_id, room_role, joined_at)
             VALUES (?, ?, ?, DATE_SUB(NOW(), INTERVAL ? SECOND))',
            [$roomId, $userId, $role, $joinedSecondsAgo ?? 0]
        );
    }

    /** Выдаёт кляп напрямую в БД (минуя бизнес-логику) для подготовки сценария. */
    public static function muteMember(int $roomId, int $userId, int $minutes, string $reason = 'тест'): void
    {
        Connection::getInstance()->execute(
            'UPDATE room_members
             SET muted_until = DATE_ADD(NOW(), INTERVAL ? MINUTE), mute_reason = ?
             WHERE room_id = ? AND user_id = ?',
            [$minutes, $reason, $roomId, $userId]
        );
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        return Connection::getInstance()->fetchOne($sql, $params);
    }
}
