<?php
declare(strict_types=1);

namespace Chat\Security;

use Chat\DB\Connection;

class Session
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function ipUaHash(string $ip, string $userAgent): string
    {
        return hash('sha256', $ip . $userAgent . APP_SECRET);
    }

    public static function create(int $userId, string $ip, string $userAgent): string
    {
        $db = Connection::getInstance();
        $token = self::generate();
        $tokenHash = self::hash($token);
        $ipUaHash = self::ipUaHash($ip, $userAgent);
        $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);

        $db->execute('DELETE FROM sessions WHERE expires_at <= NOW()');

        $db->execute(
            'INSERT INTO sessions (user_id, token_hash, ip_ua_hash, expires_at) VALUES (?, ?, ?, ?)',
            [$userId, $tokenHash, $ipUaHash, $expiresAt]
        );

        return $token;
    }

    public static function validate(string $token, string $ip, string $userAgent): ?array
    {
        if (!$token) {
            return null;
        }

        $db = Connection::getInstance();
        $tokenHash = self::hash($token);
        $session = $db->fetchOne(
            'SELECT s.id AS session_id, s.expires_at,
                    u.id, u.username, u.nickname, u.email, u.avatar_url,
                    u.custom_status, u.nick_color, u.text_color, u.global_role,
                    u.can_create_room, u.is_banned,
                    u.hide_last_seen, u.bio,
                    u.social_telegram, u.social_whatsapp, u.social_vk,
                    u.show_system_messages
             FROM sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = ? AND s.expires_at > NOW()',
            [$tokenHash]
        );

        if (!$session || self::isUserBlocked((int) $session['id'])) {
            return null;
        }

        $db->execute('UPDATE users SET last_seen_at = NOW() WHERE id = ?', [$session['id']]);

        return $session;
    }

    public static function destroy(string $token): void
    {
        Connection::getInstance()->execute(
            'DELETE FROM sessions WHERE token_hash = ?',
            [self::hash($token)]
        );
    }

    public static function destroyAllForUser(int $userId): void
    {
        Connection::getInstance()->execute(
            'DELETE FROM sessions WHERE user_id = ?',
            [$userId]
        );
    }

    /**
     * Check if user is currently blocked.
     * Automatically clears expired timed global bans before checking.
     * Permanent bans (banned_until = NULL) are never cleared automatically.
     */
    public static function isUserBlocked(int $userId): bool
    {
        $db = Connection::getInstance();

        $db->execute(
            'UPDATE users
             SET is_banned = 0, banned_at = NULL, banned_until = NULL,
                 ban_reason = NULL, banned_by = NULL
             WHERE id = ? AND is_banned = 1
               AND banned_until IS NOT NULL
               AND banned_until <= NOW()',
            [$userId]
        );

        $row = $db->fetchOne('SELECT is_banned FROM users WHERE id = ?', [$userId]);

        return isset($row['is_banned']) && (int) $row['is_banned'] === 1;
    }

    public static function setCookie(string $token): void
    {
        setcookie('chat_session', $token, [
            'expires'  => time() + SESSION_LIFETIME,
            'path'     => '/',
            'domain'   => COOKIE_DOMAIN,
            'secure'   => !empty($_SERVER['HTTPS']) || ($_SERVER['SERVER_PORT'] ?? 80) == 443,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function clearCookie(): void
    {
        setcookie('chat_session', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => COOKIE_DOMAIN,
            'secure'   => !empty($_SERVER['HTTPS']) || ($_SERVER['SERVER_PORT'] ?? 80) == 443,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function current(): ?array
    {
        $token = $_COOKIE['chat_session'] ?? '';
        if (!$token) {
            return null;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return self::validate($token, $ip, $ua);
    }
}
