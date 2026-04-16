<?php
declare(strict_types=1);

namespace Chat\Admin;

use Chat\DB\Connection;
use Chat\Security\CSRF;

class RoomManager
{
    public static function list(int $page = 1): void
    {
        $db     = Connection::getInstance();
        $offset = ($page - 1) * 50;

        $rooms = $db->fetchAll(
            "SELECT r.id, r.name, r.type, r.is_closed, r.created_at,
                    u.username AS owner_username,
                    (SELECT COUNT(*) FROM room_members rm WHERE rm.room_id = r.id AND rm.room_role != 'banned') AS member_count,
                    (SELECT COUNT(*) FROM messages m WHERE m.room_id = r.id AND m.is_deleted = 0) AS message_count
             FROM rooms r
             LEFT JOIN users u ON u.id = r.owner_id
             ORDER BY r.id DESC
             LIMIT 50 OFFSET $offset"
        );

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'rooms' => $rooms, 'page' => $page]);
        exit;
    }

    public static function rename(int $roomId, string $name): void
    {
        if (!CSRF::verifyRequest()) {
            self::jsonError('CSRF.', 403);
        }
        $name = trim($name);
        if (strlen($name) < 2 || strlen($name) > 100) {
            self::jsonError('Некорректное название.');
        }
        Connection::getInstance()->execute('UPDATE rooms SET name = ? WHERE id = ?', [$name, $roomId]);
        self::jsonSuccess(['updated' => true, 'name' => $name]);
    }

    public static function delete(int $roomId): void
    {
        if (!CSRF::verifyRequest()) {
            self::jsonError('CSRF.', 403);
        }
        Connection::getInstance()->execute('DELETE FROM rooms WHERE id = ?', [$roomId]);
        self::jsonSuccess(['deleted' => true]);
    }

    public static function members(int $roomId): void
    {
        $db      = Connection::getInstance();
        $members = $db->fetchAll(
            'SELECT u.id, u.username, u.global_role, rm.room_role, rm.joined_at, rm.banned_at
             FROM room_members rm
             JOIN users u ON u.id = rm.user_id
             WHERE rm.room_id = ?
             ORDER BY FIELD(rm.room_role, "owner","local_admin","local_moderator","member","banned"), u.username',
            [$roomId]
        );
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'members' => $members]);
        exit;
    }

    public static function setMemberRole(int $roomId, int $targetId, string $role): void
    {
        if (!CSRF::verifyRequest()) {
            self::jsonError('CSRF.', 403);
        }
        $allowed = ['owner', 'local_admin', 'local_moderator', 'member', 'banned'];
        if (!in_array($role, $allowed, true)) {
            self::jsonError('Недопустимая роль.');
        }
        Connection::getInstance()->execute(
            'UPDATE room_members SET room_role = ? WHERE room_id = ? AND user_id = ?',
            [$role, $roomId, $targetId]
        );
        self::jsonSuccess(['updated' => true]);
    }

    public static function changeOwner(int $roomId, int $newOwnerId): void
    {
        if (!CSRF::verifyRequest()) {
            self::jsonError('CSRF.', 403);
        }
        $db = Connection::getInstance();
        $db->beginTransaction();
        try {
            $db->execute(
                "UPDATE room_members SET room_role = 'member' WHERE room_id = ? AND room_role = 'owner'",
                [$roomId]
            );
            $db->execute(
                'INSERT INTO room_members (room_id, user_id, room_role) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE room_role = ?',
                [$roomId, $newOwnerId, 'owner', 'owner']
            );
            $db->execute('UPDATE rooms SET owner_id = ? WHERE id = ?', [$newOwnerId, $roomId]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            self::jsonError('Ошибка смены владельца.');
        }
        self::jsonSuccess(['updated' => true]);
    }

    public static function numeraArchive(int $page = 1, array $filters = []): void
    {
        $db     = Connection::getInstance();
        $offset = ($page - 1) * 50;
        $where  = ["r.type = 'numer' AND r.is_closed = 1"];
        $params = [];

        if (!empty($filters['username'])) {
            $where[]  = 'EXISTS (SELECT 1 FROM room_members rm2 JOIN users u2 ON u2.id = rm2.user_id WHERE rm2.room_id = r.id AND u2.username LIKE ?)';
            $params[] = '%' . $filters['username'] . '%';
        }
        if (!empty($filters['date_from'])) {
            $where[]  = 'DATE(r.closed_at) >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[]  = 'DATE(r.closed_at) <= ?';
            $params[] = $filters['date_to'];
        }

        $rooms = $db->fetchAll(
            'SELECT r.id, r.name, r.closed_at, r.created_at,
                    (SELECT COUNT(*) FROM messages m WHERE m.room_id = r.id) AS message_count,
                    GROUP_CONCAT(u.username ORDER BY u.username SEPARATOR ", ") AS participants
             FROM rooms r
             JOIN room_members rm ON rm.room_id = r.id
             JOIN users u ON u.id = rm.user_id
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY r.id
             ORDER BY r.closed_at DESC
             LIMIT 50 OFFSET ' . $offset,
            $params
        );

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'numera' => $rooms, 'page' => $page]);
        exit;
    }

    public static function numeraMessages(int $roomId): void
    {
        $db = Connection::getInstance();
        $room = $db->fetchOne("SELECT id, type FROM rooms WHERE id = ? AND type = 'numer'", [$roomId]);
        if (!$room) {
            self::jsonError('Нумер не найден.', 404);
        }
        $messages = $db->fetchAll(
            'SELECT m.id, m.content, m.type, m.created_at,
                    u.username, u.nick_color, u.avatar_url
             FROM messages m
             JOIN users u ON u.id = m.user_id
             WHERE m.room_id = ?
             ORDER BY m.created_at ASC',
            [$roomId]
        );
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'messages' => $messages]);
        exit;
    }

    private static function jsonError(string $msg, int $code = 400): never
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    }

    private static function jsonSuccess(array $data = []): never
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => true] + $data);
        exit;
    }
}
