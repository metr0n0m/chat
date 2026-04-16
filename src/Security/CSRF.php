<?php
declare(strict_types=1);

namespace Chat\Security;

class CSRF
{
    private const COOKIE_NAME = 'csrf_token';
    private const HEADER_NAME = 'X-CSRF-Token';

    public static function token(): string
    {
        $token = $_COOKIE[self::COOKIE_NAME] ?? '';
        if (!$token || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            self::setCookie($token);
        }
        return $token;
    }

    public static function verify(string $submitted): bool
    {
        $cookie = $_COOKIE[self::COOKIE_NAME] ?? '';
        return $cookie !== '' && hash_equals($cookie, $submitted);
    }

    public static function verifyRequest(): bool
    {
        $submitted = $_POST['csrf_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? '';
        return self::verify($submitted);
    }

    public static function field(): string
    {
        return sprintf(
            '<input type="hidden" name="csrf_token" value="%s">',
            htmlspecialchars(self::token(), ENT_QUOTES)
        );
    }

    private static function setCookie(string $token): void
    {
        setcookie(self::COOKIE_NAME, $token, [
            'expires'  => 0,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) || ($_SERVER['SERVER_PORT'] ?? 80) == 443,
            'httponly' => false,
            'samesite' => 'Strict',
        ]);
        $_COOKIE[self::COOKIE_NAME] = $token;
    }
}
