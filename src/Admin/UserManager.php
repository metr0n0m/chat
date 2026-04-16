<?php
declare(strict_types=1);

namespace Chat\Admin;

use Chat\DB\Connection;
use Chat\Security\{CSRF, ColorContrast};

class UserManager
{
    public static function list(int $page = 1, string $search = ''): void
    {
        $db     = Connection::getInstance();
        $offset = ($page - 1) * 50;
        $params = [];
        $where  = '1=1';

        if ($search) {
            $where    = '(username LIKE ? OR email LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        $total = (int) $db->fetchOne(
            'SELECT COUNT(*) AS c FROM users WHERE ' . $where,
            $params
        )['c'];

        $users = $db->fetchAll(
            'SELECT id, username, email, global_role, can_create_room, is_banned, created_at, last_seen_at
             FROM users WHERE ' . $where . '
             ORDER BY id DESC LIMIT 50 OFFSET ' . $offset,
            $params
        );

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'users' => $users, 'total' => $total, 'page' => $page]);
        exit;
    }

    public static function update(int $targetId, array $data): void
    {
        if (!CSRF::verifyRequest()) {
            self::jsonError('CSRF.', 403);
        }

        $db   = Connection::getInstance();
        $user = $db->fetchOne('SELECT id FROM users WHERE id = ?', [$targetId]);
        if (!$user) {
            self::jsonError('Пользователь не найден.', 404);
        }

        $allowed = ['global_role', 'is_banned', 'can_create_room'];
        $set     = [];
        $params  = [];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];

            if ($field === 'global_role') {
                if (!in_array($value, ['admin', 'moderator', 'user'], true)) {
                    self::jsonError('Недопустимая роль.');
                }
            } else {
                $value = (int) (bool) $value;
            }

            $set[]    = "`$field` = ?";
            $params[] = $value;
        }

        if (!$set) {
            self::jsonError('Нет данных для обновления.');
        }

        $params[] = $targetId;
        $db->execute('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?', $params);

        self::jsonSuccess(['updated' => true]);
    }

    public static function delete(int $targetId): void
    {
        if (!CSRF::verifyRequest()) {
            self::jsonError('CSRF.', 403);
        }
        Connection::getInstance()->execute('DELETE FROM users WHERE id = ?', [$targetId]);
        self::jsonSuccess(['deleted' => true]);
    }

    public static function profile(int $userId): void
    {
        $db   = Connection::getInstance();
        $user = $db->fetchOne(
            'SELECT id, username, email, avatar_url, signature, nick_color, text_color,
                    global_role, can_create_room, is_banned, created_at, last_seen_at
             FROM users WHERE id = ?',
            [$userId]
        );
        if (!$user) {
            self::jsonError('Не найдено.', 404);
        }
        $user['friend_count'] = (int) $db->fetchOne(
            "SELECT COUNT(*) AS c FROM friendships WHERE (requester_id = ? OR addressee_id = ?) AND status = 'accepted'",
            [$userId, $userId]
        )['c'];
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'user' => $user]);
        exit;
    }

    public static function updateSettings(int $userId, array $post, array $files): void
    {
        if (!CSRF::verifyRequest()) {
            self::jsonError('CSRF.', 403);
        }

        $db  = Connection::getInstance();
        $set = [];
        $params = [];

        if (isset($post['username'])) {
            $username = trim($post['username']);
            if (strlen($username) < 3 || strlen($username) > 50 || !preg_match('/^[\w\p{Cyrillic}]+$/u', $username)) {
                self::jsonError('Некорректное имя пользователя.');
            }
            $exists = $db->fetchOne('SELECT id FROM users WHERE username = ? AND id != ?', [$username, $userId]);
            if ($exists) {
                self::jsonError('Имя уже занято.');
            }
            $set[]    = 'username = ?';
            $params[] = $username;
        }

        if (isset($post['signature'])) {
            $sig = mb_substr(trim($post['signature']), 0, 300);
            $set[]    = 'signature = ?';
            $params[] = $sig;
        }

        if (isset($post['nick_color'])) {
            $error = ColorContrast::validate($post['nick_color']);
            if ($error) {
                self::jsonError($error);
            }
            $set[]    = 'nick_color = ?';
            $params[] = strtolower($post['nick_color']);
        }

        if (isset($post['text_color'])) {
            $error = ColorContrast::validate($post['text_color']);
            if ($error) {
                self::jsonError($error);
            }
            $set[]    = 'text_color = ?';
            $params[] = strtolower($post['text_color']);
        }

        if (isset($post['password']) && $post['password'] !== '') {
            if (strlen($post['password']) < 8) {
                self::jsonError('Пароль минимум 8 символов.');
            }
            $set[]    = 'password_hash = ?';
            $params[] = password_hash($post['password'], PASSWORD_ARGON2ID);
        }

        if (!empty($post['avatar_url'])) {
            $url = trim($post['avatar_url']);
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                self::jsonError('Некорректный URL аватара.');
            }
            $headers = self::headRequest($url);
            $mime    = strtolower(explode(';', $headers['content-type'] ?? '')[0]);
            $size    = (int) ($headers['content-length'] ?? 0);
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                self::jsonError('URL не является изображением.');
            }
            if ($size > 5 * 1024 * 1024) {
                self::jsonError('Изображение слишком большое (>5MB).');
            }
            $set[]    = 'avatar_url = ?';
            $params[] = $url;
        }

        if (!empty($files['avatar']['tmp_name'])) {
            $avatarUrl = self::uploadAvatar($userId, $files['avatar']);
            $set[]    = 'avatar_url = ?';
            $params[] = $avatarUrl;
        }

        if (!$set) {
            self::jsonError('Нет данных для обновления.');
        }

        $params[] = $userId;
        $db->execute('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?', $params);
        self::jsonSuccess(['updated' => true]);
    }

    private static function uploadAvatar(int $userId, array $file): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            self::jsonError('Ошибка загрузки файла.');
        }
        if ($file['size'] > 2 * 1024 * 1024) {
            self::jsonError('Файл слишком большой (>2MB).');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        if (!isset($allowed[$mime])) {
            self::jsonError('Недопустимый тип файла. Разрешены: JPEG, PNG, GIF, WEBP.');
        }

        $image = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
            'image/png'  => imagecreatefrompng($file['tmp_name']),
            'image/gif'  => imagecreatefromgif($file['tmp_name']),
            'image/webp' => imagecreatefromwebp($file['tmp_name']),
        };
        if (!$image) {
            self::jsonError('Не удалось обработать изображение.');
        }

        $w = imagesx($image);
        $h = imagesy($image);
        $size = min($w, $h);
        $x    = (int) (($w - $size) / 2);
        $y    = (int) (($h - $size) / 2);

        $canvas = imagecreatetruecolor(200, 200);
        imagecopyresampled($canvas, $image, 0, 0, $x, $y, 200, 200, $size, $size);
        imagedestroy($image);

        $dir  = AVATAR_PATH;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir . '/' . $userId . '.jpg';
        imagejpeg($canvas, $path, 90);
        imagedestroy($canvas);

        $db = Connection::getInstance();
        $db->execute(
            'INSERT INTO avatar_uploads (user_id, file_path, file_size) VALUES (?, ?, ?)',
            [$userId, $path, $file['size']]
        );

        return AVATAR_URL_PREFIX . '/' . $userId . '.jpg?t=' . time();
    }

    private static function headRequest(string $url): array
    {
        $ctx = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 5]]);
        @file_get_contents($url, false, $ctx);
        $headers = [];
        foreach (($http_response_header ?? []) as $line) {
            if (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        return $headers;
    }

    private static function jsonError(string $msg, int $code = 400): never
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    }

    private static function jsonSuccess(array $data = []): never
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => true] + $data);
        exit;
    }
}
