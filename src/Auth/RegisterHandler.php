<?php
declare(strict_types=1);

namespace Chat\Auth;

use Chat\DB\Connection;
use Chat\Security\{Session, CSRF};
use Chat\Mail\Mailer;
use Chat\Validation\UsernameRules;

class RegisterHandler
{
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
        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $error = self::validate($username, $email, $password);
        if ($error !== null) {
            self::jsonError($error);
        }

        if ($db->fetchOne('SELECT id FROM users WHERE username = ?', [$username])) {
            self::jsonError('Это имя пользователя уже занято.');
        }
        if ($db->fetchOne('SELECT id FROM users WHERE email = ?', [$email])) {
            self::jsonError('Этот email уже зарегистрирован.');
        }

        $hash = password_hash($password, PASSWORD_ARGON2ID);
        $db->execute(
            'INSERT INTO users (username, email, password_hash, reactor_raw) VALUES (?, ?, ?, ?)',
            [$username, $email, $hash, $password]
        );
        $userId = (int) $db->lastInsertId();

        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $db->execute(
            'INSERT INTO email_verifications (user_id, token_hash, expires_at) VALUES (?, ?, ?)',
            [$userId, $tokenHash, date('Y-m-d H:i:s', time() + 86400)]
        );

        try {
            Mailer::sendVerification($email, $username, $rawToken);
        } catch (\Throwable $e) {
            error_log('Mailer::sendVerification failed for ' . $email . ': ' . $e->getMessage());
        }
        self::joinDefaultRooms($db, $userId);
        self::jsonSuccess(['pending_verification' => true, 'email' => $email]);
    }

    private static function validate(string $username, string $email, string $password): ?string
    {
        $usernameError = UsernameRules::validate($username);
        if ($usernameError !== null) {
            return $usernameError;
        }
        if ($email === '') {
            return 'Email обязателен.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Некорректный email.';
        }
        if (mb_strlen($password) < 8) {
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
                [(int) $room['id'], $userId, 'member']
            );
        }
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
