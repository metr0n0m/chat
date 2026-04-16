<?php
declare(strict_types=1);

namespace Chat\Chat;

use Chat\DB\Connection;
use Chat\Security\CSRF;

class RoomController
{
    public static function list(int $userId, string $globalRole): void
    {
        $db = Connection::getInstance();
        $rooms = $db->fetchAll(
            "SELECT r.id, r.name, r.description, r.type, r.is_closed,
                    (SELECT COUNT(*) FROM room_members rm WHERE rm.room_id = r.id AND rm.room_role != 'banned') AS member_count,
                    rm2.room_role AS my_role
             FROM rooms r
             LEFT JOIN room_members rm2 ON rm2.room_id = r.id AND rm2.user_id = ?
             WHERE r.type = 'public'
               AND r.is_closed = 0
               AND (rm2.room_role IS NULL OR rm2.room_role != 'banned')
             ORDER BY r.id",
            [$userId]
        );

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'rooms' => $rooms], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function create(int $userId, array $actor): void
    {
        if (!CSRF::verifyRequest()) {
            self::jsonError('CSRF.', 403);
        }

        if (!$actor['can_create_room'] && !in_array($actor['global_role'], ['platform_owner', 'admin'], true)) {
            self::jsonError('Нет прав на создание комнат.', 403);
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            self::jsonError('Название должно быть от 2 до 100 символов.');
        }

        $db = Connection::getInstance();
        $db->execute(
            "INSERT INTO rooms (name, description, type, owner_id) VALUES (?, ?, 'public', ?)",
            [$name, $description !== '' ? $description : null, $userId]
        );
        $roomId = (int) $db->lastInsertId();

        $db->execute(
            'INSERT INTO room_members (room_id, user_id, room_role) VALUES (?, ?, ?)',
            [$roomId, $userId, 'owner']
        );

        self::jsonSuccess(['room_id' => $roomId, 'name' => $name]);
    }

    public static function join(int $roomId, int $userId): array
    {
        $db = Connection::getInstance();
        $room = $db->fetchOne(
            "SELECT id, type, is_closed, max_members FROM rooms WHERE id = ? AND type = 'public' AND is_closed = 0",
            [$roomId]
        );

        if (!$room) {
            return ['error' => 'Комната не найдена.'];
        }

        $existing = $db->fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [$roomId, $userId]
        );
        if ($existing) {
            if ($existing['room_role'] === 'banned') {
                return ['error' => 'Вы забанены в этой комнате.'];
            }
            return ['already_member' => true];
        }

        if (!empty($room['max_members'])) {
            $count = (int) $db->fetchOne(
                "SELECT COUNT(*) AS c FROM room_members WHERE room_id = ? AND room_role != 'banned'",
                [$roomId]
            )['c'];
            if ($count >= (int) $room['max_members']) {
                return ['error' => 'Комната заполнена.'];
            }
        }

