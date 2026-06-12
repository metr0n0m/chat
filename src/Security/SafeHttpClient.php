<?php
declare(strict_types=1);

namespace Chat\Security;

/**
 * Единый безопасный HTTP-клиент для исходящих запросов по URL, полученным от пользователей
 * (аватары по ссылке, предпросмотр ссылок в сообщениях).
 *
 * Защита от SSRF (подделки запросов к внутренней сети):
 *  - разрешены только схемы http/https, URL с логином-паролем (user@host) отклоняются;
 *  - имя хоста разрешается в IP один раз, ВСЕ полученные адреса проверяются на
 *    принадлежность к приватным/служебным диапазонам (10.x, 192.168.x, 127.x, 169.254.x и т.д.);
 *  - сам запрос идёт строго на проверенный IP (имя хоста передаётся заголовком Host
 *    и через SNI для TLS) — повторное разрешение имени невозможно, что закрывает
 *    атаку через подмену DNS-ответа между проверкой и запросом (DNS rebinding);
 *  - перенаправления (redirect) не выполняются: redirect на внутренний адрес невозможен.
 *
 * Хосты, доступные только по IPv6, не поддерживаются (разрешение идёт по A-записям).
 * Last updated: 2026-06-12.
 */
final class SafeHttpClient
{
    /**
     * HEAD-запрос. Возвращает заголовки ответа (ключи в нижнем регистре)
     * или пустой массив, если URL не прошёл проверку или запрос не удался.
     */
    public static function head(string $url, int $timeout = 5): array
    {
        $pin = self::resolveAndPin($url);
        if ($pin === null) {
            return [];
        }

        $ctx = stream_context_create([
            'http' => [
                'method'          => 'HEAD',
                'timeout'         => $timeout,
                'follow_location' => false,
                'ignore_errors'   => true,
                'header'          => self::requestHeaders($pin),
            ],
            'ssl' => self::sslOptions($pin),
        ]);

        @file_get_contents($pin['url'], false, $ctx);

        return self::parseHeaders($http_response_header ?? []);
    }

    /**
     * GET-запрос с ограничением объёма ответа.
     * Возвращает тело ответа или null, если URL не прошёл проверку или запрос не удался.
     *
     * @param string[] $extraHeaders дополнительные заголовки запроса, например 'Accept: text/html'
     */
    public static function get(string $url, int $timeout = 5, int $maxBytes = 50000, array $extraHeaders = []): ?string
    {
        $pin = self::resolveAndPin($url);
        if ($pin === null) {
            return null;
        }

        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => $timeout,
                'follow_location' => false,
                'header'          => self::requestHeaders($pin, $extraHeaders),
            ],
            'ssl' => self::sslOptions($pin),
        ]);

        $body = @file_get_contents($pin['url'], false, $ctx, 0, $maxBytes);

        return $body === false ? null : $body;
    }

    /**
     * Проверяет URL и закрепляет проверенный IP в адресе запроса.
     *
     * @return array{url:string, host:string, hostHeader:string}|null
     */
    private static function resolveAndPin(string $url): ?array
    {
        $parsed = parse_url($url);
        if (!$parsed || !in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
            return null;
        }
        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return null;
        }

        $host = strtolower((string) ($parsed['host'] ?? ''));
        if ($host === '') {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = gethostbynamel($host) ?: [];
        }
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return null;
            }
        }

        $scheme      = $parsed['scheme'];
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $port        = (int) ($parsed['port'] ?? $defaultPort);

        $pinnedHost = str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0];
        $pinnedUrl  = $scheme . '://' . $pinnedHost . ':' . $port
                    . ($parsed['path'] ?? '/')
                    . (isset($parsed['query']) ? '?' . $parsed['query'] : '');

        return [
            'url'        => $pinnedUrl,
            'host'       => $host,
            'hostHeader' => $port === $defaultPort ? $host : $host . ':' . $port,
        ];
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * @param string[] $extra
     * @return string[]
     */
    private static function requestHeaders(array $pin, array $extra = []): array
    {
        return array_merge(['Host: ' . $pin['hostHeader']], $extra);
    }

    private static function sslOptions(array $pin): array
    {
        // Сертификат проверяется по исходному имени хоста, а не по IP
        return [
            'peer_name'         => $pin['host'],
            'SNI_enabled'       => true,
            'verify_peer'       => true,
            'verify_peer_name'  => true,
        ];
    }

    /**
     * @param string[] $lines
     * @return array<string,string>
     */
    private static function parseHeaders(array $lines): array
    {
        $headers = [];
        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        return $headers;
    }
}
