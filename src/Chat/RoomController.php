<?php
declare(strict_types=1);

namespace Chat\Chat;

use Chat\DB\Connection;
use Chat\Http\JsonResponse;
use Chat\Security\CSRF;
use Chat\Support\Timestamp;
use Chat\Admin\Access;
use Chat\Chat\RoomDeletionService;

class RoomController
{
    public static function list(int $userId, string $globalRole): void
    {
        $db = Connection::getInstance();
        $rooms = $db->fetchAll(
            "SELECT r.id, r.name, r.description, r.type, r.is_closed,
                    COALESCE(mc.member_count, 0) AS member_count,
                    rm2.room_role AS my_role
             FROM rooms r
             LEFT JOIN room_members rm2 ON rm2.room_id = r.id AND rm2.user_id = ?
             LEFT JOIN (
                 SELECT room_id, COUNT(*) AS member_count
                 FROM room_members
                 WHERE room_role != 'banned'
                 GROUP BY room_id
             ) mc ON mc.room_id = r.id
             WHERE r.type = 'public'
               AND r.is_closed = 0
               AND (rm2.room_role IS NULL OR rm2.room_role != 'banned')
             ORDER BY r.id",
            [$userId]
        );

        JsonResponse::success(['rooms' => $rooms]);
    }

    public static function create(int $userId, array $actor): void
    {
        if (!CSRF::verifyRequest()) {
            JsonResponse::error('CSRF.', 403);
        }

        if (!$actor['can_create_room'] && !in_array($actor['global_role'], ['platform_owner', 'admin'], true)) {
            JsonResponse::error('Нет прав на создание комнат.', 403);
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            JsonResponse::error('Название должно быть от 2 до 100 символов.');
        }

        $isPrivileged = in_array($actor['global_role'], ['platform_owner', 'admin'], true);
        $categoryRaw  = trim((string) ($_POST['room_category'] ?? 'user'));
        $category = ($isPrivileged && in_array($categoryRaw, ['permanent', 'user', 'commercial'], true))
            ? $categoryRaw : 'user';

        $db = Connection::getInstance();
        $db->execute(
            "INSERT INTO rooms (name, description, type, room_category, owner_id) VALUES (?, ?, 'public', ?, ?)",
            [$name, $description !== '' ? $description : null, $category, $userId]
        );
        $roomId = (int) $db->lastInsertId();

        $db->execute(
            'INSERT INTO room_members (room_id, user_id, room_role) VALUES (?, ?, ?)',
            [$roomId, $userId, 'owner']
        );

        JsonResponse::success(['room_id' => $roomId, 'name' => $name]);
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

        // I-1: self-action guard — actor cannot target themselves for moderation actions.
        $targetId = (int) ($data['target_user_id'] ?? 0);
        if (in_array($action, ['kick', 'ban', 'mute', 'unmute', 'set_role'], true) && $targetId === $actorId) {
            return ['error' => 'Нельзя применить это действие к самому себе.'];
        }

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
                $roomMeta = $db->fetchOne('SELECT room_category FROM rooms WHERE id = ?', [$roomId]);
                if (($roomMeta['room_category'] ?? 'user') === 'permanent') {
                    return ['error' => 'Постоянные комнаты нельзя удалить.'];
                }
                try {
                    RoomDeletionService::deleteWithDependencies($db, $roomId);
                } catch (\Throwable) {
                    return ['error' => 'Не удалось удалить комнату.'];
                }
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
            case 'mute':
                return self::mute($roomId, (int) ($data['target_user_id'] ?? 0), $actorId, $actor, $permission, $db, $data);

            case 'unmute':
                return self::unmute($roomId, (int) ($data['target_user_id'] ?? 0), $actorId, $actor, $permission, $db);

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

        // Derive actor's room role and global role for explicit policy checks.
        // $permission['role'] is present only for room-local roles (owner/local_admin/local_moderator/member).
        // For global roles (platform_owner/admin/moderator) it is absent.
        $actorRoomRole   = $permission['role'] ?? null;
        $actorGlobalRole = $actor['global_role'] ?? 'user';

        // Policy: local_admin can be assigned only by room owner, global admin, or platform_owner.
        if ($role === 'local_admin') {
            $canAssign = in_array($actorGlobalRole, ['platform_owner', 'admin'], true)
                      || $actorRoomRole === 'owner';
            if (!$canAssign) {
                return ['error' => 'Только владелец комнаты или глобальный администратор может назначить local_admin.'];
            }
        }

        // Policy: local_moderator can be assigned only by room owner, local_admin, global admin, or platform_owner.
        // local_moderator itself is NOT in this list.
        if ($role === 'local_moderator') {
            $canAssign = in_array($actorGlobalRole, ['platform_owner', 'admin'], true)
                      || in_array($actorRoomRole, ['owner', 'local_admin'], true);
            if (!$canAssign) {
                return ['error' => 'Назначить local_moderator может только владелец комнаты, local_admin или глобальный администратор.'];
            }
        }

        $target = $db->fetchOne(
            'SELECT rm.room_role, u.username
             FROM room_members rm
             JOIN users u ON u.id = rm.user_id
             WHERE rm.room_id = ? AND rm.user_id = ?',
            [$roomId, $targetId]
        );
        if (!$target) {
            return ['error' => 'Пользователь не состоит в комнате.'];
        }
        if ($target['room_role'] === 'owner') {
            return ['error' => 'Нельзя изменить роль владельца.'];
        }

        // Policy: demotion to member requires appropriate rank depending on the current role being removed.
        if ($role === 'member') {
            $currentTargetRole = $target['room_role'];
            if ($currentTargetRole === 'local_admin') {
                // Removing local_admin requires: room owner, global admin, or platform_owner.
                $canDemote = in_array($actorGlobalRole, ['platform_owner', 'admin'], true)
                          || $actorRoomRole === 'owner';
                if (!$canDemote) {
                    return ['error' => 'Снять local_admin может только владелец комнаты или глобальный администратор.'];
                }
            }
            if ($currentTargetRole === 'local_moderator') {
                // Removing local_moderator requires: room owner, local_admin, global admin, or platform_owner.
                // local_moderator itself is NOT in this list.
                $canDemote = in_array($actorGlobalRole, ['platform_owner', 'admin'], true)
                          || in_array($actorRoomRole, ['owner', 'local_admin'], true);
                if (!$canDemote) {
                    return ['error' => 'Снять local_moderator может только владелец комнаты, local_admin или глобальный администратор.'];
                }
            }
        }

        if ($target['room_role'] === $role) {
            return ['updated' => false, 'target_user_id' => $targetId, 'role' => $role,
                    'old_role' => $role, 'target_username' => (string) $target['username'],
                    'no_change' => true];
        }

        $db->execute(
            'UPDATE room_members SET room_role = ? WHERE room_id = ? AND user_id = ?',
            [$role, $roomId, $targetId]
        );

        return ['updated' => true, 'target_user_id' => $targetId, 'role' => $role,
                'old_role' => (string) $target['room_role'], 'target_username' => (string) $target['username']];
    }

