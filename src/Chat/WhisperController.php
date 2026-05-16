<?php
declare(strict_types=1);

namespace Chat\Chat;

use Chat\Admin\Access;
use Chat\DB\Connection;
use Chat\Security\HMAC;
use Chat\Security\Session;
use Chat\Support\Lang;
use Chat\Support\Timestamp;

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
        $senderSessionId = isset($from['session_id']) && (int) $from['session_id'] > 0
            ? (int) $from['session_id']
            : null;

        $db->execute(
            'INSERT INTO messages (room_id, user_id, sender_session_id, content, content_hmac, type, whisper_to) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$roomId, $fromId, $senderSessionId, $content, $hmac, 'whisper', $toId]
        );
        $msgId = (int) $db->lastInsertId();
        $createdAt = $db->fetchOne('SELECT created_at FROM messages WHERE id = ?', [$msgId])['created_at'] ?? null;

        $toUser = $db->fetchOne(
            'SELECT id, username, nickname, custom_status, nick_color, text_color, avatar_url FROM users WHERE id = ?',
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
                'text_color' => $from['text_color'],
                'avatar_url' => $from['avatar_url'],
            ],
            'to' => $toUser,
            'content' => $content,
            'created_at' => Timestamp::isoUtc($createdAt === null ? null : (string) $createdAt),
        ];
    }

    /**
     * Возвращает архив whisper для админ-панели.
     * Last updated: 2026-04-17.
     */
    public static function archive(int $page = 1, array $filters = []): void
    {
        Access::requireOwnerPrivateArchive(Session::current());
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
        $items = Timestamp::normalizeRows($items, ['created_at']);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'whispers' => $items, 'total' => $total, 'page' => $page], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function deleteWhisper(int $id): void
    {
        Access::requireOwnerPrivateArchive(Session::current());
        if (!\Chat\Security\CSRF::verifyRequest()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'CSRF.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        Connection::getInstance()->execute(
            "UPDATE messages SET is_deleted = 1, deleted_at = NOW() WHERE id = ? AND type = 'whisper'",
            [$id]
        );
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function clearWhispers(array $post): void
    {
        Access::requireOwnerPrivateArchive(Session::current());
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
        $db->execute('UPDATE messages SET is_deleted = 1, deleted_at = NOW() WHERE ' . implode(' AND ', $where), $params);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Список whisper-сессий для панели владельца.
     * Сессия = сообщения одной пары, разрыв между которыми ≤ 3 часа.
     * A→B и B→A считаются одной парой.
     */
    public static function ownerSessionList(array $filters = []): void
    {
        Access::requireOwnerPrivateArchive(Session::current());
        $db      = Connection::getInstance();
        $page    = max(1, (int) ($filters['page'] ?? 1));
        $perPage = 50;

        $where  = ["m.type = 'whisper'", 'm.is_deleted = 0'];
        $params = [];
        if (!empty($filters['date_from'])) {
            $where[]  = 'DATE(m.created_at) >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[]  = 'DATE(m.created_at) <= ?';
            $params[] = $filters['date_to'];
        }

        // Только метаданные (без content), упорядочено по паре и времени
        $rows = $db->fetchAll(
            'SELECT m.id, m.user_id AS sender_id, m.whisper_to AS receiver_id, m.created_at
             FROM messages m
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY LEAST(m.user_id, m.whisper_to), GREATEST(m.user_id, m.whisper_to), m.created_at, m.id',
            $params
        );

        // Один проход: накапливаем сессии, сырые строки не хранятся
        $sessions = [];
        $state    = null;
        foreach ($rows as $row) {
            $minUid = min((int) $row['sender_id'], (int) $row['receiver_id']);
            $maxUid = max((int) $row['sender_id'], (int) $row['receiver_id']);
            $rowTs  = strtotime((string) $row['created_at']);

            if ($state === null) {
                $state = [
                    'min_uid'      => $minUid,
                    'max_uid'      => $maxUid,
                    'first_msg_id' => (int) $row['id'],
                    'started_at'   => (string) $row['created_at'],
                    'last_at'      => (string) $row['created_at'],
                    'last_at_ts'   => $rowTs,
                    'count'        => 1,
                ];
            } elseif ($state['min_uid'] === $minUid
                      && $state['max_uid'] === $maxUid
                      && ($rowTs - $state['last_at_ts']) <= 10800) {
                $state['last_at']    = (string) $row['created_at'];
                $state['last_at_ts'] = $rowTs;
                $state['count']++;
            } else {
                $sessions[] = $state;
                $state = [
                    'min_uid'      => $minUid,
                    'max_uid'      => $maxUid,
                    'first_msg_id' => (int) $row['id'],
                    'started_at'   => (string) $row['created_at'],
                    'last_at'      => (string) $row['created_at'],
                    'last_at_ts'   => $rowTs,
                    'count'        => 1,
                ];
            }
        }
        if ($state !== null) {
            $sessions[] = $state;
        }
        unset($rows, $state);

        // Сортируем по убыванию last_at (последние сессии сверху)
        usort($sessions, static fn($a, $b) => $b['last_at_ts'] <=> $a['last_at_ts']);

        $total = count($sessions);
        $pages = max(1, (int) ceil($total / $perPage));
        $slice = array_slice($sessions, ($page - 1) * $perPage, $perPage);
        unset($sessions);

        header('Content-Type: application/json; charset=UTF-8');
        if (empty($slice)) {
            echo json_encode(['success' => true, 'sessions' => [], 'total' => $total, 'page' => $page, 'pages' => $pages], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Имена пользователей для пар текущей страницы
        $allUids = array_values(array_unique(array_merge(
            array_column($slice, 'min_uid'),
            array_column($slice, 'max_uid')
        )));
        $phU     = implode(',', array_fill(0, count($allUids), '?'));
        $userMap = [];
        foreach ($db->fetchAll("SELECT id, username FROM users WHERE id IN ($phU)", $allUids) as $u) {
            $userMap[(int) $u['id']] = (string) $u['username'];
        }

        // Превью: content первого сообщения каждой сессии
        $firstIds = array_values(array_column($slice, 'first_msg_id'));
        $phP      = implode(',', array_fill(0, count($firstIds), '?'));
        $prevMap  = [];
        foreach ($db->fetchAll("SELECT id, content FROM messages WHERE id IN ($phP)", $firstIds) as $p) {
            $prevMap[(int) $p['id']] = mb_substr(strip_tags((string) $p['content']), 0, 120);
        }

        $result = [];
        foreach ($slice as $s) {
            $result[] = [
                'session_token' => (string) $s['first_msg_id'],
                'user1'         => ['id' => $s['min_uid'], 'username' => $userMap[$s['min_uid']] ?? '?'],
                'user2'         => ['id' => $s['max_uid'], 'username' => $userMap[$s['max_uid']] ?? '?'],
                'started_at'    => Timestamp::isoUtc($s['started_at']),
                'ended_at'      => Timestamp::isoUtc($s['last_at']),
                'count'         => $s['count'],
                'preview'       => $prevMap[$s['first_msg_id']] ?? '',
            ];
        }

        echo json_encode(['success' => true, 'sessions' => $result, 'total' => $total, 'page' => $page, 'pages' => $pages], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Все сообщения одной whisper-сессии по firstMsgId (session_token).
     * Возвращает сообщения пары начиная с firstMsgId, пока разрыв ≤ 3 часа.
     */
    public static function ownerSessionDetail(int $firstMsgId): void
    {
        Access::requireOwnerPrivateArchive(Session::current());
        $db = Connection::getInstance();

        $first = $db->fetchOne(
            "SELECT id, user_id AS sender_id, whisper_to AS receiver_id, created_at
             FROM messages WHERE id = ? AND type = 'whisper' AND is_deleted = 0",
            [$firstMsgId]
        );
        if (!$first) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Not found'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $minUid = min((int) $first['sender_id'], (int) $first['receiver_id']);
        $maxUid = max((int) $first['sender_id'], (int) $first['receiver_id']);

        $rows = $db->fetchAll(
            "SELECT m.id, m.user_id AS sender_id, m.content, m.created_at
             FROM messages m
             WHERE m.id >= ? AND m.type = 'whisper' AND m.is_deleted = 0
               AND LEAST(m.user_id, m.whisper_to) = ? AND GREATEST(m.user_id, m.whisper_to) = ?
             ORDER BY m.created_at, m.id",
            [$firstMsgId, $minUid, $maxUid]
        );

        $messages = [];
        $lastTs   = null;
        foreach ($rows as $row) {
            $rowTs = strtotime((string) $row['created_at']);
            if ($lastTs !== null && ($rowTs - $lastTs) > 10800) {
                break;
            }
            $lastTs     = $rowTs;
            $messages[] = [
                'id'         => (int) $row['id'],
                'sender_id'  => (int) $row['sender_id'],
                'content'    => (string) $row['content'],
                'created_at' => Timestamp::isoUtc((string) $row['created_at']),
            ];
        }
        unset($rows);

        // Имена отправителей
        $uids    = array_values(array_unique(array_column($messages, 'sender_id')));
        $userMap = [];
        if (!empty($uids)) {
            $ph = implode(',', array_fill(0, count($uids), '?'));
            foreach ($db->fetchAll("SELECT id, username FROM users WHERE id IN ($ph)", $uids) as $u) {
                $userMap[(int) $u['id']] = (string) $u['username'];
            }
        }
        foreach ($messages as &$msg) {
            $msg['sender_username'] = $userMap[$msg['sender_id']] ?? '?';
        }
        unset($msg);

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'messages' => $messages], JSON_UNESCAPED_UNICODE);
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
