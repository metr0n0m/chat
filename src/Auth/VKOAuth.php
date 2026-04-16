<?php
declare(strict_types=1);

namespace Chat\Auth;

use Chat\DB\Connection;
use Chat\Security\Session;

class VKOAuth
{
    private const AUTH_URL    = 'https://oauth.vk.com/authorize';
    private const TOKEN_URL   = 'https://oauth.vk.com/access_token';
    private const API_URL     = 'https://api.vk.com/method/users.get';
    private const API_VERSION = '5.199';

    public static function redirect(): void
    {
        $state = bin2hex(random_bytes(16));
        setcookie('oauth_state', $state, [
            'expires'  => time() + 300,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $params = http_build_query([
            'client_id'     => VK_CLIENT_ID,
            'redirect_uri'  => VK_REDIRECT_URI,
            'scope'         => 'email',
            'response_type' => 'code',
            'state'         => $state,
            'v'             => self::API_VERSION,
        ]);

        header('Location: ' . self::AUTH_URL . '?' . $params);
        exit;
    }

    public static function callback(): void
    {
        $state = $_COOKIE['oauth_state'] ?? '';
        if (!$state || !hash_equals($state, $_GET['state'] ?? '')) {
            self::fail('Неверный state параметр.');
        }

        $code = $_GET['code'] ?? '';
        if (!$code) {
            self::fail('Нет кода авторизации.');
        }

        $tokenData = self::fetchToken($code);
        if (!isset($tokenData['access_token'])) {
            self::fail('Не удалось получить токен.');
        }

        $accessToken = $tokenData['access_token'];
        $vkUserId    = (string) ($tokenData['user_id'] ?? '');
        $email       = $tokenData['email'] ?? null;

        $profile = self::fetchProfile($accessToken, $vkUserId);
        if (!$profile) {
            self::fail('Не удалось получить профиль.');
        }

        $db   = Connection::getInstance();
        $user = $db->fetchOne(
            "SELECT id, is_banned FROM users WHERE oauth_provider = 'vk' AND oauth_id = ?",
            [$vkUserId]
        );

        if ($user) {
            if ($user['is_banned']) {
                self::fail('Ваш аккаунт заблокирован.');
            }
            $userId = (int) $user['id'];
        } else {
            $username = self::generateUsername($profile, $db);
            $db->execute(
                'INSERT INTO users (username, email, oauth_provider, oauth_id, avatar_url) VALUES (?, ?, ?, ?, ?)',
                [$username, $email, 'vk', $vkUserId, $profile['photo_200'] ?? null]
            );
            $userId = (int) $db->lastInsertId();
            self::joinDefaultRooms($db, $userId);
        }

        self::storeToken($db, $userId, 'vk', $accessToken);

        $ip    = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua    = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $token = Session::create($userId, $ip, $ua);
        Session::setCookie($token);

        setcookie('oauth_state', '', ['expires' => time() - 1, 'path' => '/']);
        header('Location: /');
        exit;
    }

    private static function fetchToken(string $code): array
    {
        $params = http_build_query([
            'client_id'     => VK_CLIENT_ID,
            'client_secret' => VK_CLIENT_SECRET,
            'redirect_uri'  => VK_REDIRECT_URI,
            'code'          => $code,
        ]);
        $resp = self::httpGet(self::TOKEN_URL . '?' . $params);
        return json_decode($resp, true) ?? [];
    }

    private static function fetchProfile(string $token, string $userId): ?array
    {
        $params = http_build_query([
            'user_ids'  => $userId,
            'fields'    => 'photo_200',
            'access_token' => $token,
            'v'         => self::API_VERSION,
        ]);
        $resp = self::httpGet(self::API_URL . '?' . $params);
        $data = json_decode($resp, true);
        return $data['response'][0] ?? null;
    }

    private static function generateUsername(array $profile, Connection $db): string
    {
        $base = preg_replace('/[^\w\p{Cyrillic}]/u', '', ($profile['first_name'] ?? '') . ($profile['last_name'] ?? ''));
        $base = $base ?: 'user';
        $username = $base;
        $i = 1;
        while ($db->fetchOne('SELECT id FROM users WHERE username = ?', [$username])) {
            $username = $base . $i++;
        }
        return substr($username, 0, 50);
    }

    private static function storeToken(Connection $db, int $userId, string $provider, string $token): void
    {
        $iv        = random_bytes(16);
        $encrypted = base64_encode($iv . openssl_encrypt($token, 'AES-256-CBC', base64_decode(OAUTH_ENCRYPT_KEY), OPENSSL_RAW_DATA, $iv));
        $db->execute(
            'INSERT INTO oauth_tokens (user_id, provider, access_token_encrypted) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE access_token_encrypted = VALUES(access_token_encrypted)',
            [$userId, $provider, $encrypted]
        );
    }

    private static function joinDefaultRooms(Connection $db, int $userId): void
    {
        $rooms = $db->fetchAll("SELECT id FROM rooms WHERE type='public' AND is_closed=0 ORDER BY id LIMIT 5");
        foreach ($rooms as $room) {
            $db->execute(
                'INSERT IGNORE INTO room_members (room_id, user_id, room_role) VALUES (?, ?, ?)',
                [$room['id'], $userId, 'member']
            );
        }
    }

    private static function httpGet(string $url): string
    {
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'method' => 'GET']]);
        return (string) @file_get_contents($url, false, $ctx);
    }

    private static function fail(string $message): never
    {
        header('Location: /?auth_error=' . urlencode($message));
        exit;
    }
}
