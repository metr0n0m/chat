<?php
declare(strict_types=1);

namespace Chat\WebSocket;

use Chat\DB\Connection;

/**
 * Доставка события только стаффу комнаты.
 * Единственное определение «стаффа» для WS-доставки:
 * глобальные platform_owner/admin/moderator + комнатные owner/local_admin/local_moderator.
 * Используется EventRouter (живые действия) и OutboxDispatcher (мост S2).
 */
final class StaffNotifier
{
    private const ROOM_STAFF   = ['owner', 'local_admin', 'local_moderator'];
    private const GLOBAL_STAFF = ['platform_owner', 'admin', 'moderator'];

    public static function sendToRoomStaff(
        ConnectionManager $cm,
        Connection $db,
        int $roomId,
        ?int $excludeUserId,
        array $event
    ): void {
        $userIds = $cm->getRoomUserIds($roomId);
        if (!$userIds) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $rows = $db->fetchAll(
            'SELECT rm.user_id, rm.room_role, u.global_role
             FROM room_members rm
             JOIN users u ON u.id = rm.user_id
             WHERE rm.room_id = ? AND rm.user_id IN (' . $placeholders . ')',
            array_merge([$roomId], $userIds)
        );
        foreach ($rows as $row) {
            $uid = (int) $row['user_id'];
            if ($excludeUserId !== null && $uid === $excludeUserId) {
                continue;
            }
            $isStaff = in_array($row['global_role'], self::GLOBAL_STAFF, true)
                    || in_array($row['room_role'], self::ROOM_STAFF, true);
            if ($isStaff) {
                $cm->sendToUser($uid, $event);
            }
        }
    }
}
