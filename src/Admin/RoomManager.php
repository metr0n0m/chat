<?php
declare(strict_types=1);

namespace Chat\Admin;

use Chat\DB\Connection;
use Chat\Security\CSRF;

/**
 * Администрирование комнат и архива нумеров.
 * Last updated: 2026-04-17.
 */
class RoomManager
{
    /**
     * Список комнат с пагинацией.
     * Last updated: 2026-04-17.
     */
    public static function list(int $page = 1): void
    {
        $db = Connection::getInstance();
        $offset = max(0, ($page - 1) * 50);

        $rooms = $db->fetchAll(
            "SELECT r.id, r.name, r.type,
                    COALESCE(r.room_category, 'user') AS room_category,
                    r.is_closed, r.created_at,
                    TIMESTAMPDIFF(DAY, r.created_at, NOW()) AS days_running,
                    u.username AS owner_username,
                    (SELECT COUNT(*) FROM room_members rm WHERE rm.room_id = r.id AND rm.room_role != 'banned') AS member_count,
                    (SELECT COUNT(*) FROM messages m WHERE m.room_id = r.id AND m.is_deleted = 0) AS message_count
             FROM rooms r
             LEFT JOIN users u ON u.id = r.owner_id
             WHERE r.type = 'public'
             ORDER BY r.id DESC
             LIMIT 50 OFFSET $offset"
        );

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'rooms' => $rooms, 'page' => $page], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function setCategory(int $roomId, string $category): void
    {
        if (!CSRF::verifyRequest()) {
            self::jsonError('CSRF.', 403);
        }
        if (!in_array($category, ['permanent', 'user', 'commercial'], true)) {
            self::jsonError('Недопустимая категория.');
        }
        $db = Connection::getInstance();
        if (!$db->fetchOne('SELECT id FROM rooms WHERE id = ?', [$roomId])) {
            self::jsonError('Комната не найдена.', 404);
        }
        $db->execute('UPDATE rooms SET room_category = ? WHERE id = ?', [$category, $roomId]);
        self::jsonSuccess(['updated' => true, 'room_category' => $category]);
    }

    /**
     * Переименование комнаты.
     * Last updated: 2026-04-17.
     */
    public static function rename(int $roomId, string $name): void
    {
        if (!CSRF::verifyRequest()) {
            self::jsonError('CSRF.', 403);
        }

        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            self::jsonError('Некорректное название.');
        }

