<?php
declare(strict_types=1);

namespace Chat\WebSocket;

use Ratchet\ConnectionInterface;
use Chat\DB\Connection;
use Chat\Chat\{MessageController, WhisperController, RoomController, NumerController};
use Chat\Support\Lang;

/**
 * Маршрутизатор входящих WS-событий.
 * Last updated: 2026-04-17.
 */
class EventRouter
{
    /**
     * Инициализирует роутер WS-событий.
     * Last updated: 2026-04-17.
     */
    public function __construct(private ConnectionManager $cm) {}

    /**
     * Маршрутизирует событие текущего websocket-соединения.
     * Last updated: 2026-04-17.
     */
    public function route(ConnectionInterface $conn, array $data): void
    {
        $session = $this->cm->getSession($conn);
        if (!$session) {
            return;
        }

        $event = $data['event'] ?? '';

        match ($event) {
            'join_room'       => $this->onJoinRoom($conn, $session, $data),
            'leave_room'      => $this->onLeaveRoom($conn, $session, $data),
            'send_message'    => $this->onSendMessage($conn, $session, $data),
            'delete_message'  => $this->onDeleteMessage($conn, $session, $data),
            'send_whisper'    => $this->onSendWhisper($conn, $session, $data),
            'invite_user'     => $this->onInviteUser($conn, $session, $data),
            'invite_respond'  => $this->onInviteRespond($conn, $session, $data),
            'leave_numer'     => $this->onLeaveNumer($conn, $session, $data),
            'room_action'     => $this->onRoomAction($conn, $session, $data),
            'ping'            => $conn->send(json_encode(['event' => 'pong'])),
            default           => $this->cm->sendToConnection($conn, ['event' => 'error', 'message' => Lang::get('errors.common.invalid_event')]),
        };
    }

    private function onJoinRoom(ConnectionInterface $conn, array $session, array $data): void
    {
        $roomId = (int) ($data['room_id'] ?? 0);
        $userId = (int) $session['id'];
        $alreadyInRoom = $this->cm->isInRoom($userId, $roomId);

        $db   = Connection::getInstance();
        $room = $db->fetchOne(
            'SELECT id, type, is_closed FROM rooms WHERE id = ?',
            [$roomId]
        );
        if (!$room || $room['is_closed']) {
            $this->cm->sendToConnection($conn, ['event' => 'error', 'message' => Lang::get('errors.room.not_found')]);
            return;
        }

        $member = $db->fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [$roomId, $userId]
        );

        if (!$member || $member['room_role'] === 'banned') {
            if ($room['type'] === 'public') {
                $result = RoomController::join($roomId, $userId);
                if (isset($result['error'])) {
                    $this->cm->sendToConnection($conn, ['event' => 'error', 'message' => $result['error']]);
                    return;
                }
                $member = ['room_role' => 'member'];
            } else {
                $this->cm->sendToConnection($conn, ['event' => 'error', 'message' => Lang::get('errors.common.access_denied')]);
                return;
            }
        }

        if (!$alreadyInRoom) {
            $this->cm->joinRoom($conn, $roomId);
        }

        $online = $this->getOnlineList($roomId, $db);
        $this->cm->sendToConnection($conn, [
            'event'     => 'room_joined',
            'room_id'   => $roomId,
            'my_role'   => $member['room_role'],
            'online'    => $online,
        ]);

