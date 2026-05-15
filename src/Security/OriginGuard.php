<?php
declare(strict_types=1);

namespace Chat\Security;

class OriginGuard
{
    public static function isAllowed(?string $origin): bool
    {
        if ($origin === null || $origin === '') {
            return false;
        }

        $parsed = parse_url($origin);
        if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
            return false;
        }

        $normalized = self::normalize($parsed);
        if ($normalized === null) {
            return false;
        }

        $allowed = defined('WS_ALLOWED_ORIGINS') ? (array) WS_ALLOWED_ORIGINS : [];

        if (empty($allowed)) {
            echo '[WS] WS_ALLOWED_ORIGINS is not configured' . PHP_EOL;
            return false;
        }

        foreach ($allowed as $entry) {
            $ep = parse_url((string) $entry);
            if (!$ep || empty($ep['scheme']) || empty($ep['host'])) {
                continue;
            }
            $an = self::normalize($ep);
            if ($an !== null && $normalized === $an) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(array $parsed): ?string
    {
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        $host   = strtolower((string) ($parsed['host']   ?? ''));

        if ($scheme === '' || $host === '') {
            return null;
        }

        $result = $scheme . '://' . $host;

        if (isset($parsed['port'])) {
            $port = (int) $parsed['port'];
            $standard = ($scheme === 'https' && $port === 443)
                     || ($scheme === 'http'  && $port === 80);
            if (!$standard) {
                $result .= ':' . $port;
            }
        }

        return $result;
    }
}
