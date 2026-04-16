<?php
declare(strict_types=1);

namespace Chat\Auth;

use Chat\DB\Connection;
use Chat\Security\Session;

class GoogleOAuth
{
    private const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const INFO_URL  = 'https://www.googleapis.com/oauth2/v3/userinfo';

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
            'client_id'     => GOOGLE_CLIENT_ID,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'offline',
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
        if (empty($tokenData['access_token'])) {
            self::fail('Не удалось получить токен.');
        }

        $profile = self::fetchProfile($tokenData['access_token']);
        if (!$profile || empty($profile['sub'])) {
            self::fail('Не удалось получить профиль.');
        }

        $googleId = $profile['sub'];
        $email    = $profile['email'] ?? null;
        $avatar   = $profile['picture'] ?? null;

        $db   = Connection::getInstance();
        $user = $db->fetchOne(
            "SELECT id, is_banned FROM users WHERE oauth_provider = 'google' AND oauth_id = ?",
            [$googleId]
        );

        if ($user) {
            if ($user['is_banned']) {
                self::fail('Ваш аккаунт заблокирован.');
            }
            $userId = (int) $user['id'];
        } else {
            if ($email) {
                $existing = $db->fetchOne('SELECT id FROM users WHERE email = ?', [$email]);
                if ($existing) {
                    $db->execute(
                        "UPDATE users SET oauth_provider = 'google', oauth_id = ? WHERE id = ?",
                        [$googleId, $existing['id']]
                    );
                    $userId = (int) $existing['id'];
                } else {
                    $userId = self::createUser($db, $profile, $googleId, $email, $avatar);
                }
            } else {
                $userId = self::createUser($db, $profile, $googleId, null, $avatar);
            }
        }

        self::storeToken($db, $userId, 'google', $tokenData['access_token'], $tokenData['refresh_token'] ?? null);

        $ip    = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua    = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $token = Session::create($userId, $ip, $ua);
        Session::setCookie($token);

        setcookie('oauth_state', '', ['expires' => time() - 1, 'path' => '/']);
        header('Location: /');
        exit;
    }

    private static function createUser(Connection $db, array $profile, string $googleId, ?string $email, ?string $avatar): int
    {
        $username = self::generateUsername($profile, $db);
        $db->execute(
            'INSERT INTO users (username, email, oauth_provider, oauth_id, avatar_url) VALUES (?, ?, ?, ?, ?)',
            [$username, $email, 'google', $googleId, $avatar]
        );
        $userId = (int) $db->lastInsertId();
        self::joinDefaultRooms($db, $userId);
        return $userId;
    }

    private static function fetchToken(string $code): array
    {
        $payload = http_build_query([
            'code'          => $code,
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'grant_type'    => 'authorization_code',
        ]);
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => $payload,
            'timeout' => 5,
        ]]);
        $resp = (string) @file_get_contents(self::TOKEN_URL, false, $ctx);
        return json_decode($resp, true) ?? [];
    }

    private static function fetchProfile(string $accessToken): ?array
    {
        $ctx = stream_context_create(['http' => [
            'header'  => 'Authorization: Bearer ' . $accessToken,
            'timeout' => 5,
        ]]);
        $resp = (string) @file_get_contents(self::INFO_URL, false, $ctx);
        return json_decode($resp, true) ?: null;
    }

    private static function generateUsername(array $profile, Connection $db): string
    {
        $raw  = ($profile['given_name'] ?? '') . ($profile['family_name'] ?? '');
        $base = preg_replace('/[^\w\p{Cyrillic}]/u', '', $raw) ?: 'user';
        $username = $base;
        $i = 1;
        while ($db->fetchOne('SELECT id FROM users WHERE username = ?', [$username])) {
            $username = $base . $i++;
        }
        return substr($username, 0, 50);
    }

    private static function storeToken(Connection $db, int $userId, string $provider, string $access, ?string $refresh): void
    {
        $key       = base64_decode(OAUTH_ENCRYPT_KEY);
        $iv        = random_bytes(16);
        $encAccess = base64_encode($iv . openssl_encrypt($access, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv));

        $encRefresh = null;
        if ($refresh) {
            $iv2        = random_bytes(16);
            $encRefresh = base64_encode($iv2 . openssl_encrypt($refresh, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv2));
        }

        $db->execute(
            'INSERT INTO oauth_tokens (user_id, provider, access_token_encrypted, refresh_token_encrypted)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               access_token_encrypted  = VALUES(access_token_encrypted),
               refresh_token_encrypted = VALUES(refresh_token_encrypted)',
            [$userId, $provider, $encAccess, $encRefresh]
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

    private static function fail(string $message): never
    {
        header('Location: /?auth_error=' . urlencode($message));
        exit;
    }
}
