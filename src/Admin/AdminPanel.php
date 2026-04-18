<?php
declare(strict_types=1);

namespace Chat\Admin;

use Chat\DB\Connection;
use Chat\Security\{Session, CSRF};

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
        if (!$user || !in_array($user['global_role'], ['platform_owner', 'admin'], true)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'Доступ запрещён.'], JSON_UNESCAPED_UNICODE);
            exit;
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

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'stats' => $stats], JSON_UNESCAPED_UNICODE);
        exit;
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
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'moderators' => $mods], JSON_UNESCAPED_UNICODE);
        exit;
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
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'users' => $users], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Возвращает настройку override custom status.
     * Last updated: 2026-04-17.
     */
    public static function statusOverrideSettings(): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => true,
            'allow_admin_status_override' => self::isAdminStatusOverrideEnabled(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
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
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'Только владелец платформы может создавать пользователей.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!\Chat\Security\CSRF::verifyRequest()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'CSRF.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $username = trim((string) ($post['username'] ?? ''));
        $email    = trim((string) ($post['email'] ?? ''));
        $password = (string) ($post['password'] ?? '');
        $role     = (string) ($post['global_role'] ?? 'user');

        if (mb_strlen($username) < 3 || mb_strlen($username) > 50 || !preg_match('/^[\w\p{Cyrillic}]+$/u', $username)) {
            http_response_code(400);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'Некорректное имя пользователя.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (strlen($password) < 8) {
            http_response_code(400);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'Пароль должен быть не менее 8 символов.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!in_array($role, ['user', 'moderator', 'admin'], true)) {
            $role = 'user';
        }

        $db = Connection::getInstance();

        $exists = $db->fetchOne('SELECT id FROM users WHERE username = ?', [$username]);
        if ($exists) {
            http_response_code(409);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'Имя уже занято.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($email !== '') {
            $emailExists = $db->fetchOne('SELECT id FROM users WHERE email = ?', [$email]);
            if ($emailExists) {
                http_response_code(409);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['success' => false, 'error' => 'Email уже используется.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        $hash = password_hash($password, PASSWORD_ARGON2ID);
        $db->execute(
            'INSERT INTO users (username, email, password_hash, global_role) VALUES (?, ?, ?, ?)',
            [$username, $email !== '' ? $email : null, $hash, $role]
        );

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'user_id' => (int) $db->lastInsertId()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function requireOwner(): array
    {
        $actor = Session::current();
        if (!$actor || ($actor['global_role'] ?? '') !== 'platform_owner') {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'Только владелец платформы.'], JSON_UNESCAPED_UNICODE);
            exit;
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
            'system_theme'         => 'auto',
            'system_message_color' => '#DEC8A4',
        ];
        foreach ($defaults as $k => $v) {
            if (!array_key_exists($k, $s)) {
                $s[$k] = $v;
            }
        }
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'settings' => $s], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function updateSystemSettings(array $actor, array $post): void
    {
        if (($actor['global_role'] ?? '') !== 'platform_owner') {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'Только владелец платформы.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!CSRF::verifyRequest()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'CSRF.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $allowed = [
            'datetime_format', 'time_format', 'registration_enabled', 'maintenance_mode',
            'maintenance_message', 'maintenance_until', 'site_description',
            'site_keywords', 'system_theme', 'system_message_color',
        ];
        $db = Connection::getInstance();
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $post)) {
                continue;
            }
            $value = (string) $post[$key];
            if ($key === 'system_message_color' && !preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                continue;
            }
            $db->execute(
                'INSERT INTO app_settings (name, value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)',
                [$key, $value]
            );
        }
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function updateStatusOverrideSettings(array $actor, array $post): void
    {
        if (($actor['global_role'] ?? 'user') !== 'platform_owner') {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $enabled = (int) (!empty($post['allow_admin_status_override']));
        $db = Connection::getInstance();
        $db->execute(
            'INSERT INTO app_settings (name, value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['allow_admin_status_override', (string) $enabled]
        );

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => true,
            'allow_admin_status_override' => $enabled === 1,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
