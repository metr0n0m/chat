<?php
declare(strict_types=1);

namespace Chat\WebSocket;

use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use Chat\Security\Session;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;

class Server implements MessageComponentInterface
{
    private const RECONNECT_GRACE_SECONDS = 12;

    private ConnectionManager $cm;
    private EventRouter $router;

    /** @var array<int, TimerInterface> */
    private array $pendingDisconnectTimers = [];

    public function __construct()
    {
        $this->cm = new ConnectionManager();
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
        $userId = (int) $session['id'];
        if (isset($this->pendingDisconnectTimers[$userId])) {
            Loop::cancelTimer($this->pendingDisconnectTimers[$userId]);
            unset($this->pendingDisconnectTimers[$userId]);
        }

        $conn->send(json_encode([
            'event' => 'connected',
            'user' => [
                'id' => $session['id'],
                'username' => $session['username'],
                'nick_color' => $session['nick_color'],
                'text_color' => $session['text_color'],
                'avatar_url' => $session['avatar_url'],
                'global_role' => $session['global_role'],
                'can_create_room' => (bool) $session['can_create_room'],
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
            $this->cm->sendToConnection($from, [
                'event' => 'error',
                'message' => 'Внутренняя ошибка.',
            ]);
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $removed = $this->cm->remove($conn);
        if (!$removed) {
            return;
        }

        $session = $removed['session'] ?? null;
        if (!is_array($session)) {
            return;
        }

        $userId = (int) ($session['id'] ?? 0);
        echo '[WS] Disconnected: ' . ($session['username'] ?? 'unknown') . ' (' . $conn->resourceId . ')' . PHP_EOL;

        if (empty($removed['went_offline']) || $userId <= 0) {
            return;
        }

        if (isset($this->pendingDisconnectTimers[$userId])) {
            Loop::cancelTimer($this->pendingDisconnectTimers[$userId]);
            unset($this->pendingDisconnectTimers[$userId]);
        }

        $rooms = array_map('intval', (array) ($removed['rooms'] ?? []));
        $this->pendingDisconnectTimers[$userId] = Loop::addTimer(self::RECONNECT_GRACE_SECONDS, function () use ($userId, $rooms) {
            unset($this->pendingDisconnectTimers[$userId]);

            // User reconnected during grace period: keep room presence untouched.
            if ($this->cm->isUserOnline($userId)) {
                return;
            }

            foreach ($rooms as $roomId) {
                if ($roomId <= 0 || !$this->cm->isInRoom($userId, $roomId)) {
                    continue;
                }
                $this->cm->leaveRoom($userId, $roomId);
                $this->cm->sendToRoom($roomId, [
                    'event' => 'user_left',
                    'room_id' => $roomId,
                    'user_id' => $userId,
                ]);
            }
        });
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
            $pos = strpos($part, '=');
            $name = $pos !== false ? substr($part, 0, $pos) : $part;
            $val = $pos !== false ? substr($part, $pos + 1) : '';
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
