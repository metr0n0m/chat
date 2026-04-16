<?php
declare(strict_types=1);

namespace Chat\Admin;

use Chat\DB\Connection;
use Chat\Security\ColorContrast;
use Chat\Security\CSRF;
use Chat\Security\Session;

class UserManager
{
    private const GLOBAL_ROLE_LEVELS = [
        'user' => 1,
        'moderator' => 2,
        'admin' => 3,
        'platform_owner' => 4,
    ];
    public static function list(int $page = 1, string $search = ''): void
    {
        $db = Connection::getInstance();
        $offset = max(0, ($page - 1) * 50);
        $params = [];
        $where = '1=1';

        if ($search !== '') {
            $where = '(username LIKE ? OR email LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        $total = (int) $db->fetchOne('SELECT COUNT(*) AS c FROM users WHERE ' . $where, $params)['c'];
        $users = $db->fetchAll(
            'SELECT id, username, email, global_role, can_create_room, is_banned, created_at, last_seen_at
             FROM users
             WHERE ' . $where . '
             ORDER BY id DESC
             LIMIT 50 OFFSET ' . $offset,
            $params
        );

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'users' => $users, 'total' => $total, 'page' => $page], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function update(int $targetId, array $data): void
    {
        if (!CSRF::verifyRequest()) {
            self::jsonError('CSRF.', 403);
        }

        $db = Connection::getInstance();
        $actor = Session::current();
        $target = $db->fetchOne('SELECT id, global_role FROM users WHERE id = ?', [$targetId]);

        if (!$target) {
            self::jsonError('Пользователь не найден.', 404);
        }

        self::assertRoleManagementAllowed($actor, $target, $targetId, $data);

        $allowed = ['global_role', 'is_banned', 'can_create_room'];
        $set = [];
        $params = [];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];

            if ($field === 'global_role') {
                if (!in_array($value, ['platform_owner', 'admin', 'moderator', 'user'], true)) {
                    self::jsonError('Недопустимая роль.');
                }
            } else {
                $value = (int) (bool) $value;
            }

            $set[] = "`$field` = ?";
            $params[] = $value;
        }

        if ($set === []) {
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

        $db = Connection::getInstance();
        $actor = Session::current();
        $target = $db->fetchOne('SELECT id, global_role FROM users WHERE id = ?', [$targetId]);

        if (!$target) {
            self::jsonError('Пользователь не найден.', 404);
        }

        self::assertHigherRole($actor, $target, $targetId);

        $db->execute('DELETE FROM users WHERE id = ?', [$targetId]);
        self::jsonSuccess(['deleted' => true]);
    }

    public static function profile(int $userId): void
    {
        $db = Connection::getInstance();
        $user = $db->fetchOne(
            'SELECT id, username, email, avatar_url, signature, nick_color, text_color,
                    global_role, can_create_room, is_banned, created_at, last_seen_at
             FROM users
             WHERE id = ?',
            [$userId]
        );

        if (!$user) {
            self::jsonError('Пользователь не найден.', 404);
        }

        $user['friend_count'] = (int) $db->fetchOne(
            "SELECT COUNT(*) AS c
             FROM friendships
             WHERE (requester_id = ? OR addressee_id = ?) AND status = 'accepted'",
            [$userId, $userId]
        )['c'];

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'user' => $user], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function updateSettings(int $userId, array $post, array $files): void
    {
        if (!CSRF::verifyRequest()) {
            self::jsonError('CSRF.', 403);
        }

        $db = Connection::getInstance();
        $currentUser = $db->fetchOne(
            'SELECT username, nick_color, text_color, avatar_url, signature
             FROM users
             WHERE id = ?',
            [$userId]
        );

        if (!$currentUser) {
            self::jsonError('Пользователь не найден.', 404);
        }

        $set = [];
        $params = [];

        if (isset($post['username'])) {
            $username = trim((string) $post['username']);
            if (mb_strlen($username) < 3 || mb_strlen($username) > 50 || !preg_match('/^[\w\p{Cyrillic}]+$/u', $username)) {
                self::jsonError('Некорректное имя пользователя.');
            }
            $exists = $db->fetchOne('SELECT id FROM users WHERE username = ? AND id != ?', [$username, $userId]);
            if ($exists) {
                self::jsonError('Имя уже занято.');
            }
            $set[] = 'username = ?';
            $params[] = $username;
        }

        if (isset($post['signature'])) {
            $signature = mb_substr(trim((string) $post['signature']), 0, 300);
            $set[] = 'signature = ?';
            $params[] = $signature;
        }

        if (isset($post['nick_color'])) {
            $nickColor = strtolower(trim((string) $post['nick_color']));
            if ($nickColor !== strtolower((string) $currentUser['nick_color'])) {
                $error = ColorContrast::validate($nickColor);
                if ($error) {
                    self::jsonError($error);
                }
            }
            $set[] = 'nick_color = ?';
            $params[] = $nickColor;
        }

        if (isset($post['text_color'])) {
            $textColor = strtolower(trim((string) $post['text_color']));
            if ($textColor !== strtolower((string) $currentUser['text_color'])) {
                $error = ColorContrast::validate($textColor);
                if ($error) {
                    self::jsonError($error);
                }
            }
            $set[] = 'text_color = ?';
            $params[] = $textColor;
        }

        if (isset($post['password']) && $post['password'] !== '') {
            $password = (string) $post['password'];
            if (strlen($password) < 8) {
                self::jsonError('Пароль должен быть не короче 8 символов.');
            }
            $set[] = 'password_hash = ?';
            $params[] = password_hash($password, PASSWORD_ARGON2ID);
        }

        if (!empty($post['avatar_url'])) {
            $url = trim((string) $post['avatar_url']);
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                self::jsonError('Некорректный URL аватара.');
            }

            $headers = self::headRequest($url);
            $mime = strtolower(explode(';', $headers['content-type'] ?? '')[0]);
            $size = (int) ($headers['content-length'] ?? 0);

            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                self::jsonError('URL не указывает на изображение.');
            }
            if ($size > 5 * 1024 * 1024) {
                self::jsonError('Изображение слишком большое (>5MB).');
            }

