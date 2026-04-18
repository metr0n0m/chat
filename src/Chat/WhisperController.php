<?php
declare(strict_types=1);

namespace Chat\Chat;

use Chat\DB\Connection;
use Chat\Security\HMAC;
use Chat\Support\Lang;

/**
 * Логика whisper-сообщений и их архива.
 * Last updated: 2026-04-17.
 */
class WhisperController
{
    /**
     * Отправляет whisper в рамках комнаты (включая self-whisper).
     * Last updated: 2026-04-17.
     */
    public static function send(int $roomId, int $fromId, array $from, int $toId, string $rawContent): array
    {
        $db = Connection::getInstance();

        $fromMember = $db->fetchOne(
            'SELECT muted_until FROM room_members WHERE room_id = ? AND user_id = ? AND muted_until > NOW()',
            [$roomId, $fromId]
        );
        if ($fromMember) {
            return ['error' => 'У вас кляп до ' . date('H:i:s', strtotime((string) $fromMember['muted_until'])) . '.'];
        }

        $toMember = $db->fetchOne(
            "SELECT rm.room_role, u.username
             FROM room_members rm
             JOIN users u ON u.id = rm.user_id
             WHERE rm.room_id = ? AND rm.user_id = ? AND rm.room_role != 'banned'",
            [$roomId, $toId]
        );
        if (!$toMember) {
            return ['error' => Lang::get('errors.whisper.user_not_in_room')];
        }

        $raw = self::normalizeInput($rawContent, (string) ($toMember['username'] ?? ''));
        if ($raw === '' || mb_strlen($raw) > 2000) {
            return ['error' => Lang::get('errors.whisper.empty_or_too_long')];
        }

        $content = MessageController::format($raw);
        $hmac = HMAC::sign($content);

        $db->execute(
            'INSERT INTO messages (room_id, user_id, content, content_hmac, type, whisper_to) VALUES (?, ?, ?, ?, ?, ?)',
            [$roomId, $fromId, $content, $hmac, 'whisper', $toId]
        );
        $msgId = (int) $db->lastInsertId();

        $toUser = $db->fetchOne(
            'SELECT id, username, nickname, custom_status, nick_color, avatar_url FROM users WHERE id = ?',
            [$toId]
        );

        return [
            'message_id' => $msgId,
            'room_id' => $roomId,
            'from' => [
                'id' => $fromId,
                'username' => $from['username'],
                'nickname' => $from['nickname'] ?? null,
                'custom_status' => $from['custom_status'] ?? null,
                'nick_color' => $from['nick_color'],
                'avatar_url' => $from['avatar_url'],
            ],
            'to' => $toUser,
            'content' => $content,
            'created_at' => date('Y-m-d H:i:s.000'),
        ];
    }

    /**
     * Возвращает архив whisper для админ-панели.
     * Last updated: 2026-04-17.
     */
    public static function archive(int $page = 1, array $filters = []): void
    {
        $db = Connection::getInstance();
        $offset = max(0, ($page - 1) * 50);
        $where = ["m.type = 'whisper'", 'm.is_deleted = 0'];
        $params = [];

        if (!empty($filters['room_id'])) {
            $where[] = 'm.room_id = ?';
            $params[] = (int) $filters['room_id'];
        }
        if (!empty($filters['from_username'])) {
            $where[] = 'sender.username LIKE ?';
            $params[] = '%' . $filters['from_username'] . '%';
        }
        if (!empty($filters['to_username'])) {
            $where[] = 'recipient.username LIKE ?';
            $params[] = '%' . $filters['to_username'] . '%';
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(m.created_at) >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(m.created_at) <= ?';
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

        $countSql = 'SELECT COUNT(*) AS c FROM messages m
                JOIN rooms r ON r.id = m.room_id
                JOIN users sender ON sender.id = m.user_id
                JOIN users recipient ON recipient.id = m.whisper_to
                WHERE ' . implode(' AND ', $where);
        $total = (int) ($db->fetchOne($countSql, $params)['c'] ?? 0);

        $items = $db->fetchAll($sql, $params);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'whispers' => $items, 'total' => $total, 'page' => $page], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function deleteWhisper(int $id): void
    {
        if (!\Chat\Security\CSRF::verifyRequest()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'CSRF.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        Connection::getInstance()->execute(
            "UPDATE messages SET is_deleted = 1 WHERE id = ? AND type = 'whisper'",
            [$id]
        );
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function clearWhispers(array $post): void
    {
        if (!\Chat\Security\CSRF::verifyRequest()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'CSRF.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $db     = Connection::getInstance();
        $where  = ["type = 'whisper'", 'is_deleted = 0'];
        $params = [];
        if (!empty($post['room_id'])) {
            $where[]  = 'room_id = ?';
            $params[] = (int) $post['room_id'];
        }
        if (!empty($post['user_id'])) {
            $where[]  = '(user_id = ? OR whisper_to = ?)';
            $params[] = (int) $post['user_id'];
            $params[] = (int) $post['user_id'];
        }
        if (!empty($post['from_username'])) {
            $u = $db->fetchOne('SELECT id FROM users WHERE username LIKE ?', ['%' . $post['from_username'] . '%']);
            if ($u) {
                $where[]  = 'user_id = ?';
                $params[] = (int) $u['id'];
            }
        }
        if (!empty($post['to_username'])) {
            $u = $db->fetchOne('SELECT id FROM users WHERE username LIKE ?', ['%' . $post['to_username'] . '%']);
            if ($u) {
                $where[]  = 'whisper_to = ?';
                $params[] = (int) $u['id'];
            }
        }
        $db->execute('UPDATE messages SET is_deleted = 1 WHERE ' . implode(' AND ', $where), $params);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Убирает служебные whisper-префиксы из текста перед сохранением.
     * Last updated: 2026-04-17.
     */
    private static function normalizeInput(string $raw, string $toUsername): string
    {
        $text = trim($raw);
        if ($text === '') {
            return '';
        }

        if ($toUsername !== '') {
            $quoted = preg_quote($toUsername, '/');
            $text = (string) preg_replace('/^@p\+' . $quoted . '\s+/iu', '', $text);
            $text = (string) preg_replace('/^' . $quoted . ',\s*/iu', '', $text);
        }

        $text = (string) preg_replace('/^@p\+\S+\s+/iu', '', $text);
        return trim($text);
    }
}