    private static function kick(int $roomId, int $targetId, int $actorId, array $actor, array $permission, Connection $db): array
    {
        if ($permission['level'] < 2 && !in_array($actor['global_role'], ['platform_owner', 'admin', 'moderator'], true)) {
            return ['error' => 'Нет прав.'];
        }

        $target = $db->fetchOne(
            'SELECT rm.room_role, u.username, u.global_role
             FROM room_members rm JOIN users u ON u.id = rm.user_id
             WHERE rm.room_id = ? AND rm.user_id = ?',
            [$roomId, $targetId]
        );
        // I-3: room owner and platform_owner cannot be kicked.
        if (!$target || $target['room_role'] === 'owner' || $target['global_role'] === 'platform_owner') {
            return ['error' => 'Нельзя выгнать этого пользователя.'];
        }

        $db->execute('DELETE FROM room_members WHERE room_id = ? AND user_id = ?', [$roomId, $targetId]);
        return ['kicked' => true, 'target_user_id' => $targetId, 'room_id' => $roomId, 'target_username' => $target['username']];
    }

    private static function ban(int $roomId, int $targetId, int $actorId, array $actor, array $permission, Connection $db): array
    {
        if ($permission['level'] < 2 && !in_array($actor['global_role'], ['platform_owner', 'admin', 'moderator'], true)) {
            return ['error' => 'Нет прав.'];
        }

        $target = $db->fetchOne(
            'SELECT rm.room_role, u.username, u.global_role
             FROM room_members rm JOIN users u ON u.id = rm.user_id
             WHERE rm.room_id = ? AND rm.user_id = ?',
            [$roomId, $targetId]
        );
        // I-3: room owner and platform_owner cannot be banned.
        if (!$target || $target['room_role'] === 'owner' || $target['global_role'] === 'platform_owner') {
            return ['error' => 'Нельзя забанить этого пользователя.'];
        }

        $db->execute(
            'UPDATE room_members SET room_role = ?, banned_at = NOW(), banned_by = ? WHERE room_id = ? AND user_id = ?',
            ['banned', $actorId, $roomId, $targetId]
        );

        return ['banned' => true, 'target_user_id' => $targetId, 'room_id' => $roomId, 'target_username' => $target['username']];
    }

