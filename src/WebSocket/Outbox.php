<?php
declare(strict_types=1);

namespace Chat\WebSocket;

use Chat\DB\Connection;

/**
 * Очередь исходящих WS-событий для кода, работающего ВНЕ WS-процесса (PHP-FPM).
 * Этап S2 движка санкций: действия админ-панели доходят до клиентов вживую.
 *
 * HTTP-код кладёт событие сюда; WS-процесс раз в секунду доставляет
 * (OutboxDispatcher) и удаляет строку. Это очередь, не журнал.
 */
final class Outbox
{
    public static function toUser(int $userId, string $eventType, array $payload): void
    {
        self::enqueue('user', $userId, null, $eventType, $payload);
    }

    public static function toRoom(int $roomId, string $eventType, array $payload, ?int $excludeUserId = null): void
    {
        self::enqueue('room', $roomId, $excludeUserId, $eventType, $payload);
    }

    /** Доставка только стаффу комнаты (глобальный стафф + owner/local_admin/local_moderator). */
    public static function toRoomStaff(int $roomId, string $eventType, array $payload, ?int $excludeUserId = null): void
    {
        self::enqueue('room_staff', $roomId, $excludeUserId, $eventType, $payload);
    }

    private static function enqueue(string $audience, int $targetId, ?int $excludeUserId, string $eventType, array $payload): void
    {
        $payload['event'] ??= $eventType;
        Connection::getInstance()->execute(
            'INSERT INTO ws_outbox (audience, target_id, exclude_user_id, event_type, payload_json)
             VALUES (?, ?, ?, ?, ?)',
            [$audience, $targetId, $excludeUserId, $eventType, json_encode($payload, JSON_UNESCAPED_UNICODE)]
        );
    }
}
