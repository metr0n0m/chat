<?php
declare(strict_types=1);

namespace Chat\Chat;

use Chat\DB\Connection;
use Chat\Security\{HMAC, CSRF};

class MessageController
{
    private const PAGE_SIZE = 50;

    public static function format(string $raw): string
    {
        $text = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(.*?)__/s', '<u>$1</u>', $text);
        $text = preg_replace('/_(.*?)_/s', '<em>$1</em>', $text);
        $text = preg_replace('/~~(.*?)~~/s', '<del>$1</del>', $text);
        return strip_tags($text, '<strong><em><u><del>');
    }

    public static function history(int $roomId, int $actorId, string $actorRole, ?int $beforeId = null): void
    {
        if (!self::canAccess($roomId, $actorId, $actorRole)) {
            self::jsonError('Нет доступа.', 403);
        }

        $db     = Connection::getInstance();
        $params = [$roomId];
        $where  = 'WHERE m.room_id = ? AND m.is_deleted = 0 AND m.type != ?';
        $params[] = 'whisper';

        if ($beforeId) {
            $where   .= ' AND m.id < ?';
            $params[] = $beforeId;
        }

        $messages = $db->fetchAll(
            'SELECT m.id, m.user_id, m.content, m.type, m.embed_data, m.created_at,
                    u.username, u.nick_color, u.text_color, u.avatar_url, u.global_role,
                    rm.room_role
             FROM messages m
             JOIN users u ON u.id = m.user_id
             LEFT JOIN room_members rm ON rm.room_id = m.room_id AND rm.user_id = m.user_id
             ' . $where . '
             ORDER BY m.created_at DESC
             LIMIT ' . self::PAGE_SIZE,
            $params
        );

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'messages' => array_reverse($messages)]);
        exit;
    }

    public static function send(int $roomId, int $actorId, array $actor, array $data): array
    {
        if (!self::canPost($roomId, $actorId, $actor['global_role'])) {
            return ['error' => 'Нет доступа к комнате.'];
        }

        $raw = trim($data['content'] ?? '');
        if (!$raw || mb_strlen($raw) > 2000) {
            return ['error' => 'Сообщение пустое или слишком длинное.'];
        }

        $content = self::format($raw);
        $hmac    = HMAC::sign($content);
        $db      = Connection::getInstance();

        $embedData = null;
        $embed     = EmbedProcessor::process($raw);
        if ($embed) {
            $embedData = json_encode($embed);
        }

        $db->execute(
            'INSERT INTO messages (room_id, user_id, content, content_hmac, type, embed_data) VALUES (?, ?, ?, ?, ?, ?)',
            [$roomId, $actorId, $content, $hmac, 'text', $embedData]
        );
        $msgId = (int) $db->lastInsertId();

        return [
            'id'         => $msgId,
            'room_id'    => $roomId,
            'user_id'    => $actorId,
            'username'   => $actor['username'],
            'nick_color' => $actor['nick_color'],
            'text_color' => $actor['text_color'],
            'avatar_url' => $actor['avatar_url'],
            'global_role'=> $actor['global_role'],
            'content'    => $content,
            'embed_data' => $embed,
            'type'       => 'text',
            'created_at' => date('Y-m-d H:i:s.000'),
        ];
    }

    public static function delete(int $messageId, int $actorId, array $actor): array
    {
        $db  = Connection::getInstance();
        $msg = $db->fetchOne(
            'SELECT id, room_id, user_id FROM messages WHERE id = ? AND is_deleted = 0',
            [$messageId]
        );

        if (!$msg) {
            return ['error' => 'Сообщение не найдено.'];
        }

        if (!self::canDelete($msg, $actorId, $actor)) {
            return ['error' => 'Нет прав.'];
        }

        $db->execute(
            'UPDATE messages SET is_deleted = 1, deleted_by = ? WHERE id = ?',
            [$actorId, $messageId]
        );

        return ['deleted' => true, 'message_id' => $messageId, 'room_id' => (int) $msg['room_id']];
    }

    private static function canDelete(array $msg, int $actorId, array $actor): bool
    {
        if (in_array($actor['global_role'], ['admin', 'moderator'], true)) {
            return true;
        }
        $db       = Connection::getInstance();
        $roomRole = $db->fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [$msg['room_id'], $actorId]
        )['room_role'] ?? null;

        return in_array($roomRole, ['owner', 'local_admin', 'local_moderator'], true);
    }

    private static function canPost(int $roomId, int $userId, string $globalRole): bool
    {
        $db  = Connection::getInstance();
        $room = $db->fetchOne('SELECT type, is_closed FROM rooms WHERE id = ?', [$roomId]);
        if (!$room || $room['is_closed']) {
            return false;
        }
        $member = $db->fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [$roomId, $userId]
        );
        if (!$member || $member['room_role'] === 'banned') {
            return false;
        }
        if ($room['type'] === 'numer' && $globalRole !== 'user') {
            // admins/mods only if explicitly member
            return true;
        }
        return true;
    }

    private static function canAccess(int $roomId, int $userId, string $globalRole): bool
    {
        $db   = Connection::getInstance();
        $room = $db->fetchOne('SELECT type, is_closed FROM rooms WHERE id = ?', [$roomId]);
        if (!$room) {
            return false;
        }
        if ($globalRole === 'admin') {
            return true;
        }
        $member = $db->fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [$roomId, $userId]
        );
        return $member && $member['room_role'] !== 'banned';
    }

    private static function jsonError(string $msg, int $code = 400): never
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    }
}
