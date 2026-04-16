<?php
declare(strict_types=1);

namespace Chat\Chat;

use Chat\DB\Connection;
use Chat\Security\HMAC;

class WhisperController
{
    public static function send(int $roomId, int $fromId, array $from, int $toId, string $rawContent): array
    {
        if ($fromId === $toId) {
            return ['error' => 'Нельзя шептать самому себе.'];
        }

        $db = Connection::getInstance();

        $toMember = $db->fetchOne(
            "SELECT rm.room_role, u.username FROM room_members rm
             JOIN users u ON u.id = rm.user_id
             WHERE rm.room_id = ? AND rm.user_id = ? AND rm.room_role != 'banned'",
            [$roomId, $toId]
        );
        if (!$toMember) {
            return ['error' => 'Получатель не в этой комнате.'];
        }

        $raw = trim($rawContent);
        if (!$raw || mb_strlen($raw) > 2000) {
            return ['error' => 'Сообщение пустое или слишком длинное.'];
        }

        $content = MessageController::format($raw);
        $hmac    = HMAC::sign($content);

        $db->execute(
            'INSERT INTO messages (room_id, user_id, content, content_hmac, type, whisper_to) VALUES (?, ?, ?, ?, ?, ?)',
            [$roomId, $fromId, $content, $hmac, 'whisper', $toId]
        );
        $msgId = (int) $db->lastInsertId();

        $toUser = $db->fetchOne(
            'SELECT id, username, nick_color, avatar_url FROM users WHERE id = ?',
            [$toId]
        );

        return [
            'message_id'  => $msgId,
            'room_id'     => $roomId,
            'from'        => [
                'id'         => $fromId,
                'username'   => $from['username'],
                'nick_color' => $from['nick_color'],
                'avatar_url' => $from['avatar_url'],
            ],
            'to' => $toUser,
            'content'     => $content,
            'created_at'  => date('Y-m-d H:i:s.000'),
        ];
    }

    public static function archive(int $page = 1, array $filters = []): void
    {
        $db     = Connection::getInstance();
        $offset = ($page - 1) * 50;
        $where  = ["m.type = 'whisper'"];
        $params = [];

        if (!empty($filters['room_id'])) {
            $where[]  = 'm.room_id = ?';
            $params[] = (int) $filters['room_id'];
        }
        if (!empty($filters['from_username'])) {
            $where[]  = 'sender.username LIKE ?';
            $params[] = '%' . $filters['from_username'] . '%';
        }
        if (!empty($filters['to_username'])) {
            $where[]  = 'recipient.username LIKE ?';
            $params[] = '%' . $filters['to_username'] . '%';
        }
        if (!empty($filters['date_from'])) {
            $where[]  = 'DATE(m.created_at) >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[]  = 'DATE(m.created_at) <= ?';
            $params[] = $filters['date_to'];
        }

        $sql = 'SELECT m.id, m.room_id, m.content, m.created_at,
                       r.name AS room_name,
                       sender.id AS from_id, sender.username AS from_username,
                       recipient.id AS to_id, recipient.username AS to_username
                FROM messages m
                JOIN rooms r ON r.id = m.room_id
                JOIN users sender ON sender.id = m.user_id
                JOIN users recipient ON recipient.id = m.whisper_to
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY m.created_at DESC
                LIMIT 50 OFFSET ' . $offset;

        $items = $db->fetchAll($sql, $params);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'whispers' => $items, 'page' => $page]);
        exit;
    }
}
