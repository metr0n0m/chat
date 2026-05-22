<?php
declare(strict_types=1);

namespace Chat\Admin;

use Chat\DB\Connection;
use Chat\Http\JsonResponse;
use Chat\Security\{Session, CSRF};
use Chat\Support\Timestamp;
use Chat\Validation\UsernameRules;

/**
 * Методы админ-панели верхнего уровня.
 * Last updated: 2026-04-17.
 */
class AdminPanel
{
    /**
     * Флаг разрешения глобальным админам менять custom status.
     * Last updated: 2026-04-17.
     */
    public static function isAdminStatusOverrideEnabled(): bool
    {
        $db = Connection::getInstance();
        $row = $db->fetchOne('SELECT value FROM app_settings WHERE name = ?', ['allow_admin_status_override']);
        return ((string) ($row['value'] ?? '0')) === '1';
    }

    /**
     * Проверяет доступ в админ-панель и возвращает текущего пользователя.
     * Last updated: 2026-04-17.
     */
    public static function requireAdmin(): array
    {
        $user = Session::current();
        if (!$user || !Access::canOpenAdminPanel($user)) {
            JsonResponse::error('Доступ запрещён.', 403);
        }
        return $user;
    }

    /**
     * Сводные метрики для dashboard.
     * Last updated: 2026-04-17.
     */
    public static function dashboard(): void
    {
        $db = Connection::getInstance();
        $stats = [
            'users_total' => (int) $db->fetchOne('SELECT COUNT(*) AS c FROM users')['c'],
            'messages_today' => (int) $db->fetchOne("SELECT COUNT(*) AS c FROM messages WHERE DATE(created_at) = CURDATE()")['c'],
            'rooms_active' => (int) $db->fetchOne("SELECT COUNT(*) AS c FROM rooms WHERE type = 'public' AND is_closed = 0")['c'],
            'numera_active' => (int) $db->fetchOne("SELECT COUNT(*) AS c FROM rooms WHERE type = 'numer' AND is_closed = 0")['c'],
        ];

        JsonResponse::success(['stats' => $stats]);
    }

    /**
     * Сводные метрики owner overview (только platform_owner).
     */
    public static function ownerOverview(): void
    {
        Access::requireOwnerOnly(Session::current());
        $db = Connection::getInstance();

        $stats = [
            'users_total'     => (int) $db->fetchOne('SELECT COUNT(*) AS c FROM users')['c'],
            'users_active_1h' => (int) $db->fetchOne('SELECT COUNT(*) AS c FROM users WHERE last_seen_at >= NOW() - INTERVAL 60 MINUTE')['c'],
            'rooms_total'     => (int) $db->fetchOne('SELECT COUNT(*) AS c FROM rooms')['c'],
            'rooms_public'    => (int) $db->fetchOne("SELECT COUNT(*) AS c FROM rooms WHERE type = 'public' AND is_closed = 0")['c'],
            'numera_active'   => (int) $db->fetchOne("SELECT COUNT(*) AS c FROM rooms WHERE type = 'numer' AND is_closed = 0")['c'],
            'whisper_messages'=> (int) $db->fetchOne("SELECT COUNT(*) AS c FROM messages WHERE type = 'whisper' AND is_deleted = 0")['c'],
            'whisper_pairs'   => (int) $db->fetchOne("SELECT COUNT(DISTINCT CONCAT(LEAST(user_id, whisper_to), '_', GREATEST(user_id, whisper_to))) AS c FROM messages WHERE type = 'whisper' AND is_deleted = 0")['c'],
            'messages_total'  => (int) $db->fetchOne('SELECT COUNT(*) AS c FROM messages WHERE is_deleted = 0')['c'],
            'messages_24h'    => (int) $db->fetchOne('SELECT COUNT(*) AS c FROM messages WHERE is_deleted = 0 AND created_at >= NOW() - INTERVAL 24 HOUR')['c'],
            'active_bans'     => (int) $db->fetchOne('SELECT COUNT(*) AS c FROM users WHERE is_banned = 1')['c'],
        ];

        JsonResponse::success(['stats' => $stats]);
    }

    /**
     * Список глобальных модераторов.
     * Last updated: 2026-04-17.
     */
    public static function globalModerators(): void
    {
        $db = Connection::getInstance();
        $mods = $db->fetchAll(
            "SELECT id, username, email, created_at FROM users WHERE global_role = 'moderator' ORDER BY username"
        );
        $mods = Timestamp::normalizeRows($mods, ['created_at']);
        JsonResponse::success(['moderators' => $mods]);
    }

    /**
     * Список пользователей с правом создания комнат.
     * Last updated: 2026-04-17.
     */
    public static function roomCreators(): void
    {
        $db = Connection::getInstance();
        $users = $db->fetchAll(
            'SELECT id, username, email, can_create_room FROM users WHERE can_create_room = 1 ORDER BY username'
        );
        JsonResponse::success(['users' => $users]);
    }