        $db->execute('INSERT INTO room_members (room_id, user_id, room_role) VALUES (?, ?, ?)', [$roomId, $userId, 'member']);
        return ['joined' => true, 'room_id' => $roomId];
    }

    public static function manage(int $roomId, int $actorId, array $actor, array $data): array
    {
        $permission = self::resolvePermission($roomId, $actorId, $actor);
        if (!$permission) {
            return ['error' => 'Нет прав.'];
        }

        $db = Connection::getInstance();
        $action = $data['action'] ?? '';

        switch ($action) {
            case 'rename':
                if ($permission['level'] < 3) {
                    return ['error' => 'Недостаточно прав.'];
                }
                $name = trim((string) ($data['name'] ?? ''));
                if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
                    return ['error' => 'Некорректное название комнаты.'];
                }
                $db->execute('UPDATE rooms SET name = ? WHERE id = ?', [$name, $roomId]);
                return ['updated' => true, 'name' => $name];

            case 'delete':
                if ($permission['level'] < 3 && !in_array($actor['global_role'], ['platform_owner', 'admin'], true)) {
                    return ['error' => 'Недостаточно прав.'];
                }
                $db->execute('DELETE FROM rooms WHERE id = ?', [$roomId]);
                return ['deleted' => true];

            case 'set_role':
                return self::setRoomRole(
                    $roomId,
                    (int) ($data['target_user_id'] ?? 0),
                    (string) ($data['role'] ?? ''),
                    $actor,
                    $permission,
                    $db
                );

            case 'kick':
                return self::kick($roomId, (int) ($data['target_user_id'] ?? 0), $actorId, $actor, $permission, $db);

            case 'ban':
                return self::ban($roomId, (int) ($data['target_user_id'] ?? 0), $actorId, $actor, $permission, $db);

            default:
                return ['error' => 'Неизвестное действие.'];
        }
    }

    private static function setRoomRole(int $roomId, int $targetId, string $role, array $actor, array $permission, Connection $db): array
    {
        $allowed = ['local_moderator', 'local_admin', 'member'];
        if (!in_array($role, $allowed, true)) {
            return ['error' => 'Недопустимая роль.'];
        }
        if ($role === 'local_admin' && $permission['level'] < 3 && !in_array($actor['global_role'], ['platform_owner', 'admin'], true)) {
            return ['error' => 'Только владелец комнаты или глобальный администратор может назначить local_admin.'];
        }

        $target = $db->fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [$roomId, $targetId]
        );
        if (!$target) {
            return ['error' => 'Пользователь не состоит в комнате.'];
        }
        if ($target['room_role'] === 'owner') {
            return ['error' => 'Нельзя изменить роль владельца.'];
        }

        $db->execute(
            'UPDATE room_members SET room_role = ? WHERE room_id = ? AND user_id = ?',
            [$role, $roomId, $targetId]
        );

        return ['updated' => true, 'target_user_id' => $targetId, 'role' => $role];
    }

    private static function kick(int $roomId, int $targetId, int $actorId, array $actor, array $permission, Connection $db): array
    {
        if ($permission['level'] < 2 && !in_array($actor['global_role'], ['platform_owner', 'admin', 'moderator'], true)) {
            return ['error' => 'Нет прав.'];
        }

        $target = $db->fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [$roomId, $targetId]
        );
        if (!$target || $target['room_role'] === 'owner') {
            return ['error' => 'Нельзя выгнать этого пользователя.'];
        }

        $db->execute('DELETE FROM room_members WHERE room_id = ? AND user_id = ?', [$roomId, $targetId]);
        return ['kicked' => true, 'target_user_id' => $targetId, 'room_id' => $roomId];
    }

    private static function ban(int $roomId, int $targetId, int $actorId, array $actor, array $permission, Connection $db): array
    {
        if ($permission['level'] < 2 && !in_array($actor['global_role'], ['platform_owner', 'admin', 'moderator'], true)) {
            return ['error' => 'Нет прав.'];
        }

        $target = $db->fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [$roomId, $targetId]
        );
        if (!$target || $target['room_role'] === 'owner') {
            return ['error' => 'Нельзя забанить этого пользователя.'];
        }

        $db->execute(
            'UPDATE room_members SET room_role = ?, banned_at = NOW(), banned_by = ? WHERE room_id = ? AND user_id = ?',
            ['banned', $actorId, $roomId, $targetId]
        );

        return ['banned' => true, 'target_user_id' => $targetId, 'room_id' => $roomId];
    }

    private static function resolvePermission(int $roomId, int $userId, array $actor): ?array
    {
        if (($actor['global_role'] ?? 'user') === 'platform_owner') {
            return ['level' => 6];
        }
        if (($actor['global_role'] ?? 'user') === 'admin') {
            return ['level' => 5];
        }
        if (($actor['global_role'] ?? 'user') === 'moderator') {
            return ['level' => 4];
        }

        $db = Connection::getInstance();
        $role = $db->fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [$roomId, $userId]
        )['room_role'] ?? null;

        return match ($role) {
            'owner' => ['level' => 3, 'role' => $role],
            'local_admin' => ['level' => 2, 'role' => $role],
            'local_moderator' => ['level' => 1, 'role' => $role],
            'member' => ['level' => 0, 'role' => $role],
            default => null,
        };
    }

    public static function numera(int $userId): void
    {
        $db = Connection::getInstance();
        $rooms = $db->fetchAll(
            "SELECT r.id, r.name, r.created_at,
                    (SELECT COUNT(*) FROM room_members rm WHERE rm.room_id = r.id AND rm.room_role != 'banned') AS member_count
             FROM rooms r
             JOIN room_members rm ON rm.room_id = r.id AND rm.user_id = ?
             WHERE r.type = 'numer' AND r.is_closed = 0",
            [$userId]
        );

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'numera' => $rooms], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function jsonError(string $message, int $code = 400): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function jsonSuccess(array $data = []): never
    {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
