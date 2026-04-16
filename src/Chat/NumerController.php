<?php
declare(strict_types=1);

namespace Chat\Chat;

use Chat\DB\Connection;

class NumerController
{
    public static function invite(int $fromId, array $from, int $toId): array
    {
        if ($fromId === $toId) {
            return ['error' => 'Нельзя пригласить себя.'];
        }

        $db = Connection::getInstance();

        $pendingCount = (int) $db->fetchOne(
            "SELECT COUNT(*) AS c FROM invitations WHERE from_user_id = ? AND status = 'pending'",
            [$fromId]
        )['c'];
        if ($pendingCount >= INVITE_PENDING_MAX) {
            return ['error' => 'Слишком много ожидающих приглашений.'];
        }

        $toUser = $db->fetchOne(
            'SELECT id, username, is_banned FROM users WHERE id = ?',
            [$toId]
        );
        if (!$toUser || $toUser['is_banned']) {
            return ['error' => 'Пользователь не найден.'];
        }

        // Find or create numer where from is owner and it's open and has < 4 members
        $numer = $db->fetchOne(
            "SELECT r.id FROM rooms r
             JOIN room_members rm ON rm.room_id = r.id AND rm.user_id = ? AND rm.room_role = 'owner'
             WHERE r.type = 'numer' AND r.is_closed = 0
             HAVING (SELECT COUNT(*) FROM room_members WHERE room_id = r.id AND room_role != 'banned') < 4
             LIMIT 1",
            [$fromId]
        );

        if (!$numer) {
            $db->execute(
                "INSERT INTO rooms (name, type, owner_id, max_members) VALUES (?, 'numer', ?, 4)",
                ['Нумер #' . $fromId, $fromId]
            );
            $numerId = (int) $db->lastInsertId();
            $db->execute(
                'INSERT INTO room_members (room_id, user_id, room_role) VALUES (?, ?, ?)',
                [$numerId, $fromId, 'owner']
            );
        } else {
            $numerId = (int) $numer['id'];
        }

        $expiresAt = date('Y-m-d H:i:s', time() + 30);
        $db->execute(
            'INSERT INTO invitations (room_id, from_user_id, to_user_id, expires_at) VALUES (?, ?, ?, ?)',
            [$numerId, $fromId, $toId, $expiresAt]
        );
        $invId = (int) $db->lastInsertId();

        return [
            'invitation_id' => $invId,
            'room_id'       => $numerId,
            'from'          => ['id' => $fromId, 'username' => $from['username'], 'avatar_url' => $from['avatar_url'] ?? null],
            'to_user_id'    => $toId,
            'to_username'   => $toUser['username'],
            'expires_at'    => $expiresAt,
        ];
    }

    public static function respond(int $invId, int $userId, string $response): array
    {
        $db  = Connection::getInstance();
        $inv = $db->fetchOne(
            "SELECT * FROM invitations WHERE id = ? AND to_user_id = ? AND status = 'pending' AND expires_at > NOW()",
            [$invId, $userId]
        );

        if (!$inv) {
            return ['error' => 'Приглашение не найдено или истекло.'];
        }

        $status = $response === 'accept' ? 'accepted' : 'declined';
        $db->execute(
            'UPDATE invitations SET status = ?, responded_at = NOW() WHERE id = ?',
            [$status, $invId]
        );

        if ($status !== 'accepted') {
            return ['declined' => true, 'invitation_id' => $invId];
        }

        $roomId = (int) $inv['room_id'];
        $count  = (int) $db->fetchOne(
            "SELECT COUNT(*) AS c FROM room_members WHERE room_id = ? AND room_role != 'banned'",
            [$roomId]
        )['c'];

        if ($count >= 4) {
            $db->execute("UPDATE invitations SET status = 'expired' WHERE id = ?", [$invId]);
            return ['error' => 'Нумер заполнен.'];
        }

        $db->execute(
            'INSERT IGNORE INTO room_members (room_id, user_id, room_role) VALUES (?, ?, ?)',
            [$roomId, $userId, 'member']
        );

        $members = $db->fetchAll(
            'SELECT u.id, u.username, u.nick_color, u.avatar_url
             FROM room_members rm
             JOIN users u ON u.id = rm.user_id
             WHERE rm.room_id = ?',
            [$roomId]
        );

        return [
            'accepted'      => true,
            'invitation_id' => $invId,
            'room_id'       => $roomId,
            'members'       => $members,
        ];
    }

    public static function leave(int $roomId, int $userId): array
    {
        $db   = Connection::getInstance();
        $room = $db->fetchOne("SELECT id, type FROM rooms WHERE id = ? AND type = 'numer'", [$roomId]);
        if (!$room) {
            return ['error' => 'Нумер не найден.'];
        }

        $db->execute('DELETE FROM room_members WHERE room_id = ? AND user_id = ?', [$roomId, $userId]);

        $remaining = (int) $db->fetchOne(
            "SELECT COUNT(*) AS c FROM room_members WHERE room_id = ? AND room_role != 'banned'",
            [$roomId]
        )['c'];

        if ($remaining === 0) {
            $db->execute(
                'UPDATE rooms SET is_closed = 1, closed_at = NOW() WHERE id = ?',
                [$roomId]
            );
            return ['left' => true, 'room_id' => $roomId, 'destroyed' => true];
        }

        return ['left' => true, 'room_id' => $roomId, 'remaining' => $remaining];
    }

    public static function expireInvitations(): void
    {
        Connection::getInstance()->execute(
            "UPDATE invitations SET status = 'expired' WHERE status = 'pending' AND expires_at <= NOW()"
        );
    }
}
