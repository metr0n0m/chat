<?php
declare(strict_types=1);

namespace Chat\Auth;

use Chat\Chat\DefaultRoomMembership;
use Chat\DB\Connection;
use Chat\Http\JsonResponse;
use Chat\Security\{Session, CSRF};
use Chat\Mail\Mailer;
use Chat\Validation\UsernameRules;

class RegisterHandler
{
    public static function handle(): void
    {
        if (!CSRF::verifyRequest()) {
            JsonResponse::error('Неверный CSRF токен.', 403);
        }

        $db = Connection::getInstance();
        $regEnabled = $db->fetchOne('SELECT value FROM app_settings WHERE name = ?', ['registration_enabled']);
        if (($regEnabled['value'] ?? '1') === '0') {
            JsonResponse::error('Регистрация временно закрыта.', 403);
        }

        $username = trim((string) ($_POST['username'] ?? ''));
        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $error = self::validate($username, $email, $password);
        if ($error !== null) {
            JsonResponse::error($error);
        }

        if ($db->fetchOne('SELECT id FROM users WHERE username = ?', [$username])) {
            JsonResponse::error('Это имя пользователя уже занято.');
        }
        if ($db->fetchOne('SELECT id FROM users WHERE email = ?', [$email])) {
            JsonResponse::error('Этот email уже зарегистрирован.');
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
        DefaultRoomMembership::joinDefaultRooms($db, $userId);
        JsonResponse::success(['pending_verification' => true, 'email' => $email]);
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

}
