<?php
declare(strict_types=1);

namespace Chat\WebSocket;

use Chat\DB\Connection;

/**
 * Доставщик ws_outbox: вызывается периодическим таймером WS-процесса (~1с).
 * Читает пачку событий, доставляет согласно audience и удаляет строки.
 *
 * Спец-обработка по event_type:
 *  - force_logout     → разорвать все соединения пользователя (cm->closeUser);
 *  - numer_destroyed  → после доставки убрать комнату из presence (cm->clearRoom);
 *  - banned_from_room → после доставки убрать цель из presence комнаты (cm->leaveRoom).
 */
final class OutboxDispatcher
{
    private const BATCH = 100;

    public static function dispatch(ConnectionManager $cm): int
    {
        $db = Connection::getInstance();
        $rows = $db->fetchAll(
            'SELECT id, audience, target_id, exclude_user_id, event_type, payload_json
             FROM ws_outbox ORDER BY id ASC LIMIT ' . self::BATCH
        );
        if ($rows === []) {
            return 0;
        }

        foreach ($rows as $row) {
            $event = json_decode((string) $row['payload_json'], true);
            if (!is_array($event)) {
                continue; // битая строка — удалится ниже, в журнале события всё равно есть
            }
            $targetId = (int) $row['target_id'];
            $exclude  = $row['exclude_user_id'] !== null ? (int) $row['exclude_user_id'] : null;

            switch ($row['audience']) {
                case 'user':
                    if ($row['event_type'] === 'force_logout') {
                        $cm->closeUser($targetId, $event);
                    } else {
                        $cm->sendToUser($targetId, $event);
                    }
                    if ($row['event_type'] === 'banned_from_room' && isset($event['room_id'])) {
                        $cm->leaveRoom($targetId, (int) $event['room_id']);
                    }
                    break;

                case 'room':
                    $cm->sendToRoom($targetId, $event, $exclude);
                    if ($row['event_type'] === 'numer_destroyed') {
                        $cm->clearRoom($targetId);
                    }
                    break;

                case 'room_staff':
                    StaffNotifier::sendToRoomStaff($cm, $db, $targetId, $exclude, $event);
                    break;
            }
        }

        $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
        $db->execute(
            'DELETE FROM ws_outbox WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );

        return count($rows);
    }
}
