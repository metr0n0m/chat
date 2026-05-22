<?php
declare(strict_types=1);

namespace Chat\Chat;

use Chat\DB\Connection;

/**
 * Каскадное удаление комнаты в одной транзакции.
 */
class RoomDeletionService
{
    /**
     * Удаляет комнату и все зависимые записи.
     * При ошибке откатывает транзакцию и пробрасывает исключение вызывающему коду.
     *
     * @throws \Throwable
     */
    public static function deleteWithDependencies(Connection $db, int $roomId): void
    {
        $db->beginTransaction();
        try {
            $db->execute('DELETE FROM messages WHERE room_id = ?', [$roomId]);
            $db->execute('DELETE FROM room_members WHERE room_id = ?', [$roomId]);
            $db->execute('DELETE FROM invitations WHERE room_id = ?', [$roomId]);
            $db->execute('DELETE FROM rooms WHERE id = ?', [$roomId]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