    private static function mute(int $roomId, int $targetId, int $actorId, array $actor, array $permission, Connection $db, array $data): array
    {
        if ($permission['level'] < 2 && !in_array($actor['global_role'], ['platform_owner', 'admin', 'moderator'], true)) {
            return ['error' => 'Нет прав.'];
        }

        $target = $db->fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [$roomId, $targetId]
        );
        if (!$target || $target['room_role'] === 'owner' || $target['room_role'] === 'banned') {
            return ['error' => 'Нельзя выдать кляп этому пользователю.'];
        }

        $minutes = isset($data['minutes']) ? (int) $data['minutes'] : 0;
        if ($minutes <= 0) {
            return ['error' => 'Выберите срок кляпа.'];
        }
        $minutes = min(1440, $minutes);

        $reason = mb_substr(trim((string) ($data['reason'] ?? '')), 0, 255);
        if ($reason === '') {
            return ['error' => 'Укажите причину кляпа.'];
        }

        $activeMute = $db->fetchOne(
            'SELECT muted_until FROM room_members WHERE room_id = ? AND user_id = ? AND muted_until > NOW()',
            [$roomId, $targetId]
        );
        if ($activeMute) {
            return ['error' => 'Пользователь уже в кляпе до ' . date('H:i', strtotime((string) $activeMute['muted_until'])) . '. Сначала снимите кляп.'];
        }

        $db->execute(
            'UPDATE room_members
             SET muted_until = DATE_ADD(NOW(), INTERVAL ? MINUTE), mute_reason = ?
             WHERE room_id = ? AND user_id = ?',
            [$minutes, $reason, $roomId, $targetId]
        );

        $row = $db->fetchOne(
            'SELECT muted_until, mute_reason FROM room_members WHERE room_id = ? AND user_id = ?',
            [$roomId, $targetId]
        );

        return [
            'muted' => true,
            'target_user_id' => $targetId,
            'room_id' => $roomId,
            'muted_until' => Timestamp::isoUtc(isset($row['muted_until']) ? (string) $row['muted_until'] : null),
            'reason' => $row['mute_reason'] ?? null,
        ];
    }

    private static function unmute(int $roomId, int $targetId, int $actorId, array $actor, array $permission, Connection $db): array
    {
        if ($permission['level'] < 2 && !in_array($actor['global_role'], ['platform_owner', 'admin', 'moderator'], true)) {
            return ['error' => 'Нет прав.'];
        }

        $target = $db->fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [$roomId, $targetId]
        );
        if (!$target) {
            return ['error' => 'Пользователь не состоит в комнате.'];
        }

        $db->execute(
            'UPDATE room_members SET muted_until = NULL, mute_reason = NULL WHERE room_id = ? AND user_id = ?',
            [$roomId, $targetId]
        );

        return ['unmuted' => true, 'target_user_id' => $targetId, 'room_id' => $roomId];
    }

    private static function resolvePermission(int $roomId, int $userId, array $actor): ?array
    {
        $level = Access::resolveLevel($roomId, $userId, $actor);
        if ($level < 0) {
            return null;
        }
        if ($level >= 4) {
            // global roles (platform_owner=6, admin=5, moderator=4) — no room_role needed
            return ['level' => $level];
        }
        // room-local role required by setRoomRole() policy checks
        $role = Connection::getInstance()->fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [$roomId, $userId]
        )['room_role'] ?? null;
        return ['level' => $level, 'role' => $role];
    }

    public static function numera(int $userId): void
    {
        $db = Connection::getInstance();
        $rooms = $db->fetchAll(
            "SELECT r.id, r.name, r.created_at,
                    COALESCE(mc.member_count, 0) AS member_count
             FROM rooms r
             JOIN room_members rm ON rm.room_id = r.id AND rm.user_id = ?
             LEFT JOIN (
                 SELECT room_id, COUNT(*) AS member_count
                 FROM room_members
                 WHERE room_role != 'banned'
                 GROUP BY room_id
             ) mc ON mc.room_id = r.id
             WHERE r.type = 'numer' AND r.is_closed = 0",
            [$userId]
        );
        $rooms = Timestamp::normalizeRows($rooms, ['created_at']);

        JsonResponse::success(['numera' => $rooms]);
    }

    public static function members(int $roomId, int $userId): never
    {
        $db = Connection::getInstance();
        $member = $db->fetchOne(
            "SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ? AND room_role != 'banned'",
            [$roomId, $userId]
        );
        if (!$member) {
            http_response_code(403);
            echo json_encode(['success' => false]);
            exit;
        }
        $members = $db->fetchAll(
            "SELECT u.id, u.username, u.nickname, u.nick_color, u.avatar_url, rm.room_role
             FROM room_members rm
             JOIN users u ON u.id = rm.user_id
             WHERE rm.room_id = ? AND rm.room_role != 'banned'
             ORDER BY rm.joined_at ASC",
            [$roomId]
        );
        JsonResponse::success(['members' => $members]);
    }

}
