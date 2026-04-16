<?php
declare(strict_types=1);

namespace Chat\Security;

class HMAC
{
    public static function sign(string $content): string
    {
        return hash_hmac('sha256', $content, APP_SECRET);
    }

    public static function verify(string $content, string $hmac): bool
    {
        return hash_equals(self::sign($content), $hmac);
    }
}
