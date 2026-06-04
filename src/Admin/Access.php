<?php
declare(strict_types=1);

namespace Chat\Admin;

use Chat\DB\Connection;

class Access
{
    public static function isOwner(array $user): bool
    {
        return ($user['global_role'] ?? '') === 'platform_owner';
    }

    public static function isGlobalAdmin(array $user): bool
    {
        return in_array($user['global_role'] ?? '', ['platform_owner', 'admin'], true);
    }

    public static function isGlobalModerator(array $user): bool
    {
        return ($user['global_role'] ?? '') === 'moderator';
    }

    public static function isGlobalStaff(array $user): bool
    {
        return in_array($user['global_role'] ?? '', ['platform_owner', 'admin', 'moderator'], true);
    }

    public static function canOpenAdminPanel(array $user): bool
    {
        return self::isGlobalAdmin($user);
    }

    public static function canOpenOwnerPanel(array $user): bool
    {
        return self::isOwner($user);
    }

    public static function canAccessRoom(array $user, int $roomId): bool
    {
        $db   = Connection::getInstance();
        $room = $db->fetchOne('SELECT type, is_closed FROM rooms WHERE id = ?', [$roomId]);

        if (!$room || (int) $room['is_closed'] === 1) {
            return false;
        }

        if (self::isGlobalAdmin($user)) {
            return true;
        }

        $userId = (int) $user['id'];

        if ($room['type'] === 'public') {
            $member = $db->fetchOne(
                'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
                [$roomId, $userId]
            );
            return !$member || $member['room_role'] !== 'banned';
        }

        $member = $db->fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [$roomId, $userId]
        );

        return $member !== null && $member['room_role'] !== 'banned';
    }

    public static function canModerateRoom(array $user, int $roomId): bool
    {
        return self::resolveLevel($roomId, (int) $user['id'], $user) >= 1;
    }

    public static function canManageRoom(array $user, int $roomId): bool
    {
        return self::resolveLevel($roomId, (int) $user['id'], $user) >= 3;
    }

    public static function canAssignRoomRole(array $user, int $roomId, string $targetRole): bool
    {
        $level = self::resolveLevel($roomId, (int) $user['id'], $user);
        if ($targetRole === 'local_admin') {
            return $level >= 3 || self::isGlobalAdmin($user);
        }
        return $level >= 1;
    }

    public static function canDeleteMessage(array $user, array $message): bool
    {
        if (self::isGlobalAdmin($user)) {
            return true;
        }

        $db = Connection::getInstance();

        if (self::isGlobalModerator($user)) {
            $room = $db->fetchOne('SELECT type FROM rooms WHERE id = ?', [(int) $message['room_id']]);
            return $room && $room['type'] === 'public';
        }

        $roomRole = $db->fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [(int) $message['room_id'], (int) $user['id']]
        )['room_role'] ?? null;

        return in_array($roomRole, ['owner', 'local_admin', 'local_moderator'], true);
    }

    public static function requireOwnerOnly(?array $user): array
    {
        if (!$user || !self::isOwner($user)) {
            self::denyNotFound();
        }
        return $user;
    }

    public static function requireOwnerPrivateArchive(?array $user): array
    {
        if (!$user || !self::isOwner($user)) {
            self::denyNotFound();
        }
        return $user;
    }

    public static function denyForbidden(): never
    {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'Доступ запрещён.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function denyNotFound(): never
    {
        http_response_code(404);
        exit;
    }

    public static function resolveLevel(int $roomId, int $userId, array $actor): int
    {
        $globalRole = $actor['global_role'] ?? 'user';
        if ($globalRole === 'platform_owner') return 6;
        if ($globalRole === 'admin')          return 5;
        if ($globalRole === 'moderator')      return 4;

        $db   = Connection::getInstance();
        $role = $db->fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [$roomId, $userId]
        )['room_role'] ?? null;

        return match ($role) {
            'owner'           => 3,
            'local_admin'     => 2,
            'local_moderator' => 1,
            'member'          => 0,
            default           => -1,
        };
    }
}
