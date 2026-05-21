<?php
declare(strict_types=1);

namespace Chat\Auth;

use Chat\DB\Connection;
use Chat\Http\JsonResponse;
use Chat\Security\CSRF;
use Chat\Security\Session;

class LoginHandler
{
    public static function handle(): void
    {
        if (!CSRF::verifyRequest()) {
            JsonResponse::error('Неверный CSRF токен.', 403);
        }

        $login = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($login === '' || $password === '') {
            JsonResponse::error('Введите email или имя пользователя и пароль.');
        }

        $db = Connection::getInstance();
        $user = $db->fetchOne(
            'SELECT id, password_hash, is_banned, email_verified, global_role
             FROM users
             WHERE email = ? OR username = ?
             LIMIT 1',
            [$login, $login]
        );

        if (!$user || !password_verify($password, $user['password_hash'] ?? '')) {
            JsonResponse::error('Неверный логин или пароль.');
        }

        if (Session::isUserBlocked((int) $user['id'])) {
            JsonResponse::error('Ваш аккаунт заблокирован.');
        }

        if ((int) ($user['email_verified'] ?? 0) === 0) {
            JsonResponse::error('Подтвердите email. Проверьте почту или запросите новое письмо.', 403);
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $token = Session::create((int) $user['id'], $ip, $ua);
        Session::setCookie($token);

        JsonResponse::success(['redirect' => '/']);
    }


}
