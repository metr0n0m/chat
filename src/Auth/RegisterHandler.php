<?php
declare(strict_types=1);

namespace Chat\Auth;

use Chat\DB\Connection;
use Chat\Security\{Session, CSRF};

class RegisterHandler
{
    public static function handle(): void
    {
        if (!CSRF::verifyRequest()) {
            self::jsonError('Неверный CSRF токен.', 403);
        }

        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        $error = self::validate($username, $email, $password);
        if ($error) {
            self::jsonError($error);
        }

        $db = Connection::getInstance();

        if ($db->fetchOne('SELECT id FROM users WHERE username = ?', [$username])) {
            self::jsonError('Это имя пользователя уже занято.');
        }
        if ($email && $db->fetchOne('SELECT id FROM users WHERE email = ?', [$email])) {
            self::jsonError('Этот email уже зарегистрирован.');
        }

        $hash = password_hash($password, PASSWORD_ARGON2ID);
        $db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            [$username, $email ?: null, $hash]
        );
        $userId = (int) $db->lastInsertId();

        $ip    = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua    = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $token = Session::create($userId, $ip, $ua);
        Session::setCookie($token);

        self::joinDefaultRooms($db, $userId);

        self::jsonSuccess(['redirect' => '/']);
    }

    private static function validate(string $username, string $email, string $password): ?string
    {
        if (strlen($username) < 3 || strlen($username) > 50) {
            return 'Имя пользователя: от 3 до 50 символов.';
        }
        if (!preg_match('/^[\w\p{Cyrillic}]+$/u', $username)) {
            return 'Имя пользователя: только буквы, цифры и подчёркивание.';
        }
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Некорректный email.';
        }
        if (strlen($password) < 8) {
            return 'Пароль должен содержать не менее 8 символов.';
        }
        return null;
    }

    private static function joinDefaultRooms(Connection $db, int $userId): void
    {
        $rooms = $db->fetchAll(
            "SELECT id FROM rooms WHERE type = 'public' AND is_closed = 0 ORDER BY id LIMIT 5"
        );
        foreach ($rooms as $room) {
            $db->execute(
                'INSERT IGNORE INTO room_members (room_id, user_id, room_role) VALUES (?, ?, ?)',
                [$room['id'], $userId, 'member']
            );
        }
    }

    private static function jsonError(string $message, int $code = 400): never
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }

    private static function jsonSuccess(array $data = []): never
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => true] + $data);
        exit;
    }
}
