<?php
declare(strict_types=1);

namespace Chat\Auth;

use Chat\DB\Connection;
use Chat\Security\{Session, CSRF};

/**
 * Обработчик регистрации пользователя.
 * Last updated: 2026-04-17.
 */
class RegisterHandler
{
    /**
     * Выполняет регистрацию и создает сессию.
     * Last updated: 2026-04-17.
     */
    public static function handle(): void
    {
        if (!CSRF::verifyRequest()) {
            self::jsonError('Неверный CSRF токен.', 403);
        }

        $db = Connection::getInstance();
        $regEnabled = $db->fetchOne('SELECT value FROM app_settings WHERE name = ?', ['registration_enabled']);
        if (($regEnabled['value'] ?? '1') === '0') {
            self::jsonError('Регистрация временно закрыта.', 403);
        }

        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $error = self::validate($username, $email, $password);
        if ($error !== null) {
            self::jsonError($error);
        }

        $db = Connection::getInstance();

        if ($db->fetchOne('SELECT id FROM users WHERE username = ?', [$username])) {
            self::jsonError('Это имя пользователя уже занято.');
        }
        if ($email !== '' && $db->fetchOne('SELECT id FROM users WHERE email = ?', [$email])) {
            self::jsonError('Этот email уже зарегистрирован.');
        }

        $hash = password_hash($password, PASSWORD_ARGON2ID);
        $db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            [$username, $email !== '' ? $email : null, $hash]
        );
        $userId = (int) $db->lastInsertId();

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $token = Session::create($userId, $ip, $ua);
        Session::setCookie($token);

        self::joinDefaultRooms($db, $userId);
        self::jsonSuccess(['redirect' => '/']);
    }

    /**
     * Валидирует поля регистрации.
     * Last updated: 2026-04-17.
     */
    private static function validate(string $username, string $email, string $password): ?string
    {
        if (mb_strlen($username) < 3 || mb_strlen($username) > 50) {
            return 'Имя пользователя: от 3 до 50 символов.';
        }
        if (!preg_match('/^[\w\p{Cyrillic}]+$/u', $username)) {
            return 'Имя пользователя: только буквы, цифры и подчеркивание.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Некорректный email.';
        }
        if (mb_strlen($password) < 8) {
            return 'Пароль должен содержать не менее 8 символов.';
        }
        return null;
    }

    /**
     * Добавляет пользователя в первые публичные комнаты.
     * Last updated: 2026-04-17.
     */
    private static function joinDefaultRooms(Connection $db, int $userId): void
    {
        $rooms = $db->fetchAll(
            "SELECT id FROM rooms WHERE type = 'public' AND is_closed = 0 ORDER BY id LIMIT 5"
        );
        foreach ($rooms as $room) {
            $db->execute(
                'INSERT IGNORE INTO room_members (room_id, user_id, room_role) VALUES (?, ?, ?)',
                [(int) $room['id'], $userId, 'member']
            );
        }
    }

    /**
     * Возвращает JSON-ошибку.
     * Last updated: 2026-04-17.
     */
    private static function jsonError(string $message, int $code = 400): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Возвращает JSON-успех.
     * Last updated: 2026-04-17.
     *
     * @param array<string, mixed> $data
     */
    private static function jsonSuccess(array $data = []): never
    {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