        if ($room['type'] === 'public' && !$alreadyInRoom) {
            $this->cm->sendToRoom($roomId, [
                'event'    => 'user_joined',
                'room_id'  => $roomId,
                'user'     => $this->userPayload($session),
            ], $userId);

            $this->broadcastSystemMessage($roomId, $session['username'] . ' вошёл(а) в комнату', $userId);
        }
    }

    private function onLeaveRoom(ConnectionInterface $conn, array $session, array $data): void
    {
        $roomId = (int) ($data['room_id'] ?? 0);
        $userId = (int) $session['id'];

        if (!$this->cm->isInRoom($userId, $roomId)) {
            return;
        }

        $this->cm->leaveRoom($userId, $roomId);

        $this->cm->sendToRoom($roomId, [
            'event'   => 'user_left',
            'room_id' => $roomId,
            'user_id' => $userId,
        ]);
        $this->broadcastSystemMessage($roomId, $session['username'] . ' покинул(а) комнату', $userId);
    }

    private function onSendMessage(ConnectionInterface $conn, array $session, array $data): void
    {
        $roomId = (int) ($data['room_id'] ?? 0);
        $userId = (int) $session['id'];

        if (!$this->cm->isInRoom($userId, $roomId)) {
            $this->cm->sendToConnection($conn, ['event' => 'error', 'message' => Lang::get('errors.whisper.not_in_room')]);
            return;
        }

        if (!$this->cm->checkRateLimit($userId)) {
            $this->cm->sendToConnection($conn, ['event' => 'error', 'message' => Lang::get('errors.message.rate_limit')]);
            return;
        }

        $result = MessageController::send($roomId, $userId, $session, $data);
        if (isset($result['error'])) {
            $this->cm->sendToConnection($conn, ['event' => 'error', 'message' => $result['error']]);
            return;
        }

        $this->cm->sendToRoom($roomId, [
            'event'   => 'new_message',
            'message' => $result,
        ]);

        if (str_contains((string) ($data['content'] ?? ''), '@!')) {
            $this->notifyGlobalStaffCall($roomId, $session);
        }
    }

    private function onDeleteMessage(ConnectionInterface $conn, array $session, array $data): void
    {
        $msgId  = (int) ($data['message_id'] ?? 0);
        $userId = (int) $session['id'];

        $result = MessageController::delete($msgId, $userId, $session);
        if (isset($result['error'])) {
            $this->cm->sendToConnection($conn, ['event' => 'error', 'message' => $result['error']]);
            return;
        }

        $this->cm->sendToRoom((int) $result['room_id'], [
            'event'      => 'message_deleted',
            'message_id' => $msgId,
            'room_id'    => $result['room_id'],
        ]);
    }

    private function onSendWhisper(ConnectionInterface $conn, array $session, array $data): void
    {
        $roomId = (int) ($data['room_id'] ?? 0);
        $toId   = (int) ($data['to_user_id'] ?? 0);
        $fromId = (int) $session['id'];

        if (!$this->cm->isInRoom($fromId, $roomId)) {
            $this->cm->sendToConnection($conn, ['event' => 'error', 'message' => Lang::get('errors.whisper.not_in_room')]);
            return;
        }

        if (!$this->cm->isInRoom($toId, $roomId)) {
            $this->cm->sendToConnection($conn, ['event' => 'error', 'message' => Lang::get('errors.whisper.user_not_in_room')]);
            return;
        }

        if (!$this->cm->checkWhisperLimit($fromId)) {
            $this->cm->sendToConnection($conn, ['event' => 'error', 'message' => Lang::get('errors.whisper.rate_limit')]);
            return;
        }

        $result = WhisperController::send($roomId, $fromId, $session, $toId, $data['content'] ?? '');
        if (isset($result['error'])) {
            $this->cm->sendToConnection($conn, ['event' => 'error', 'message' => $result['error']]);
            return;
        }

        $this->cm->sendToUser($fromId, [
            'event'   => 'whisper_sent',
            'message' => $result,
        ]);

        $this->cm->sendToUser($toId, [
            'event'   => 'whisper_received',
            'message' => $result,
        ]);
    }

    private function onInviteUser(ConnectionInterface $conn, array $session, array $data): void
    {
        $toId   = (int) ($data['to_user_id'] ?? 0);
        $fromId = (int) $session['id'];

        $result = NumerController::invite($fromId, $session, $toId);
        if (isset($result['error'])) {
            $this->cm->sendToConnection($conn, ['event' => 'error', 'message' => $result['error']]);
            return;
        }

        if (!empty($result['self_created'])) {
            $roomId = (int) ($result['room_id'] ?? 0);
            if ($roomId > 0 && !$this->cm->isInRoom($fromId, $roomId)) {
                $this->cm->joinRoom($conn, $roomId);
            }
            $this->cm->sendToConnection($conn, [
                'event'   => 'numer_joined',
                'room_id' => $roomId,
                'members' => $result['members'] ?? [],
            ]);
            return;
        }

        $this->cm->sendToConnection($conn, ['event' => 'invite_sent', 'invitation' => $result]);

        $this->cm->sendToUser($toId, [
            'event'      => 'invite_received',
            'invitation' => $result,
        ]);

        // Auto-expire after 30 seconds
        $invId  = $result['invitation_id'];
        $fromId = (int) $session['id'];
        $this->scheduleInviteExpiry($invId, $toId, $fromId, 30);
    }

    private function scheduleInviteExpiry(int $invId, int $toId, int $fromId, int $seconds): void
    {
        $loop = \React\EventLoop\Loop::get();
        $cm   = $this->cm;
        $loop->addTimer($seconds, function () use ($invId, $toId, $fromId, $cm) {
            $db  = Connection::getInstance();
            $inv = $db->fetchOne(
                "SELECT status FROM invitations WHERE id = ?",
                [$invId]
            );
            if ($inv && $inv['status'] === 'pending') {
                $db->execute("UPDATE invitations SET status = 'expired' WHERE id = ?", [$invId]);
                $cm->sendToUser($toId,   ['event' => 'invite_expired', 'invitation_id' => $invId]);
                $cm->sendToUser($fromId, ['event' => 'invite_expired', 'invitation_id' => $invId]);
            }
        });
    }

    private function onInviteRespond(ConnectionInterface $conn, array $session, array $data): void
    {
        $invId    = (int) ($data['invitation_id'] ?? 0);
        $response = $data['response'] ?? '';
        $userId   = (int) $session['id'];

        if (!in_array($response, ['accept', 'decline'], true)) {
            $this->cm->sendToConnection($conn, ['event' => 'error', 'message' => Lang::get('errors.common.invalid_request')]);
            return;
        }

        $result = NumerController::respond($invId, $userId, $response);
        if (isset($result['error'])) {
            $this->cm->sendToConnection($conn, ['event' => 'error', 'message' => $result['error']]);
            return;
        }

        $db  = Connection::getInstance();
        $inv = $db->fetchOne('SELECT from_user_id FROM invitations WHERE id = ?', [$invId]);

        if ($result['declined'] ?? false) {
            $this->cm->sendToUser((int) $inv['from_user_id'], [
                'event'         => 'invite_declined',
                'invitation_id' => $invId,
            ]);
            return;
        }

        $roomId = $result['room_id'];
        $this->cm->joinRoom($conn, $roomId);
        $this->cm->sendToConnection($conn, [
            'event'   => 'numer_joined',
            'room_id' => $roomId,
            'members' => $result['members'],
        ]);
        $this->cm->sendToRoom($roomId, [
            'event'   => 'numer_participant_joined',
            'room_id' => $roomId,
            'user'    => $this->userPayload($session),
        ], $userId);
        $this->cm->sendToUser((int) $inv['from_user_id'], [
            'event'         => 'invite_accepted',
            'invitation_id' => $invId,
            'user'          => $this->userPayload($session),
        ]);
    }

    private function onLeaveNumer(ConnectionInterface $conn, array $session, array $data): void
    {
        $roomId = (int) ($data['room_id'] ?? 0);
        $userId = (int) $session['id'];

        $result = NumerController::leave($roomId, $userId);
        if (isset($result['error'])) {
            $this->cm->sendToConnection($conn, ['event' => 'error', 'message' => $result['error']]);
            return;
        }

        $this->cm->leaveRoom($userId, $roomId);

        if ($result['destroyed'] ?? false) {
            $this->cm->sendToRoom($roomId, ['event' => 'numer_destroyed', 'room_id' => $roomId]);
        } else {
            $this->cm->sendToRoom($roomId, [
                'event'   => 'numer_participant_left',
                'room_id' => $roomId,
                'user_id' => $userId,
            ]);

            if (!empty($result['owner_transferred']) && !empty($result['new_owner_id'])) {
                $newOwner = Connection::getInstance()->fetchOne(
                    'SELECT id, username, custom_status, nick_color, avatar_url, global_role FROM users WHERE id = ?',
                    [(int) $result['new_owner_id']]
                );
                if ($newOwner) {
                    $this->cm->sendToRoom($roomId, [
                        'event' => 'numer_owner_changed',
                        'room_id' => $roomId,
                        'owner' => $newOwner,
                    ]);
                }
            }
        }
    }

    private function onRoomAction(ConnectionInterface $conn, array $session, array $data): void
    {
        $roomId = (int) ($data['room_id'] ?? 0);
        $userId = (int) $session['id'];

        $result = \Chat\Chat\RoomController::manage($roomId, $userId, $session, $data);
        if (isset($result['error'])) {
            $this->cm->sendToConnection($conn, ['event' => 'error', 'message' => $result['error']]);
            return;
        }

        if ($result['deleted'] ?? false) {
            $this->cm->sendToRoom($roomId, ['event' => 'room_deleted', 'room_id' => $roomId]);
            return;
        }
        if ($result['kicked'] ?? false) {
            $this->cm->sendToUser((int) $result['target_user_id'], [
                'event'   => 'kicked_from_room',
                'room_id' => $roomId,
            ]);
            $this->cm->leaveRoom((int) $result['target_user_id'], $roomId);
        }
        if ($result['banned'] ?? false) {
            $this->cm->sendToUser((int) $result['target_user_id'], [
                'event'   => 'banned_from_room',
                'room_id' => $roomId,
            ]);
            $this->cm->leaveRoom((int) $result['target_user_id'], $roomId);
        }
        if ($result['muted'] ?? false) {
            $this->cm->sendToUser((int) $result['target_user_id'], [
                'event' => 'muted_in_room',
                'room_id' => $roomId,
                'muted_until' => $result['muted_until'] ?? null,
                'reason' => $result['reason'] ?? null,
            ]);
        }

        $this->cm->sendToRoom($roomId, ['event' => 'room_updated', 'room_id' => $roomId, 'data' => $result]);
    }

    private function broadcastSystemMessage(int $roomId, string $text, int $fromUserId): void
    {
        $content = htmlspecialchars($text, ENT_QUOTES);

        $db = Connection::getInstance();
        $db->execute(
            'INSERT INTO messages (room_id, user_id, content, content_hmac, type) VALUES (?, ?, ?, ?, ?)',
            [$roomId, $fromUserId, $content, '', 'system']
        );
        $msgId = (int) $db->lastInsertId();

        $this->cm->sendToRoom($roomId, [
            'event'   => 'system_message',
            'room_id' => $roomId,
            'message' => ['id' => $msgId, 'content' => $content, 'type' => 'system', 'created_at' => date('Y-m-d H:i:s')],
        ]);
    }

    private function getOnlineList(int $roomId, Connection $db): array
    {
        $userIds = $this->cm->getRoomUserIds($roomId);
        if (!$userIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        return $db->fetchAll(
            'SELECT u.id, u.username, u.custom_status, u.nick_color, u.avatar_url, u.global_role,
                    rm.room_role
             FROM users u
             JOIN room_members rm ON rm.room_id = ? AND rm.user_id = u.id
             WHERE u.id IN (' . $placeholders . ')
             ORDER BY u.username',
            array_merge([$roomId], $userIds)
        );
    }

    private function userPayload(array $session): array
    {
        return [
            'id'          => (int) $session['id'],
            'username'    => $session['username'],
            'custom_status' => $session['custom_status'] ?? null,
            'nick_color'  => $session['nick_color'],
            'avatar_url'  => $session['avatar_url'],
            'global_role' => $session['global_role'],
        ];
    }

    private function notifyGlobalStaffCall(int $roomId, array $session): void
    {
        $db = Connection::getInstance();
        $room = $db->fetchOne('SELECT name FROM rooms WHERE id = ?', [$roomId]);
        $staff = $db->fetchAll(
            "SELECT id FROM users WHERE global_role IN ('platform_owner', 'admin', 'moderator') AND is_banned = 0"
        );

        $message = [
            'room_id' => $roomId,
            'scope' => 'staff_call',
            'content' => sprintf(
                'Вызов персонала: %s позвал(а) в комнату "%s".',
                (string) ($session['username'] ?? 'Пользователь'),
                (string) ($room['name'] ?? ('#' . $roomId))
            ),
            'type' => 'system',
            'created_at' => date('Y-m-d H:i:s.000'),
        ];

        foreach ($staff as $member) {
            $staffId = (int) ($member['id'] ?? 0);
            if ($staffId === (int) ($session['id'] ?? 0)) {
                continue;
            }

            $this->cm->sendToUser($staffId, [
                'event' => 'system_message',
                'message' => $message,
            ]);
        }
    }

}
