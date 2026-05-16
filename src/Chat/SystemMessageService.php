<?php
declare(strict_types=1);

namespace Chat\Chat;

use Chat\DB\Connection;
use Chat\Support\Timestamp;
use Chat\WebSocket\ConnectionManager;

class SystemMessageService
{
    private const IMPORTANCES = ['normal', 'optional', 'important'];

    private const GLOBAL_ROLE_LAYERS = [
        'moderator' => 'global_moderators',
        'admin' => 'global_admins',
        'platform_owner' => 'platform_owners',
    ];

    private const ROOM_ROLE_LAYERS = [
        'local_moderator' => 'local_moderators',
        'local_admin' => 'local_admins',
        'owner' => 'room_owners',
    ];

    public static function emitRoomLifecycle(
        ConnectionManager $cm,
        int $roomId,
        int $actorId,
        string $content,
        string $scope
    ): void {
        $db         = Connection::getInstance();
        $importance = self::normalizeImportance('optional');

        $db->execute(
            'INSERT INTO messages (room_id, user_id, content, content_hmac, type, system_importance, system_scope)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$roomId, $actorId, $content, '', 'system', $importance, $scope]
        );

        $msgId     = (int) $db->lastInsertId();
        $createdAt = $db->fetchOne('SELECT created_at FROM messages WHERE id = ?', [$msgId])['created_at'] ?? null;

        $cm->sendToRoom($roomId, [
            'event'   => 'system_message',
            'room_id' => $roomId,
            'message' => self::buildPayload([
                'id'                => $msgId,
                'room_id'           => $roomId,
                'content'           => $content,
                'created_at'        => $createdAt,
                'system_importance' => $importance,
                'system_scope'      => $scope,
            ]),
        ]);
    }

    public static function emitModerationCall(ConnectionManager $cm, int $roomId, array $actor): void
    {
        $db = Connection::getInstance();
        $room = $db->fetchOne('SELECT name FROM rooms WHERE id = ?', [$roomId]);
        $targets = $db->fetchAll(
            "SELECT id, global_role FROM users
             WHERE global_role IN ('platform_owner', 'admin', 'moderator') AND is_banned = 0"
        );

        $message = self::buildPayload([
            'room_id' => $roomId,
            'content' => sprintf(
                'Вызов модерации: %s позвал(а) в комнату "%s".',
                (string) ($actor['username'] ?? 'Пользователь'),
                (string) ($room['name'] ?? ('#' . $roomId))
            ),
            'created_at' => Timestamp::nowIsoUtc(),
            'system_importance' => 'important',
            'system_scope' => 'moderation_call',
        ]);

        foreach ($targets as $target) {
            $targetId = (int) ($target['id'] ?? 0);
            if ($targetId === (int) ($actor['id'] ?? 0)) {
                continue;
            }
            $cm->sendToUser($targetId, [
                'event'   => 'system_message',
                'message' => $message,
            ]);
        }
    }

    // Placeholder: все persisted system messages первой волны имеют visibility=all.
    // Реализовать через canUserSeeAnyLayer() при появлении первого persisted non-all visibility сообщения.
    public static function canUserSeeSystemMessage(array $user, array $message): bool
    {
        return true;
    }

    public static function canUserSeeAnyLayer(array $user, int $roomId, array $layers): bool
    {
        if (in_array('all', $layers, true)) {
            return true;
        }

        $globalRole = (string) ($user['global_role'] ?? 'user');
        $globalLayer = self::GLOBAL_ROLE_LAYERS[$globalRole] ?? null;
        if ($globalLayer !== null && in_array($globalLayer, $layers, true)) {
            return true;
        }

        $roomRole = self::roomRole($roomId, (int) ($user['id'] ?? 0));
        $roomLayer = self::ROOM_ROLE_LAYERS[$roomRole] ?? null;
        if ($roomLayer !== null && in_array($roomLayer, $layers, true)) {
            return true;
        }

        return in_array('users', $layers, true)
            && $globalRole === 'user'
            && ($roomRole === null || $roomRole === 'member');
    }

    public static function buildPayload(array $message): array
    {
        $importance = self::normalizeImportance((string) ($message['system_importance'] ?? 'optional'));
        $scope = (string) ($message['system_scope'] ?? 'legacy');

        return [
            'id' => isset($message['id']) ? (int) $message['id'] : null,
            'room_id' => (int) ($message['room_id'] ?? 0),
            'content' => (string) ($message['content'] ?? ''),
            'type' => 'system',
            'created_at' => Timestamp::isoUtc(isset($message['created_at']) ? (string) $message['created_at'] : null),
            'system_importance' => $importance,
            'system_scope' => $scope,
            'scope' => $scope,
        ];
    }

    public static function normalizeImportance(string $importance): string
    {
        return in_array($importance, self::IMPORTANCES, true) ? $importance : 'optional';
    }

    private static function roomRole(int $roomId, int $userId): ?string
    {
        if ($roomId <= 0 || $userId <= 0) {
            return null;
        }

        $row = Connection::getInstance()->fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [$roomId, $userId]
        );

        return isset($row['room_role']) ? (string) $row['room_role'] : null;
    }
}
