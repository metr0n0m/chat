<?php
declare(strict_types=1);

namespace Chat\Chat;

use Chat\DB\Connection;

/**
 * Назначение дефолтных комнат новому пользователю.
 */
class DefaultRoomMembership
{
    /**
     * Добавляет нового пользователя в первые 5 открытых публичных комнат.
     * Использует INSERT IGNORE — безопасен при повторном вызове.
     */
    public static function joinDefaultRooms(Connection $db, int $userId): void
    {
        $rooms = $db->fetchAll(
            "SELECT id FROM rooms WHERE type = 'public' AND is_closed = 0 ORDER BY id LIMIT 5"
        );
        foreach ($rooms as $room) {
            $db->execute(
                'INSERT IGNORE INTO room_members (room_id, user_id, room_role) VALUES (?, ?, ?)',
                [(int) $room['id'], $userId, 'member']
            );
        }
    }
}