    /**
     * Возвращает настройку override custom status.
     * Last updated: 2026-04-17.
     */
    public static function statusOverrideSettings(): void
    {
        JsonResponse::success(['allow_admin_status_override' => self::isAdminStatusOverrideEnabled()]);
    }

    /**
     * Изменяет настройку override custom status (только platform_owner).
     * Last updated: 2026-04-17.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $post
     */
    public static function createUser(array $actor, array $post): never
    {
        if (($actor['global_role'] ?? '') !== 'platform_owner') {
            JsonResponse::error('Только владелец платформы может создавать пользователей.', 403);
        }

        if (!\Chat\Security\CSRF::verifyRequest()) {
            JsonResponse::error('CSRF.', 403);
        }

        $username = trim((string) ($post['username'] ?? ''));
        $email    = trim((string) ($post['email'] ?? ''));
        $password = (string) ($post['password'] ?? '');
        $role     = (string) ($post['global_role'] ?? 'user');

        $usernameError = UsernameRules::validate($username);
        if ($usernameError !== null) {
            JsonResponse::error($usernameError, 400);
        }

        if (strlen($password) < 8) {
            JsonResponse::error('Пароль должен быть не менее 8 символов.', 400);
        }

        if (!in_array($role, ['user', 'moderator', 'admin'], true)) {
            $role = 'user';
        }

        $db = Connection::getInstance();

        $exists = $db->fetchOne('SELECT id FROM users WHERE username = ?', [$username]);
        if ($exists) {
            JsonResponse::error('Имя уже занято.', 409);
        }

        if ($email !== '') {
            $emailExists = $db->fetchOne('SELECT id FROM users WHERE email = ?', [$email]);
            if ($emailExists) {
                JsonResponse::error('Email уже используется.', 409);
            }
        }

        $hash = password_hash($password, PASSWORD_ARGON2ID);
        $db->execute(
            'INSERT INTO users (username, email, password_hash, global_role) VALUES (?, ?, ?, ?)',
            [$username, $email !== '' ? $email : null, $hash, $role]
        );

        JsonResponse::success(['user_id' => (int) $db->lastInsertId()]);
    }

    private static function requireOwner(): array
    {
        $actor = Session::current();
        if (!$actor || !Access::canOpenOwnerPanel($actor)) {
            JsonResponse::error('Только владелец платформы.', 403);
        }
        return $actor;
    }

    public static function getSystemSettings(): void
    {
        self::requireOwner();
        $db = Connection::getInstance();
        $rows = $db->fetchAll('SELECT name, value FROM app_settings');
        $s = [];
        foreach ($rows as $r) {
            $s[$r['name']] = $r['value'];
        }
        $defaults = [
            'datetime_format'      => 'DD.MM.YY HH:mm',
            'time_format'          => 'HH:mm',
            'registration_enabled' => '1',
            'maintenance_mode'     => '0',
            'maintenance_message'  => '',
            'maintenance_until'    => '',
            'site_description'     => '',
            'site_keywords'        => '',
            'system_theme'               => 'auto',
            'system_message_color'       => '#DEC8A4',
            'system_message_color_light' => '#7a6a4a',
            'system_message_color_dark'  => '#DEC8A4',
        ];
        foreach ($defaults as $k => $v) {
            if (!array_key_exists($k, $s)) {
                $s[$k] = $v;
            }
        }
        JsonResponse::success(['settings' => $s]);
    }

    public static function updateSystemSettings(array $actor, array $post): void
    {
        if (($actor['global_role'] ?? '') !== 'platform_owner') {
            JsonResponse::error('Только владелец платформы.', 403);
        }
        if (!CSRF::verifyRequest()) {
            JsonResponse::error('CSRF.', 403);
        }
        $allowed = [
            'datetime_format', 'time_format', 'registration_enabled', 'maintenance_mode',
            'maintenance_message', 'maintenance_until', 'site_description',
            'site_keywords', 'system_theme', 'system_message_color',
            'system_message_color_light', 'system_message_color_dark',
        ];
        $db = Connection::getInstance();
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $post)) {
                continue;
            }
            $value = (string) $post[$key];
            if (in_array($key, ['system_message_color', 'system_message_color_light', 'system_message_color_dark'], true)
                && !preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                continue;
            }
            $db->execute(
                'INSERT INTO app_settings (name, value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)',
                [$key, $value]
            );
        }
        JsonResponse::success();
    }

    public static function updateStatusOverrideSettings(array $actor, array $post): void
    {
        if (($actor['global_role'] ?? 'user') !== 'platform_owner') {
            JsonResponse::error('Недостаточно прав.', 403);
        }

        $enabled = (int) (!empty($post['allow_admin_status_override']));
        $db = Connection::getInstance();
        $db->execute(
            'INSERT INTO app_settings (name, value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['allow_admin_status_override', (string) $enabled]
        );

        JsonResponse::success(['allow_admin_status_override' => $enabled === 1]);
    }
}
