<?php
declare(strict_types=1);

namespace Chat\Chat;

use Chat\DB\Connection;

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

        $db = Connection::getInstance();
        $params = [$roomId, 'whisper'];
        $where = 'WHERE m.room_id = ? AND m.is_deleted = 0 AND m.type != ?';

        if ($beforeId !== null) {
            $where .= ' AND m.id < ?';
            $params[] = $beforeId;
        }

        $messages = $db->fetchAll(
            'SELECT m.id, m.user_id, m.content, m.type, m.embed_data, m.created_at,
                    u.username, u.custom_status, u.nick_color, u.text_color, u.avatar_url, u.global_role,
                    rm.room_role
             FROM messages m
             JOIN users u ON u.id = m.user_id
             LEFT JOIN room_members rm ON rm.room_id = m.room_id AND rm.user_id = m.user_id
             ' . $where . '
             ORDER BY m.created_at DESC
             LIMIT ' . self::PAGE_SIZE,
            $params
        );

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'messages' => array_reverse($messages)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function send(int $roomId, int $actorId, array $actor, array $data): array
    {
        $db = Connection::getInstance();
        $memberState = $db->fetchOne(
            'SELECT muted_until FROM room_members WHERE room_id = ? AND user_id = ?',
            [$roomId, $actorId]
        );
        if (!empty($memberState['muted_until']) && strtotime((string)$memberState['muted_until']) > time()) {
            return ['error' => 'У вас кляп до ' . date('H:i:s', strtotime((string)$memberState['muted_until'])) . '.'];
        }

        if (!self::canPost($roomId, $actorId, (string) $actor['global_role'])) {
            return ['error' => 'Нет доступа к комнате.'];
        }

        $raw = trim((string) ($data['content'] ?? ''));
        if ($raw === '' || mb_strlen($raw) > 2000) {
            return ['error' => 'Сообщение пустое или слишком длинное.'];
        }

        $content = self::format($raw);
        $embed = EmbedProcessor::process($raw);
        $embedData = $embed ? json_encode($embed, JSON_UNESCAPED_UNICODE) : null;

        $db->execute(
            'INSERT INTO messages (room_id, user_id, content, type, embed_data) VALUES (?, ?, ?, ?, ?)',
            [$roomId, $actorId, $content, 'text', $embedData]
        );

        return [
            'id' => (int) $db->lastInsertId(),
            'room_id' => $roomId,
            'user_id' => $actorId,
            'username' => $actor['username'],
            'custom_status' => $actor['custom_status'] ?? null,
            'nick_color' => $actor['nick_color'],
            'text_color' => $actor['text_color'],
            'avatar_url' => $actor['avatar_url'],
            'global_role' => $actor['global_role'],
            'content' => $content,
            'embed_data' => $embed,
            'type' => 'text',
            'created_at' => date('Y-m-d H:i:s.000'),
        ];
    }

    public static function delete(int $messageId, int $actorId, array $actor): array
    {
        $db = Connection::getInstance();
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

        $db->execute('UPDATE messages SET is_deleted = 1, deleted_by = ? WHERE id = ?', [$actorId, $messageId]);

        return ['deleted' => true, 'message_id' => $messageId, 'room_id' => (int) $msg['room_id']];
    }

    private static function canDelete(array $msg, int $actorId, array $actor): bool
    {
        $globalRole = (string) ($actor['global_role'] ?? 'user');
        if (in_array($globalRole, ['platform_owner', 'admin'], true)) {
            return true;
        }

        if ($globalRole === 'moderator') {
            $db = Connection::getInstance();
            $room = $db->fetchOne('SELECT type FROM rooms WHERE id = ?', [(int) $msg['room_id']]);
            return $room && $room['type'] === 'public';
        }

        $db = Connection::getInstance();
        $roomRole = $db->fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [(int) $msg['room_id'], $actorId]
        )['room_role'] ?? null;

        return in_array($roomRole, ['owner', 'local_admin', 'local_moderator'], true);
    }

    private static function canPost(int $roomId, int $userId, string $globalRole): bool
    {
        $db = Connection::getInstance();
        $room = $db->fetchOne('SELECT type, is_closed FROM rooms WHERE id = ?', [$roomId]);

        if (!$room || (int) $room['is_closed'] === 1) {
            return false;
        }

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
        if (!$member || $member['room_role'] === 'banned') {
            return false;
        }

        if ($room['type'] === 'numer' && $globalRole === 'moderator') {
            return false;
        }

        return true;
    }

    private static function canAccess(int $roomId, int $userId, string $globalRole): bool
    {
        $db = Connection::getInstance();
        $room = $db->fetchOne('SELECT type, is_closed FROM rooms WHERE id = ?', [$roomId]);

        if (!$room || (int) $room['is_closed'] === 1) {
            return false;
        }

        if (in_array($globalRole, ['platform_owner', 'admin'], true)) {
            return true;
        }

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

        return $member && $member['room_role'] !== 'banned';
    }

    private static function jsonError(string $message, int $code = 400): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
