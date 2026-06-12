<?php
declare(strict_types=1);

namespace Tests\Unit;

use Chat\Security\SafeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Защита от SSRF: запросы к внутренним/служебным адресам должны блокироваться
 * ДО любого сетевого обращения.
 */
final class SafeHttpClientTest extends TestCase
{
    #[DataProvider('blockedUrls')]
    public function testHeadBlocksUnsafeUrls(string $url): void
    {
        $this->assertSame([], SafeHttpClient::head($url, 1));
    }

    #[DataProvider('blockedUrls')]
    public function testGetBlocksUnsafeUrls(string $url): void
    {
        $this->assertNull(SafeHttpClient::get($url, 1));
    }

    public static function blockedUrls(): array
    {
        return [
            'loopback'                  => ['http://127.0.0.1/secret'],
            'loopback с портом'         => ['http://127.0.0.1:8080/'],
            'RFC1918 10.x'              => ['http://10.0.0.1/'],
            'RFC1918 192.168.x'         => ['http://192.168.1.1/router'],
            'RFC1918 172.16.x'          => ['http://172.16.0.1/'],
            'link-local / метаданные'   => ['http://169.254.169.254/latest/meta-data/'],
            'нулевой адрес'             => ['http://0.0.0.0/'],
            'IPv6 loopback'             => ['http://[::1]/'],
            'схема ftp'                 => ['ftp://example.com/file'],
            'схема file'                => ['file:///etc/passwd'],
            'логин-пароль в URL'        => ['http://user:pass@example.com/'],
            'пустой хост'               => ['http:///path'],
            'не URL'                    => ['это не ссылка'],
        ];
    }
}
