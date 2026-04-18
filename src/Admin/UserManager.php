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

    /** @var array<string,true>|null */
    private static ?array $usersColumnCache = null;
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
            'SELECT id, username, email, global_role, custom_status, can_create_room, is_banned, created_at, last_seen_at
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

        $allowed = ['global_role', 'is_banned', 'can_create_room', 'custom_status'];
        $set = [];
        $params = [];
        $onlyCustomStatusUpdate = true;

        foreach (['global_role', 'is_banned', 'can_create_room'] as $privilegedField) {
            if (array_key_exists($privilegedField, $data)) {
                $onlyCustomStatusUpdate = false;
                break;
            }
        }

        if (!$onlyCustomStatusUpdate) {
            self::assertRoleManagementAllowed($actor, $target, $targetId, $data);
        }

        if (array_key_exists('custom_status', $data) && !self::canEditCustomStatus($actor)) {
            self::jsonError('Недостаточно прав для изменения отображаемого статуса.', 403);
        }

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];

            if ($field === 'global_role') {
                if (!in_array($value, ['platform_owner', 'admin', 'moderator', 'user'], true)) {
                    self::jsonError('Недопустимая роль.');
                }
            } elseif ($field === 'custom_status') {
                $value = trim(strip_tags((string) $value));
                $value = mb_substr($value, 0, 80);
                $value = $value === '' ? null : $value;
            } else {
                $value = (int) (bool) $value;
            }

            $set[] = "`$field` = ?";
            $params[] = $value;

            // When banning: record metadata
            if ($field === 'is_banned' && $value === 1) {
                $actorId = $actor ? (int) $actor['id'] : null;
                $reason  = trim((string) ($data['ban_reason'] ?? ''));
                $hours   = (int) ($data['ban_hours'] ?? 0);
                $set[]   = 'banned_at = NOW()';
                $set[]   = 'banned_by = ?';
                $params[] = $actorId;
                $set[]   = 'ban_reason = ?';
                $params[] = $reason !== '' ? $reason : null;
                $set[]   = 'banned_until = ?';
                $params[] = $hours > 0 ? date('Y-m-d H:i:s', time() + $hours * 3600) : null;
            }
            // When unbanning: clear metadata fields
            if ($field === 'is_banned' && $value === 0) {
                $set[] = 'banned_at = NULL';
                $set[] = 'banned_by = NULL';
                $set[] = 'banned_until = NULL';
                $set[] = 'ban_reason = NULL';
            }
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

        $select = [
            'id', 'username', 'email', 'avatar_url', 'signature', 'custom_status', 'nick_color', 'text_color',
            'global_role', 'can_create_room', 'is_banned', 'created_at', 'last_seen_at',
        ];
        foreach (['bio', 'social_telegram', 'social_whatsapp', 'social_vk', 'hide_last_seen'] as $optional) {
            if (self::hasUsersColumn($db, $optional)) {
                $select[] = $optional;
            }
        }

        $user = $db->fetchOne(
            'SELECT ' . implode(', ', $select) . ' FROM users WHERE id = ?',
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

        $user['bio'] = (string)($user['bio'] ?? '');
        $user['social_telegram'] = (string)($user['social_telegram'] ?? '');
        $user['social_whatsapp'] = (string)($user['social_whatsapp'] ?? '');
        $user['social_vk'] = (string)($user['social_vk'] ?? '');
        $user['hide_last_seen'] = (int)($user['hide_last_seen'] ?? 0);

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
            'SELECT username, nick_color, text_color, avatar_url, signature, custom_status
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

        if (self::hasUsersColumn($db, 'bio') && array_key_exists('bio', $post)) {
            $bio = mb_substr(trim((string)$post['bio']), 0, 500);
            $set[] = 'bio = ?';
            $params[] = $bio === '' ? null : $bio;
        }

        if (self::hasUsersColumn($db, 'social_telegram') && array_key_exists('social_telegram', $post)) {
            $value = mb_substr(trim((string)$post['social_telegram']), 0, 255);
            $set[] = 'social_telegram = ?';
            $params[] = $value === '' ? null : $value;
        }

        if (self::hasUsersColumn($db, 'social_whatsapp') && array_key_exists('social_whatsapp', $post)) {
            $value = mb_substr(trim((string)$post['social_whatsapp']), 0, 255);
            $set[] = 'social_whatsapp = ?';
            $params[] = $value === '' ? null : $value;
        }

        if (self::hasUsersColumn($db, 'social_vk') && array_key_exists('social_vk', $post)) {
            $value = mb_substr(trim((string)$post['social_vk']), 0, 255);
            $set[] = 'social_vk = ?';
            $params[] = $value === '' ? null : $value;
        }

        if (self::hasUsersColumn($db, 'hide_last_seen') && array_key_exists('hide_last_seen', $post)) {
            $set[] = 'hide_last_seen = ?';
            $params[] = (int)(bool)$post['hide_last_seen'];
        }

        if (array_key_exists('custom_status', $post)) {
            $customStatus = trim(strip_tags((string) $post['custom_status']));
            $customStatus = mb_substr($customStatus, 0, 80);
            $set[] = 'custom_status = ?';
            $params[] = $customStatus === '' ? null : $customStatus;
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

        $select = [
            'id', 'username', 'email', 'avatar_url', 'signature', 'custom_status', 'nick_color', 'text_color',
            'global_role', 'can_create_room', 'is_banned', 'created_at', 'last_seen_at',
        ];
        foreach (['bio', 'social_telegram', 'social_whatsapp', 'social_vk', 'hide_last_seen'] as $optional) {
            if (self::hasUsersColumn($db, $optional)) {
                $select[] = $optional;
            }
        }

        $updated = $db->fetchOne(
            'SELECT ' . implode(', ', $select) . ' FROM users WHERE id = ?',
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

    private static function canEditCustomStatus(array $actor): bool
    {
        $role = (string) ($actor['global_role'] ?? 'user');
        if ($role === 'platform_owner') {
            return true;
        }
        if ($role !== 'admin') {
            return false;
        }
        return AdminPanel::isAdminStatusOverrideEnabled();
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

    private static function hasUsersColumn(Connection $db, string $column): bool
    {
        if (self::$usersColumnCache === null) {
            self::$usersColumnCache = [];
            $rows = $db->fetchAll('SHOW COLUMNS FROM users');
            foreach ($rows as $row) {
                $name = (string)($row['Field'] ?? '');
                if ($name !== '') {
                    self::$usersColumnCache[$name] = true;
                }
            }
        }
        return isset(self::$usersColumnCache[$column]);
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

    public static function listBanned(): void
    {
        $db = Connection::getInstance();

        // Auto-expire time-limited global bans
        $db->execute(
            "UPDATE users SET is_banned = 0, banned_at = NULL, banned_by = NULL, banned_until = NULL, ban_reason = NULL
             WHERE is_banned = 1 AND banned_until IS NOT NULL AND banned_until <= NOW()"
        );
        // Auto-clear expired mutes
        $db->execute(
            "UPDATE room_members SET muted_until = NULL, mute_reason = NULL
             WHERE muted_until IS NOT NULL AND muted_until <= NOW()"
        );

        $global = $db->fetchAll(
            "SELECT u.id, u.username, u.email, u.global_role,
                    u.banned_at, u.banned_until, u.ban_reason,
                    a.username AS banned_by_name,
                    NULL AS room_id, NULL AS room_name,
                    'global' AS ban_type
             FROM users u
             LEFT JOIN users a ON a.id = u.banned_by
             WHERE u.is_banned = 1
             ORDER BY u.banned_at DESC"
        );

        $room = $db->fetchAll(
            "SELECT u.id, u.username, u.email, u.global_role,
                    rm.banned_at, NULL AS banned_until, rm.ban_reason,
                    a.username AS banned_by_name,
                    r.id AS room_id, r.name AS room_name,
                    'room' AS ban_type
             FROM room_members rm
             JOIN users u ON u.id = rm.user_id
             JOIN rooms r ON r.id = rm.room_id
             LEFT JOIN users a ON a.id = rm.banned_by
             WHERE rm.room_role = 'banned'
             ORDER BY rm.banned_at DESC"
        );

        $mutes = $db->fetchAll(
            "SELECT u.id, u.username, u.email, u.global_role,
                    rm.muted_until AS banned_until, rm.mute_reason AS ban_reason,
                    NULL AS banned_at, NULL AS banned_by_name,
                    r.id AS room_id, r.name AS room_name,
                    'mute' AS ban_type
             FROM room_members rm
             JOIN users u ON u.id = rm.user_id
             JOIN rooms r ON r.id = rm.room_id
             WHERE rm.muted_until IS NOT NULL AND rm.muted_until > NOW()
             ORDER BY rm.muted_until DESC"
        );

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'global' => $global, 'room' => $room, 'mutes' => $mutes], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function roomUnban(int $roomId, int $userId): void
    {
        if (!CSRF::verifyRequest()) {
            self::jsonError('CSRF.', 403);
        }
        $db = Connection::getInstance();
        $db->execute(
            "DELETE FROM room_members WHERE room_id = ? AND user_id = ? AND room_role = 'banned'",
            [$roomId, $userId]
        );
        self::jsonSuccess();
    }

    public static function roomUnmute(int $roomId, int $userId): void
    {
        if (!CSRF::verifyRequest()) {
            self::jsonError('CSRF.', 403);
        }
        $db = Connection::getInstance();
        $db->execute(
            'UPDATE room_members SET muted_until = NULL, mute_reason = NULL WHERE room_id = ? AND user_id = ?',
            [$roomId, $userId]
        );
        self::jsonSuccess();
    }
}