        Connection::getInstance()->execute('UPDATE rooms SET name = ? WHERE id = ?', [$name, $roomId]);
        self::jsonSuccess(['updated' => true, 'name' => $name]);
    }

    /**
     * Удаление комнаты.
     * Last updated: 2026-04-17.
     */
    public static function delete(int $roomId): void
    {
        if (!CSRF::verifyRequest()) {
            self::jsonError('CSRF.', 403);
        }
        $db   = Connection::getInstance();
        $room = $db->fetchOne('SELECT room_category FROM rooms WHERE id = ?', [$roomId]);
        if (!$room) {
            self::jsonError('Комната не найдена.', 404);
        }
        if (($room['room_category'] ?? 'user') === 'permanent') {
            self::jsonError('Постоянные комнаты нельзя удалить.', 403);
        }
        $db->beginTransaction();
        try {
            $db->execute('DELETE FROM messages WHERE room_id = ?', [$roomId]);
            $db->execute('DELETE FROM room_members WHERE room_id = ?', [$roomId]);
            $db->execute('DELETE FROM invitations WHERE room_id = ?', [$roomId]);
            $db->execute('DELETE FROM rooms WHERE id = ?', [$roomId]);
            $db->commit();
        } catch (\Throwable) {
            $db->rollBack();
            self::jsonError('Не удалось удалить комнату.');
        }
        self::jsonSuccess(['deleted' => true]);
    }

    /**
     * Активные нумера (не закрытые) с расширенной информацией.
     */
    public static function numeraActive(int $page = 1): void
    {
        $db     = Connection::getInstance();
        $offset = max(0, ($page - 1) * 50);

        $numera = $db->fetchAll(
            "SELECT r.id, r.name, r.created_at,
                    TIMESTAMPDIFF(MINUTE, r.created_at, NOW()) AS minutes_running,
                    u.username AS owner_username,
                    (SELECT COUNT(*) FROM room_members rm WHERE rm.room_id = r.id AND rm.room_role != 'banned') AS member_count,
                    GROUP_CONCAT(u2.username ORDER BY rm2.joined_at SEPARATOR ', ') AS participants
             FROM rooms r
             LEFT JOIN users u ON u.id = r.owner_id
             LEFT JOIN room_members rm2 ON rm2.room_id = r.id AND rm2.room_role != 'banned'
             LEFT JOIN users u2 ON u2.id = rm2.user_id
             WHERE r.type = 'numer' AND r.is_closed = 0
             GROUP BY r.id
             ORDER BY r.created_at DESC
             LIMIT 50 OFFSET $offset"
        );

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'numera' => $numera, 'page' => $page], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Список участников комнаты.
     * Last updated: 2026-04-17.
     */
    public static function members(int $roomId): void
    {
        $db = Connection::getInstance();
        $members = $db->fetchAll(
            'SELECT u.id, u.username, u.global_role, rm.room_role, rm.joined_at, rm.banned_at
             FROM room_members rm
             JOIN users u ON u.id = rm.user_id
             WHERE rm.room_id = ?
             ORDER BY FIELD(rm.room_role, "owner","local_admin","local_moderator","member","banned"), u.username',
            [$roomId]
        );
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'members' => $members], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Изменение роли участника в комнате.
     * Last updated: 2026-04-17.
     */
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

    /**
     * Передача ownership комнаты.
     * Last updated: 2026-04-17.
     */
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
        } catch (\Throwable) {
            $db->rollBack();
            self::jsonError('Ошибка смены владельца.');
        }

        self::jsonSuccess(['updated' => true]);
    }

    /**
     * Принудительно закрывает нумер (только если is_closed = 0).
     */
    public static function closeNumer(int $roomId): void
    {
        if (!CSRF::verifyRequest()) {
            self::jsonError('CSRF.', 403);
        }
        $db   = Connection::getInstance();
        $room = $db->fetchOne("SELECT id FROM rooms WHERE id = ? AND type = 'numer' AND is_closed = 0", [$roomId]);
        if (!$room) {
            self::jsonError('Нумер не найден или уже закрыт.', 404);
        }
        $db->execute('UPDATE rooms SET is_closed = 1, closed_at = NOW() WHERE id = ?', [$roomId]);
        self::jsonSuccess(['closed' => true, 'room_id' => $roomId]);
    }

    /**
     * Архив закрытых нумеров.
     * Last updated: 2026-04-17.
     *
     * @param array<string, mixed> $filters
     */
    public static function numeraArchive(int $page = 1, array $filters = []): void
    {
        $db = Connection::getInstance();
        $offset = max(0, ($page - 1) * 50);
        $where = ["r.type = 'numer' AND r.is_closed = 1"];
        $params = [];

        if (!empty($filters['username'])) {
            $where[] = 'EXISTS (SELECT 1 FROM room_members rm2 JOIN users u2 ON u2.id = rm2.user_id WHERE rm2.room_id = r.id AND u2.username LIKE ?)';
            $params[] = '%' . (string) $filters['username'] . '%';
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(r.closed_at) >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(r.closed_at) <= ?';
            $params[] = (string) $filters['date_to'];
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

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'numera' => $rooms, 'page' => $page], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Сообщения конкретного нумера из архива.
     * Last updated: 2026-04-17.
     */
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

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'messages' => $messages], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function roomMessages(int $roomId, int $page = 1, string $filterUser = ''): void
    {
        $db   = Connection::getInstance();
        $room = $db->fetchOne('SELECT id, name FROM rooms WHERE id = ?', [$roomId]);
        if (!$room) {
            self::jsonError('Комната не найдена.', 404);
        }

        $offset = max(0, ($page - 1) * 50);
        $where  = ["m.room_id = ?", "m.type != 'whisper'"];
        $params = [$roomId];

        if ($filterUser !== '') {
            $where[] = 'u.username LIKE ?';
            $params[] = '%' . $filterUser . '%';
        }

        $whereStr = implode(' AND ', $where);
        $total = (int) $db->fetchOne(
            "SELECT COUNT(*) AS c FROM messages m JOIN users u ON u.id = m.user_id WHERE $whereStr",
            $params
        )['c'];

        $messages = $db->fetchAll(
            "SELECT m.id, m.content, m.type, m.created_at, m.is_deleted,
                    u.id AS user_id, u.username
             FROM messages m
             JOIN users u ON u.id = m.user_id
             WHERE $whereStr
             ORDER BY m.created_at DESC
             LIMIT 50 OFFSET $offset",
            $params
        );

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success'  => true,
            'messages' => $messages,
            'total'    => $total,
            'page'     => $page,
            'room'     => $room,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function clearMessages(int $roomId): void
    {
        if (!CSRF::verifyRequest()) {
            self::jsonError('CSRF.', 403);
        }
        $actor = \Chat\Security\Session::current();
        $db    = Connection::getInstance();
        if (!$db->fetchOne('SELECT id FROM rooms WHERE id = ?', [$roomId])) {
            self::jsonError('Комната не найдена.', 404);
        }
        $db->execute(
            "UPDATE messages SET is_deleted = 1, deleted_by = ? WHERE room_id = ? AND type != 'whisper' AND is_deleted = 0",
            [(int) ($actor['id'] ?? 0), $roomId]
        );
        self::jsonSuccess(['cleared' => true]);
    }

    public static function clearUserMessages(int $roomId, int $userId): void
    {
        if (!CSRF::verifyRequest()) {
            self::jsonError('CSRF.', 403);
        }
        $actor = \Chat\Security\Session::current();
        $db    = Connection::getInstance();
        $db->execute(
            "UPDATE messages SET is_deleted = 1, deleted_by = ? WHERE room_id = ? AND user_id = ? AND type != 'whisper' AND is_deleted = 0",
            [(int) ($actor['id'] ?? 0), $roomId, $userId]
        );
        self::jsonSuccess(['cleared' => true]);
    }

    /**
     * Единая ошибка API.
     * Last updated: 2026-04-17.
     */
    private static function jsonError(string $msg, int $code = 400): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Единый успешный ответ API.
     * Last updated: 2026-04-17.
     *
     * @param array<string, mixed> $data
     */
    private static function jsonSuccess(array $data = []): never
    {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
