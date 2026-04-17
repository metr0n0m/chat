<?php
declare(strict_types=1);

namespace Chat\Chat;

use Chat\DB\Connection;
use Chat\Support\Lang;

/**
 * Управление приватными сессиями (нумера).
 * Last updated: 2026-04-17.
 */
class NumerController
{
    /**
     * Создает нумер (в т.ч. self-create) или отправляет приглашение.
     * Last updated: 2026-04-17.
     */
    public static function invite(int $fromId, array $from, int $toId): array
    {
        $db = Connection::getInstance();
        $numerId = self::findOrCreateOwnedNumer($db, $fromId);

        // Self-create: пользователь создает/открывает нумер для ожидания приглашений.
        if ($fromId === $toId) {
            return [
                'self_created' => true,
                'room_id' => $numerId,
                'members' => self::roomMembers($db, $numerId),
            ];
        }

        $pendingCount = (int) $db->fetchOne(
            "SELECT COUNT(*) AS c FROM invitations WHERE from_user_id = ? AND status = 'pending'",
            [$fromId]
        )['c'];
        if ($pendingCount >= INVITE_PENDING_MAX) {
            return ['error' => Lang::get('errors.numer.too_many_pending')];
        }

        $toUser = $db->fetchOne(
            'SELECT id, username, is_banned FROM users WHERE id = ?',
            [$toId]
        );
        if (!$toUser || (int) $toUser['is_banned'] === 1) {
            return ['error' => Lang::get('errors.numer.user_not_found')];
        }

        $expiresAt = date('Y-m-d H:i:s', time() + 30);
        $db->execute(
            'INSERT INTO invitations (room_id, from_user_id, to_user_id, expires_at) VALUES (?, ?, ?, ?)',
            [$numerId, $fromId, $toId, $expiresAt]
        );
        $invId = (int) $db->lastInsertId();

        return [
            'invitation_id' => $invId,
            'room_id' => $numerId,
            'from' => [
                'id' => $fromId,
                'username' => $from['username'],
                'avatar_url' => $from['avatar_url'] ?? null,
            ],
            'to_user_id' => $toId,
            'to_username' => $toUser['username'],
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Ответ на приглашение в нумер.
     * Last updated: 2026-04-17.
     */
    public static function respond(int $invId, int $userId, string $response): array
    {
        $db = Connection::getInstance();
        $inv = $db->fetchOne(
            "SELECT * FROM invitations WHERE id = ? AND to_user_id = ? AND status = 'pending' AND expires_at > NOW()",
            [$invId, $userId]
        );

        if (!$inv) {
            return ['error' => Lang::get('errors.numer.invitation_not_found')];
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
        $count = (int) $db->fetchOne(
            "SELECT COUNT(*) AS c FROM room_members WHERE room_id = ? AND room_role != 'banned'",
            [$roomId]
        )['c'];

        if ($count >= 4) {
            $db->execute("UPDATE invitations SET status = 'expired' WHERE id = ?", [$invId]);
            return ['error' => Lang::get('errors.numer.full')];
        }

        $db->execute(
            'INSERT IGNORE INTO room_members (room_id, user_id, room_role) VALUES (?, ?, ?)',
            [$roomId, $userId, 'member']
        );

        return [
            'accepted' => true,
            'invitation_id' => $invId,
            'room_id' => $roomId,
            'members' => self::roomMembers($db, $roomId),
        ];
    }

    /**
     * Выход из нумера с передачей owner первому вошедшему.
     * Last updated: 2026-04-17.
     */
    public static function leave(int $roomId, int $userId): array
    {
        $db = Connection::getInstance();
        $room = $db->fetchOne("SELECT id, type FROM rooms WHERE id = ? AND type = 'numer'", [$roomId]);
        if (!$room) {
            return ['error' => Lang::get('errors.numer.not_found')];
        }

        $member = $db->fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [$roomId, $userId]
        );
        $wasOwner = $member && ($member['room_role'] ?? '') === 'owner';

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

        $newOwnerId = null;
        if ($wasOwner) {
            $alreadyOwner = $db->fetchOne(
                "SELECT user_id FROM room_members WHERE room_id = ? AND room_role = 'owner' LIMIT 1",
                [$roomId]
            );

            if (!$alreadyOwner) {
                $candidate = $db->fetchOne(
                    "SELECT user_id
                     FROM room_members
                     WHERE room_id = ? AND room_role != 'banned'
                     ORDER BY joined_at ASC, user_id ASC
                     LIMIT 1",
                    [$roomId]
                );

                if ($candidate) {
                    $newOwnerId = (int) $candidate['user_id'];
                    $db->execute(
                        "UPDATE room_members SET room_role = 'owner' WHERE room_id = ? AND user_id = ?",
                        [$roomId, $newOwnerId]
                    );
                    $db->execute(
                        'UPDATE rooms SET owner_id = ? WHERE id = ?',
                        [$newOwnerId, $roomId]
                    );
                }
            }
        }

        return [
            'left' => true,
            'room_id' => $roomId,
            'remaining' => $remaining,
            'owner_transferred' => $newOwnerId !== null,
            'new_owner_id' => $newOwnerId,
        ];
    }

    /**
     * Фоновое истечение pending приглашений.
     * Last updated: 2026-04-17.
     */
    public static function expireInvitations(): void
    {
        Connection::getInstance()->execute(
            "UPDATE invitations SET status = 'expired' WHERE status = 'pending' AND expires_at <= NOW()"
        );
    }

    /**
     * Находит открытый нумер владельца или создает новый.
     * Last updated: 2026-04-17.
     */
    private static function findOrCreateOwnedNumer(Connection $db, int $ownerId): int
    {
        $numer = $db->fetchOne(
            "SELECT r.id FROM rooms r
             JOIN room_members rm ON rm.room_id = r.id AND rm.user_id = ? AND rm.room_role = 'owner'
             WHERE r.type = 'numer' AND r.is_closed = 0
             HAVING (SELECT COUNT(*) FROM room_members WHERE room_id = r.id AND room_role != 'banned') < 4
             LIMIT 1",
            [$ownerId]
        );

        if ($numer) {
            return (int) $numer['id'];
        }

        $db->execute(
            "INSERT INTO rooms (name, type, owner_id, max_members) VALUES (?, 'numer', ?, 4)",
            ['Нумер #' . $ownerId, $ownerId]
        );
        $numerId = (int) $db->lastInsertId();
        $db->execute(
            'INSERT INTO room_members (room_id, user_id, room_role) VALUES (?, ?, ?)',
            [$numerId, $ownerId, 'owner']
        );
        return $numerId;
    }

    /**
     * Список участников нумера для клиента.
     * Last updated: 2026-04-17.
     */
    private static function roomMembers(Connection $db, int $roomId): array
    {
        return $db->fetchAll(
            'SELECT u.id, u.username, u.nick_color, u.avatar_url
             FROM room_members rm
             JOIN users u ON u.id = rm.user_id
             WHERE rm.room_id = ?',
            [$roomId]
        );
    }
}
