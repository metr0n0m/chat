<?php
declare(strict_types=1);

namespace Tests\Support;

/**
 * Минимальный WebSocket-клиент (RFC 6455) для сквозных проверок чата.
 * Подключается к локальному ws-server как настоящий браузерный клиент:
 * рукопожатие с Origin и cookie сессии, маскированные текстовые кадры.
 * Только для тестов и локальной диагностики — не для продакшн-кода.
 */
final class WsTestClient
{
    /** @var resource */
    private $sock;
    private string $buffer = '';

    public function __construct(string $host, int $port, string $sessionToken, string $origin)
    {
        $sock = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 5);
        if ($sock === false) {
            throw new \RuntimeException('WS connect failed: ' . $errstr);
        }
        $this->sock = $sock;
        stream_set_timeout($this->sock, 5);

        $key = base64_encode(random_bytes(16));
        $handshake = 'GET / HTTP/1.1' . "\r\n"
            . 'Host: ' . $host . ':' . $port . "\r\n"
            . 'Upgrade: websocket' . "\r\n"
            . 'Connection: Upgrade' . "\r\n"
            . 'Sec-WebSocket-Key: ' . $key . "\r\n"
            . 'Sec-WebSocket-Version: 13' . "\r\n"
            . 'Origin: ' . $origin . "\r\n"
            . 'User-Agent: WsTestClient/1.0' . "\r\n"
            . 'Cookie: chat_session=' . $sessionToken . "\r\n\r\n";
        fwrite($this->sock, $handshake);

        $response = '';
        while (!str_contains($response, "\r\n\r\n")) {
            $chunk = fread($this->sock, 4096);
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('WS handshake: пустой ответ');
            }
            $response .= $chunk;
        }
        if (!str_contains($response, ' 101 ')) {
            throw new \RuntimeException('WS handshake отклонён: ' . strtok($response, "\r\n"));
        }
        // тело после заголовков (если сервер успел прислать кадр) — в буфер
        $this->buffer = substr($response, strpos($response, "\r\n\r\n") + 4);
    }

    public function send(array $data): void
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        $len     = strlen($payload);
        $frame   = "\x81"; // FIN + text

        if ($len <= 125) {
            $frame .= chr(0x80 | $len);
        } elseif ($len <= 0xFFFF) {
            $frame .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $len);
        }

        $mask = random_bytes(4);
        $frame .= $mask;
        for ($i = 0; $i < $len; $i++) {
            $frame .= $payload[$i] ^ $mask[$i % 4];
        }
        fwrite($this->sock, $frame);
    }

    /**
     * Читает события до истечения времени. Возвращает список декодированных JSON-событий.
     * @return list<array>
     */
    public function readEvents(float $timeoutSec = 2.0): array
    {
        $events   = [];
        $deadline = microtime(true) + $timeoutSec;

        while (true) {
            foreach ($this->drainFrames() as $payload) {
                $decoded = json_decode($payload, true);
                if (is_array($decoded)) {
                    $events[] = $decoded;
                }
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }
            $read = [$this->sock];
            $write = $except = [];
            $sec  = (int) $remaining;
            $usec = (int) (($remaining - $sec) * 1_000_000);
            if (@stream_select($read, $write, $except, $sec, $usec) > 0) {
                $chunk = fread($this->sock, 65536);
                if ($chunk === false || $chunk === '') {
                    break; // соединение закрыто сервером
                }
                $this->buffer .= $chunk;
            }
        }

        return $events;
    }

    public function close(): void
    {
        @fwrite($this->sock, "\x88\x80" . random_bytes(4)); // masked close frame
        @fclose($this->sock);
    }

    /** Извлекает все целиком пришедшие text-кадры из буфера. @return list<string> */
    private function drainFrames(): array
    {
        $payloads = [];
        while (true) {
            $bufLen = strlen($this->buffer);
            if ($bufLen < 2) {
                break;
            }
            $opcode = ord($this->buffer[0]) & 0x0F;
            $len    = ord($this->buffer[1]) & 0x7F;
            $offset = 2;
            if ($len === 126) {
                if ($bufLen < 4) break;
                $len = unpack('n', substr($this->buffer, 2, 2))[1];
                $offset = 4;
            } elseif ($len === 127) {
                if ($bufLen < 10) break;
                $len = unpack('J', substr($this->buffer, 2, 8))[1];
                $offset = 10;
            }
            if ($bufLen < $offset + $len) {
                break; // кадр ещё не дочитан
            }
            $payload = substr($this->buffer, $offset, $len);
            $this->buffer = substr($this->buffer, $offset + $len);

            if ($opcode === 0x1) {
                $payloads[] = $payload;
            } elseif ($opcode === 0x9) {
                // ping → pong с тем же телом
                $mask = random_bytes(4);
                $masked = '';
                for ($i = 0; $i < strlen($payload); $i++) {
                    $masked .= $payload[$i] ^ $mask[$i % 4];
                }
                @fwrite($this->sock, "\x8A" . chr(0x80 | strlen($payload)) . $mask . $masked);
            } elseif ($opcode === 0x8) {
                break; // close
            }
        }
        return $payloads;
    }
}
