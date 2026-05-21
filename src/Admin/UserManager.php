<?php
declare(strict_types=1);

namespace Chat\Admin;

use Chat\DB\Connection;
use Chat\Http\JsonResponse;
use Chat\Security\CSRF;
use Chat\Security\Session;
use Chat\Support\Timestamp;
use Chat\Validation\UsernameRules;

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
        $users = Timestamp::normalizeRows($users, ['created_at', 'last_seen_at']);

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'users' => $users, 'total' => $total, 'page' => $page], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function update(int $targetId, array $data): void
    {
        if (!CSRF::verifyRequest()) {
            JsonResponse::error('CSRF.', 403);
        }

        $db = Connection::getInstance();
        $actor = Session::current();
        $target = $db->fetchOne('SELECT id, global_role FROM users WHERE id = ?', [$targetId]);

        if (!$target) {
            JsonResponse::error('Пользователь не найден.', 404);
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
            JsonResponse::error('Недостаточно прав для изменения отображаемого статуса.', 403);
        }

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];

            if ($field === 'global_role') {
                if (!in_array($value, ['platform_owner', 'admin', 'moderator', 'user'], true)) {
                    JsonResponse::error('Недопустимая роль.');
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
            JsonResponse::error('Нет данных для обновления.');
        }

        $params[] = $targetId;
        $db->execute('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?', $params);

        JsonResponse::success(['updated' => true]);
    }

    public static function delete(int $targetId): void
    {
        if (!CSRF::verifyRequest()) {
            JsonResponse::error('CSRF.', 403);
        }

        $db = Connection::getInstance();
        $actor = Session::current();
        $target = $db->fetchOne('SELECT id, global_role FROM users WHERE id = ?', [$targetId]);

        if (!$target) {
            JsonResponse::error('Пользователь не найден.', 404);
        }

        self::assertHigherRole($actor, $target, $targetId);

        try {
            $db->execute('DELETE FROM users WHERE id = ?', [$targetId]);
        } catch (\PDOException $e) {
            error_log('User delete id=' . $targetId . ': ' . $e->getMessage());
            JsonResponse::error('Ошибка при удалении пользователя.', 500);
        }
        JsonResponse::success(['deleted' => true]);
    }

    public static function profile(int $userId): void
    {
        $db = Connection::getInstance();

        $select = [
            'id', 'username', 'nickname', 'email', 'avatar_url', 'custom_status', 'nick_color', 'text_color',
            'global_role', 'can_create_room', 'is_banned', 'created_at', 'last_seen_at',
        ];
        foreach (['bio', 'social_telegram', 'social_whatsapp', 'social_vk', 'hide_last_seen', 'show_system_messages'] as $optional) {
            if (self::hasUsersColumn($db, $optional)) {
                $select[] = $optional;
            }
        }

        $user = $db->fetchOne(
            'SELECT ' . implode(', ', $select) . ' FROM users WHERE id = ?',
            [$userId]
        );

        if (!$user) {
            JsonResponse::error('Пользователь не найден.', 404);
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
        $user = Timestamp::normalizeFields($user, ['created_at', 'last_seen_at']);

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'user' => $user], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function updateSettings(int $userId, array $post, array $files): void
    {
        if (!CSRF::verifyRequest()) {
            JsonResponse::error('CSRF.', 403);
        }

        $db = Connection::getInstance();
        $currentUser = $db->fetchOne(
            'SELECT username, nick_color, text_color, avatar_url, custom_status
             FROM users
             WHERE id = ?',
            [$userId]
        );

        if (!$currentUser) {
            JsonResponse::error('Пользователь не найден.', 404);
        }

        $set = [];
        $params = [];

        if (isset($post['username'])) {
            $username = trim((string) $post['username']);
            $usernameError = UsernameRules::validate($username);
            if ($usernameError !== null) {
                JsonResponse::error($usernameError);
            }
            $exists = $db->fetchOne('SELECT id FROM users WHERE username = ? AND id != ?', [$username, $userId]);
            if ($exists) {
                JsonResponse::error('Имя уже занято.');
            }
            $set[] = 'username = ?';
            $params[] = $username;
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

        if (self::hasUsersColumn($db, 'show_system_messages') && array_key_exists('show_system_messages', $post)) {
            $set[] = 'show_system_messages = ?';
            $params[] = (int)(bool)$post['show_system_messages'];
        }

        if (array_key_exists('custom_status', $post)) {
            $customStatus = trim(strip_tags((string) $post['custom_status']));
            $customStatus = mb_substr($customStatus, 0, 80);
            $set[] = 'custom_status = ?';
            $params[] = $customStatus === '' ? null : $customStatus;
        }

        if (isset($post['nick_color'])) {
            $nickColor = strtolower(trim((string) $post['nick_color']));
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $nickColor)) {
                JsonResponse::error('Недопустимый формат цвета.');
            }
            $set[] = 'nick_color = ?';
            $params[] = $nickColor;
        }

        if (isset($post['text_color'])) {
            $textColor = strtolower(trim((string) $post['text_color']));
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $textColor)) {
                JsonResponse::error('Недопустимый формат цвета.');
            }
            $set[] = 'text_color = ?';
            $params[] = $textColor;
        }

        if (isset($post['password']) && $post['password'] !== '') {
            $password = (string) $post['password'];
            if (strlen($password) < 8) {
                JsonResponse::error('Пароль должен быть не короче 8 символов.');
            }
            $set[] = 'password_hash = ?';
            $params[] = password_hash($password, PASSWORD_ARGON2ID);
        }

        if (!empty($post['avatar_url'])) {
            $url = trim((string) $post['avatar_url']);
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                JsonResponse::error('Некорректный URL аватара.');
            }

            $headers = self::headRequest($url);
            $mime = strtolower(explode(';', $headers['content-type'] ?? '')[0]);
            $size = (int) ($headers['content-length'] ?? 0);

            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                JsonResponse::error('URL не указывает на изображение.');
            }
            if ($size > 5 * 1024 * 1024) {
                JsonResponse::error('Изображение слишком большое (>5MB).');
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
            JsonResponse::error('Нет данных для обновления.');
        }

        $params[] = $userId;
        $db->execute('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?', $params);

        $select = [
            'id', 'username', 'email', 'avatar_url', 'custom_status', 'nick_color', 'text_color',
            'global_role', 'can_create_room', 'is_banned', 'created_at', 'last_seen_at',
        ];
        foreach (['bio', 'social_telegram', 'social_whatsapp', 'social_vk', 'hide_last_seen', 'show_system_messages'] as $optional) {
            if (self::hasUsersColumn($db, $optional)) {
                $select[] = $optional;
            }
        }

        $updated = $db->fetchOne(
            'SELECT ' . implode(', ', $select) . ' FROM users WHERE id = ?',
            [$userId]
        );
        $updated = Timestamp::normalizeFields($updated, ['created_at', 'last_seen_at']);

        JsonResponse::success(['updated' => true, 'user' => $updated]);
    }

    private static function uploadAvatar(int $userId, array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            JsonResponse::error('Ошибка загрузки файла.');
        }
        if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
            JsonResponse::error('Файл слишком большой (>2MB).');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($file['tmp_name']);
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        if (!in_array($mime, $allowed, true)) {
            JsonResponse::error('Недопустимый тип файла. Разрешены JPEG, PNG, GIF, WEBP.');
        }

        $image = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
            'image/png' => imagecreatefrompng($file['tmp_name']),
            'image/gif' => imagecreatefromgif($file['tmp_name']),
            'image/webp' => imagecreatefromwebp($file['tmp_name']),
        };

        if (!$image) {
            JsonResponse::error('Не удалось обработать изображение.');
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
            JsonResponse::error('Нельзя менять собственные административные права через админку.', 403);
        }

        if (self::roleLevel($actorRole) <= self::roleLevel($targetRole)) {
            JsonResponse::error('Можно изменять только пользователей с более низкой ролью.', 403);
        }

        if (!array_key_exists('global_role', $data)) {
            return;
        }

        $newRole = (string) $data['global_role'];
        if (self::roleLevel($newRole) >= self::roleLevel($actorRole)) {
            JsonResponse::error('Нельзя назначать роль не ниже собственной.', 403);
        }
    }

    private static function assertHigherRole(array $actor, array $target, int $targetId): void
    {
        $actorId = (int) ($actor['id'] ?? 0);
        $actorRole = (string) ($actor['global_role'] ?? 'user');
        $targetRole = (string) ($target['global_role'] ?? 'user');

        if ($actorId === $targetId) {
            JsonResponse::error('Нельзя удалять или изменять собственную административную запись.', 403);
        }

        if (self::roleLevel($actorRole) <= self::roleLevel($targetRole)) {
            JsonResponse::error('Можно управлять только пользователями с более низкой ролью.', 403);
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
        $parsed = parse_url($url);
        if (!$parsed || !in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
            return [];
        }

        $host = strtolower((string) ($parsed['host'] ?? ''));
        if ($host === '') {
            return [];
        }

        // Block loopback, link-local, and RFC-1918 private ranges
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : (gethostbyname($host) ?: '');
        if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return [];
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'HEAD',
                'timeout'       => 5,
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
            "SELECT u.id AS user_id, u.username, u.email, u.global_role,
                    u.banned_at, u.banned_until, u.ban_reason,
                    a.username AS banned_by_name,
                    NULL AS room_id, NULL AS room_name,
                    NULL AS room_role, NULL AS muted_until,
                    'global' AS ban_scope
             FROM users u
             LEFT JOIN users a ON a.id = u.banned_by
             WHERE u.is_banned = 1
             ORDER BY u.banned_at DESC"
        );

        $room = $db->fetchAll(
            "SELECT u.id AS user_id, u.username, u.email, u.global_role,
                    rm.room_role, rm.banned_at, NULL AS banned_until, rm.ban_reason,
                    NULL AS muted_until,
                    a.username AS banned_by_name,
                    r.id AS room_id, r.name AS room_name,
                    'room' AS ban_scope
             FROM room_members rm
             JOIN users u ON u.id = rm.user_id
             JOIN rooms r ON r.id = rm.room_id
             LEFT JOIN users a ON a.id = rm.banned_by
             WHERE rm.room_role = 'banned'
             ORDER BY rm.banned_at DESC"
        );

        $mutes = $db->fetchAll(
            "SELECT u.id AS user_id, u.username, u.email, u.global_role,
                    rm.room_role, NULL AS banned_at, NULL AS banned_until, rm.mute_reason AS ban_reason,
                    rm.muted_until,
                    NULL AS banned_by_name,
                    r.id AS room_id, r.name AS room_name,
                    'mute' AS ban_scope
             FROM room_members rm
             JOIN users u ON u.id = rm.user_id
             JOIN rooms r ON r.id = rm.room_id
             WHERE rm.muted_until IS NOT NULL AND rm.muted_until > NOW()
             ORDER BY rm.muted_until DESC"
        );
        $global = Timestamp::normalizeRows($global, ['banned_at', 'banned_until']);
        $room   = Timestamp::normalizeRows($room,   ['banned_at', 'banned_until', 'muted_until']);
        $mutes  = Timestamp::normalizeRows($mutes,  ['muted_until']);
        $bans   = array_merge($global, $room, $mutes);

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => true,
            'bans'    => $bans,    // unified contract: all scopes
            'global'  => $global,  // deprecated — backward compat
            'room'    => $room,    // deprecated — backward compat
            'mutes'   => $mutes,   // deprecated — backward compat
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function roomUnban(int $roomId, int $userId): void
    {
        if (!CSRF::verifyRequest()) {
            JsonResponse::error('CSRF.', 403);
        }
        $db = Connection::getInstance();
        $db->execute(
            "DELETE FROM room_members WHERE room_id = ? AND user_id = ? AND room_role = 'banned'",
            [$roomId, $userId]
        );
        JsonResponse::success();
    }

    public static function roomUnmute(int $roomId, int $userId): void
    {
        if (!CSRF::verifyRequest()) {
            JsonResponse::error('CSRF.', 403);
        }
        $db = Connection::getInstance();
        $db->execute(
            'UPDATE room_members SET muted_until = NULL, mute_reason = NULL WHERE room_id = ? AND user_id = ?',
            [$roomId, $userId]
        );
        JsonResponse::success();
    }
}
