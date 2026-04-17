<?php
declare(strict_types=1);

namespace Chat\WebSocket;

use Ratchet\ConnectionInterface;

class ConnectionManager
{
    /** @var array<int, ConnectionInterface> connId → connection */
    private array $connections = [];

    /** @var array<int, array> connId → session data */
    private array $sessions = [];

    /** @var array<int, array<int, true>> userId → [connId => true] */
    private array $userConnections = [];

    /** @var array<int, array<int, true>> roomId → [userId => true] */
    private array $roomMembers = [];

    /** @var array<int, array<int, true>> userId → [roomId => true] */
    private array $userRooms = [];

    /** @var array<int, float> userId → last message timestamp */
    private array $lastMessageTime = [];

    /** @var array<int, int[]> userId → list of whisper timestamps in current minute */
    private array $whisperTimes = [];

    public function add(ConnectionInterface $conn, array $session): void
    {
        $connId = $conn->resourceId;
        $userId = (int) $session['id'];

        $this->connections[$connId]      = $conn;
        $this->sessions[$connId]         = $session;
        $this->userConnections[$userId][$connId] = true;
    }

    public function remove(ConnectionInterface $conn): ?array
    {
        $connId  = $conn->resourceId;
        $session = $this->sessions[$connId] ?? null;
        if (!$session) {
            return null;
        }

        $userId = (int) $session['id'];
        unset(
            $this->connections[$connId],
            $this->sessions[$connId],
            $this->userConnections[$userId][$connId]
        );

        $rooms = array_keys($this->userRooms[$userId] ?? []);
        $wentOffline = false;

        if (empty($this->userConnections[$userId])) {
            unset($this->userConnections[$userId]);
            $wentOffline = true;
        }

        return [
            'session' => $session,
            'rooms' => $rooms,
            'went_offline' => $wentOffline,
        ];
    }

    public function getSession(ConnectionInterface $conn): ?array
    {
        return $this->sessions[$conn->resourceId] ?? null;
    }

    public function joinRoom(ConnectionInterface $conn, int $roomId): void
    {
        $session = $this->sessions[$conn->resourceId] ?? null;
        if (!$session) {
            return;
        }
        $userId = (int) $session['id'];
        $this->roomMembers[$roomId][$userId] = true;
        $this->userRooms[$userId][$roomId]   = true;
    }

    public function leaveRoom(int $userId, int $roomId): void
    {
        unset($this->roomMembers[$roomId][$userId], $this->userRooms[$userId][$roomId]);
        if (empty($this->roomMembers[$roomId])) {
            unset($this->roomMembers[$roomId]);
        }
        if (empty($this->userRooms[$userId])) {
            unset($this->userRooms[$userId]);
        }
    }

    public function isInRoom(int $userId, int $roomId): bool
    {
        return isset($this->roomMembers[$roomId][$userId]);
    }

    public function getRoomUserIds(int $roomId): array
    {
        return array_keys($this->roomMembers[$roomId] ?? []);
    }

    public function isUserOnline(int $userId): bool
    {
        return !empty($this->userConnections[$userId]);
    }

    public function sendToUser(int $userId, array $event): void
    {
        $payload = json_encode($event);
        foreach (array_keys($this->userConnections[$userId] ?? []) as $connId) {
            if (isset($this->connections[$connId])) {
                $this->connections[$connId]->send($payload);
            }
        }
    }

    public function sendToRoom(int $roomId, array $event, ?int $excludeUserId = null): void
    {
        $payload = json_encode($event);
        foreach (array_keys($this->roomMembers[$roomId] ?? []) as $userId) {
            if ($excludeUserId !== null && $userId === $excludeUserId) {
                continue;
            }
            foreach (array_keys($this->userConnections[$userId] ?? []) as $connId) {
                if (isset($this->connections[$connId])) {
                    $this->connections[$connId]->send($payload);
                }
            }
        }
    }

    public function sendToConnection(ConnectionInterface $conn, array $event): void
    {
        $conn->send(json_encode($event));
    }

    public function checkRateLimit(int $userId): bool
    {
        $now  = microtime(true);
        $last = $this->lastMessageTime[$userId] ?? 0;
        if ($now - $last < MSG_RATE_LIMIT_SEC) {
            return false;
        }
        $this->lastMessageTime[$userId] = $now;
        return true;
    }

    public function checkWhisperLimit(int $userId): bool
    {
        $now    = time();
        $minute = $now - 60;
        $times  = array_filter($this->whisperTimes[$userId] ?? [], fn($t) => $t > $minute);

        if (count($times) >= WHISPER_RATE_LIMIT_MIN) {
            $this->whisperTimes[$userId] = array_values($times);
            return false;
        }

        $times[] = $now;
        $this->whisperTimes[$userId] = array_values($times);
        return true;
    }

    public function getOnlineUserIds(): array
    {
        return array_keys($this->userConnections);
    }

    public function getUserRooms(int $userId): array
    {
        return array_keys($this->userRooms[$userId] ?? []);
    }
}
