<?php
declare(strict_types=1);

namespace Chat\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Chat\Security\Session;
use Chat\DB\Connection;

class Server implements MessageComponentInterface
{
    private ConnectionManager $cm;
    private EventRouter $router;

    public function __construct()
    {
        $this->cm     = new ConnectionManager();
        $this->router = new EventRouter($this->cm);

        echo '[WS] Server started on port ' . WS_PORT . PHP_EOL;
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $cookies = $this->parseCookies(
            $conn->httpRequest->getHeader('Cookie')[0] ?? ''
        );
        $token = $cookies['chat_session'] ?? '';

        if (!$token) {
            $this->reject($conn, 'Не авторизован.');
            return;
        }

        $ip = $this->getRemoteIp($conn);
        $ua = $conn->httpRequest->getHeader('User-Agent')[0] ?? '';

        try {
            $session = Session::validate($token, $ip, $ua);
        } catch (\Throwable) {
            $this->reject($conn, 'Ошибка сессии.');
            return;
        }

        if (!$session) {
            $this->reject($conn, 'Недействительная сессия.');
            return;
        }

        $this->cm->add($conn, $session);

        $conn->send(json_encode([
            'event' => 'connected',
            'user'  => [
                'id'             => $session['id'],
                'username'       => $session['username'],
                'nick_color'     => $session['nick_color'],
                'text_color'     => $session['text_color'],
                'avatar_url'     => $session['avatar_url'],
                'global_role'    => $session['global_role'],
                'can_create_room'=> (bool) $session['can_create_room'],
            ],
        ]));

        echo '[WS] Connected: ' . $session['username'] . ' (' . $conn->resourceId . ')' . PHP_EOL;
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $data = json_decode($msg, true);
        if (!is_array($data) || !isset($data['event'])) {
            return;
        }
        try {
            $this->router->route($from, $data);
        } catch (\Throwable $e) {
            echo '[WS] Error: ' . $e->getMessage() . PHP_EOL;
            $this->cm->sendToConnection($from, ['event' => 'error', 'message' => 'Внутренняя ошибка.']);
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $session = $this->cm->remove($conn);
        if ($session) {
            echo '[WS] Disconnected: ' . $session['username'] . ' (' . $conn->resourceId . ')' . PHP_EOL;

            foreach ($this->cm->getUserRooms((int) $session['id']) as $roomId) {
                $this->cm->sendToRoom((int) $roomId, [
                    'event'   => 'user_left',
                    'room_id' => $roomId,
                    'user_id' => $session['id'],
                ]);
            }
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        echo '[WS] Exception: ' . $e->getMessage() . PHP_EOL;
        $conn->close();
    }

    private function reject(ConnectionInterface $conn, string $reason): void
    {
        $conn->send(json_encode(['event' => 'error', 'message' => $reason]));
        $conn->close();
    }

    private function parseCookies(string $header): array
    {
        $cookies = [];
        foreach (explode(';', $header) as $part) {
            $part = trim($part);
            if (!$part) {
                continue;
            }
            $pos  = strpos($part, '=');
            $name = $pos !== false ? substr($part, 0, $pos) : $part;
            $val  = $pos !== false ? substr($part, $pos + 1) : '';
            $cookies[trim($name)] = urldecode(trim($val));
        }
        return $cookies;
    }

    private function getRemoteIp(ConnectionInterface $conn): string
    {
        $headers = $conn->httpRequest->getHeader('X-Real-IP');
        if ($headers) {
            return $headers[0];
        }
        $headers = $conn->httpRequest->getHeader('X-Forwarded-For');
        if ($headers) {
            return trim(explode(',', $headers[0])[0]);
        }
        return $conn->remoteAddress ?? '';
    }
}
