<?php
declare(strict_types=1);

namespace Chat\Auth;

use Chat\DB\Connection;
use Chat\Security\{Session, CSRF};

class LoginHandler
{
    public static function handle(): void
    {
        if (!CSRF::verifyRequest()) {
            self::jsonError('Неверный CSRF токен.', 403);
        }

        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$email || !$password) {
            self::jsonError('Введите email и пароль.');
        }

        $db   = Connection::getInstance();
        $user = $db->fetchOne(
            'SELECT id, password_hash, is_banned, global_role FROM users WHERE email = ?',
            [$email]
        );

        if (!$user || !password_verify($password, $user['password_hash'] ?? '')) {
            self::jsonError('Неверный email или пароль.');
        }

        if ($user['is_banned']) {
            self::jsonError('Ваш аккаунт заблокирован.');
        }

        $ip    = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua    = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $token = Session::create((int) $user['id'], $ip, $ua);
        Session::setCookie($token);

        self::jsonSuccess(['redirect' => '/']);
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
