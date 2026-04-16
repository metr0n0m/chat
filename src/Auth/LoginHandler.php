<?php
declare(strict_types=1);

namespace Chat\Auth;

use Chat\DB\Connection;
use Chat\Security\CSRF;
use Chat\Security\Session;

class LoginHandler
{
    public static function handle(): void
    {
        if (!CSRF::verifyRequest()) {
            self::jsonError('Неверный CSRF токен.', 403);
        }

        $login = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($login === '' || $password === '') {
            self::jsonError('Введите email или имя пользователя и пароль.');
        }

        $db = Connection::getInstance();
        $user = $db->fetchOne(
            'SELECT id, password_hash, is_banned, global_role
             FROM users
             WHERE email = ? OR username = ?
             LIMIT 1',
            [$login, $login]
        );

        if (!$user || !password_verify($password, $user['password_hash'] ?? '')) {
            self::jsonError('Неверный логин или пароль.');
        }

        if ((int) ($user['is_banned'] ?? 0) === 1) {
            self::jsonError('Ваш аккаунт заблокирован.');
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $token = Session::create((int) $user['id'], $ip, $ua);
        Session::setCookie($token);

        self::jsonSuccess(['redirect' => '/']);
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