            $set[] = 'avatar_url = ?';
            $params[] = $url;
        }

        if (!empty($files['avatar']['tmp_name'])) {
            $avatarUrl = self::uploadAvatar($userId, $files['avatar']);
            $set[] = 'avatar_url = ?';
            $params[] = $avatarUrl;
        }

        if ($set === []) {
            self::jsonError('Нет данных для обновления.');
        }

        $params[] = $userId;
        $db->execute('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?', $params);

        $updated = $db->fetchOne(
            'SELECT id, username, email, avatar_url, signature, nick_color, text_color,
                    global_role, can_create_room, is_banned, created_at, last_seen_at
             FROM users
             WHERE id = ?',
            [$userId]
        );

        self::jsonSuccess(['updated' => true, 'user' => $updated]);
    }

    private static function uploadAvatar(int $userId, array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            self::jsonError('Ошибка загрузки файла.');
        }
        if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
            self::jsonError('Файл слишком большой (>2MB).');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($file['tmp_name']);
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        if (!in_array($mime, $allowed, true)) {
            self::jsonError('Недопустимый тип файла. Разрешены JPEG, PNG, GIF, WEBP.');
        }

        $image = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
            'image/png' => imagecreatefrompng($file['tmp_name']),
            'image/gif' => imagecreatefromgif($file['tmp_name']),
            'image/webp' => imagecreatefromwebp($file['tmp_name']),
        };

        if (!$image) {
            self::jsonError('Не удалось обработать изображение.');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $size = min($width, $height);
        $srcX = (int) (($width - $size) / 2);
        $srcY = (int) (($height - $size) / 2);

        $canvas = imagecreatetruecolor(200, 200);
        imagecopyresampled($canvas, $image, 0, 0, $srcX, $srcY, 200, 200, $size, $size);
        imagedestroy($image);

        if (!is_dir(AVATAR_PATH)) {
            mkdir(AVATAR_PATH, 0755, true);
        }

        $path = AVATAR_PATH . '/' . $userId . '.jpg';
        imagejpeg($canvas, $path, 90);
        imagedestroy($canvas);

        $db = Connection::getInstance();
        $db->execute(
            'INSERT INTO avatar_uploads (user_id, file_path, file_size) VALUES (?, ?, ?)',
            [$userId, $path, (int) ($file['size'] ?? 0)]
        );

        return AVATAR_URL_PREFIX . '/' . $userId . '.jpg?t=' . time();
    }


    private static function assertRoleManagementAllowed(array $actor, array $target, int $targetId, array $data): void
    {
        $actorRole = (string) ($actor['global_role'] ?? 'user');
        $actorId = (int) ($actor['id'] ?? 0);
        $targetRole = (string) ($target['global_role'] ?? 'user');

        if ($actorId === $targetId) {
            self::jsonError('Нельзя менять собственные административные права через админку.', 403);
        }

        if (self::roleLevel($actorRole) <= self::roleLevel($targetRole)) {
            self::jsonError('Можно изменять только пользователей с более низкой ролью.', 403);
        }

        if (!array_key_exists('global_role', $data)) {
            return;
        }

        $newRole = (string) $data['global_role'];
        if (self::roleLevel($newRole) >= self::roleLevel($actorRole)) {
            self::jsonError('Нельзя назначать роль не ниже собственной.', 403);
        }
    }

    private static function assertHigherRole(array $actor, array $target, int $targetId): void
    {
        $actorId = (int) ($actor['id'] ?? 0);
        $actorRole = (string) ($actor['global_role'] ?? 'user');
        $targetRole = (string) ($target['global_role'] ?? 'user');

        if ($actorId === $targetId) {
            self::jsonError('Нельзя удалять или изменять собственную административную запись.', 403);
        }

        if (self::roleLevel($actorRole) <= self::roleLevel($targetRole)) {
            self::jsonError('Можно управлять только пользователями с более низкой ролью.', 403);
        }
    }

    private static function roleLevel(string $role): int
    {
        return self::GLOBAL_ROLE_LEVELS[$role] ?? 0;
    }
    private static function headRequest(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'HEAD',
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        @file_get_contents($url, false, $context);

        $headers = [];
        foreach (($http_response_header ?? []) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return $headers;
    }

    private static function jsonError(string $message, int $code = 400): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function jsonSuccess(array $data = []): never
    {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
