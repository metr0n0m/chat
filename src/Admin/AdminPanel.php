<?php
declare(strict_types=1);

namespace Chat\Admin;

use Chat\DB\Connection;
use Chat\Security\Session;

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
