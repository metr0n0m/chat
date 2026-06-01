# WS EVENT MAP
> Version: 1.0 | Audit date: 2026-05-25
> All event names verified by grep against source code.
> `[UNVERIFIED]` marks any claim not confirmed directly in code.

---

## INBOUND EVENTS (Client → Server)

These are events the server accepts via `EventRouter::route()`.
Source: EventRouter.php match block, lines 54–68 (verified).

| Event | Handler method | Source (JS) | Notes |
|---|---|---|---|
| `join_room` | EventRouter::onJoinRoom | chat.js wsSend (onWSConnected, joinPublicRoom), NumerPage inline | Joins WS presence + sends room_joined back |
| `leave_room` | EventRouter::onLeaveRoom | chat.js wsSend (joinPublicRoom switch rooms) | Public rooms only |
| `send_message` | EventRouter::onSendMessage | chat-input-send.js wsSend | Requires isInRoom + rate limit |
| `delete_message` | EventRouter::onDeleteMessage | chat-input-send.js wsSend | Permission check in MessageController::delete |
| `send_whisper` | EventRouter::onSendWhisper | chat-input-send.js wsSend | Requires both users in same room |
| `invite_user` | EventRouter::onInviteUser | chat.js wsSend (line 467, 601), NumerPage inline (line 444) | Creates invitation, schedules 30s timer |
| `invite_respond` | EventRouter::onInviteRespond | chat-numer.js wsSend (accept line 65, decline line 70) | response=accept or decline |
| `leave_numer` | EventRouter::onLeaveNumer | NumerPage inline ws.send (line 401) | Deletes room_members, owner transfer |
| `room_action` | EventRouter::onRoomAction | chat.js wsSend (line 655), chat-sidebar.js wsSend (lines 66, 72) | action=rename/delete/set_role/kick/ban/mute |
| `get_online_users` | EventRouter::onGetOnlineUsers | NumerPage inline ws.send (line 418) | Returns online_users to connection only |
| `get_room_counts` | EventRouter::onGetRoomCounts | chat.js wsSend (onWSConnected line 231) | Returns room_counts to connection only |
| `ping` | inline in route() match | chat.js (implicit keep-alive) | Responds with pong immediately |

**Unrecognized events:** routed to `default` match arm — sends `error` event back to connection.

---

## OUTBOUND EVENTS (Server → Client)

Events sent from PHP (EventRouter / Server / SystemMessageService) to browser clients.

### Connection lifecycle

| Event | Sent by | Method | Recipient | Frontend handler | JS file |
|---|---|---|---|---|---|
| `connected` | Server::onOpen | conn->send | connection | onWSConnected(data) | chat.js line 131 |
| `error` | EventRouter (various) | cm->sendToConnection | connection | showToast(data.message) | chat.js line 224 |
| `pong` | EventRouter::route inline | conn->send | connection | case 'pong': break | chat.js line 225 |
| `force_logout` | EventRouter::executeForceLogout, EventRouter::route (ban check) | cm->closeUser | user (all connections) | forcedLogout + redirect | chat.js line 213 |

### Room presence

| Event | Sent by | Method | Recipient | Frontend handler | JS file |
|---|---|---|---|---|---|
| `room_joined` | EventRouter::onJoinRoom | cm->sendToConnection | connection | onRoomJoined(data) | chat.js line 132, fn line 340 |
| `user_joined` | EventRouter::onJoinRoom | cm->sendToRoom (exclude sender) | room (public only) | onUserJoined(data) | chat.js line 133, fn line 355 |
| `user_left` | EventRouter::onLeaveRoom, onRoomAction (kick/ban), handleRoomLeave (public), executePresenceCleanup | cm->sendToRoom | room | onUserLeft(data) | chat.js line 134, fn line 362 |
| `room_counts` | EventRouter::onGetRoomCounts | cm->sendToConnection | connection | onWSConnected -> updateRoomBadge | chat.js line 168 |
| `room_count_changed` | EventRouter::broadcastRoomCount | cm->sendToAll | ALL connections | onRoomCountChanged(data) | chat.js line 174, fn line 690 |

### Messages

| Event | Sent by | Method | Recipient | Frontend handler | JS file |
|---|---|---|---|---|---|
| `new_message` | EventRouter::onSendMessage | cm->sendToRoom | room | onNewMessage(data.message) | chat.js line 135-137; onNewMessage fn in chat-messages.js line 74 |
| `message_deleted` | EventRouter::onDeleteMessage | cm->sendToRoom | room | onMessageDeleted(data) | chat.js line 138-140; fn in chat-messages.js line 79 |
| `system_message` | SystemMessageService::emitRoomLifecycle, emitModerationCall | cm->sendToRoom or cm->sendToUser | room or targeted users | onSystemMessage(data.message) | chat.js line 141-143; fn in chat-messages.js line 83 |
| `whisper_sent` | EventRouter::onSendWhisper | cm->sendToUser (sender) | sender user | onWhisperMessage(data.message, true) | chat.js line 144-146; fn in chat-messages.js line 97 |
| `whisper_received` | EventRouter::onSendWhisper | cm->sendToUser (recipient) | recipient user | onWhisperMessage(data.message, false) | chat.js line 147-149; fn in chat-messages.js line 97 |

### Invitations

| Event | Sent by | Method | Recipient | Frontend handler | JS file |
|---|---|---|---|---|---|
| `invite_sent` | EventRouter::onInviteUser | cm->sendToConnection | sender connection | onInviteSent(data.invitation) | chat.js line 153-155; fn in chat-numer.js line 19 |
| `invite_received` | EventRouter::onInviteUser | cm->sendToUser | to_user | onInviteReceived(data.invitation) | chat.js line 150-152; fn in chat-numer.js line 37 |
| `invite_accepted` | EventRouter::onInviteRespond | cm->sendToUser (from_user) | inviter | onInviteAccepted(data) | chat.js line 156-158; fn in chat-numer.js line 23 |
| `invite_declined` | EventRouter::onInviteRespond | cm->sendToUser (from_user) | inviter | onInviteDeclined(data) | chat.js line 159-161; fn in chat-numer.js line 29 |
| `invite_expired` | EventRouter::scheduleInviteExpiry (ReactPHP 30s timer) | cm->sendToUser | both users (to_user + from_user) | onInviteExpired(data) | chat.js line 162-164; fn in chat-numer.js line 33 |

### Numera

| Event | Sent by | Method | Recipient | Frontend handler | JS file |
|---|---|---|---|---|---|
| `numer_joined` | EventRouter::onInviteRespond | cm->sendToConnection | responder connection | onNumerJoined(data) | chat.js line 165-167; fn in chat-numer.js line 14 |
| `numer_left` | EventRouter::onLeaveNumer | cm->sendToConnection | leaver connection | case 'numer_left': ws.close() | NumerPage.php inline line 369 |
| `numer_destroyed` | EventRouter::onLeaveNumer (last member), EventRouter::startNumerCountdown (30min idle) | cm->sendToUser (leaver always), cm->sendToRoom + each participant sendToUser | leaver + room + all participants | removeNumerFromSidebar(data.room_id) | chat.js line 175-177, fn line 370; NumerPage.php inline line 360 |
| `numer_participant_joined` | EventRouter::onJoinRoom (numer type) | cm->sendToRoom (exclude sender) | room | case 'numer_participant_joined' | NumerPage.php inline line 331 |
| `numer_participant_left` | EventRouter::onLeaveNumer (not destroyed), EventRouter::handleRoomLeave (numer disconnect) | cm->sendToRoom | room | case 'numer_participant_left' | NumerPage.php inline line 341 |
| `numer_owner_changed` | EventRouter::onLeaveNumer (owner transfer) | cm->sendToRoom | room | case 'numer_owner_changed' | NumerPage.php inline line 346 |
| `numer_countdown` | EventRouter::startNumerCountdown | cm->sendToRoom | room | case 'numer_countdown': startCountdown(d.seconds) | NumerPage.php inline line 351 |
| `numer_countdown_cancelled` | EventRouter::cancelNumerCountdown | cm->sendToRoom | room | case 'numer_countdown_cancelled': stopCountdown() | NumerPage.php inline line 354 |

### Room management

| Event | Sent by | Method | Recipient | Frontend handler | JS file |
|---|---|---|---|---|---|
| `kicked_from_room` | EventRouter::onRoomAction (kicked branch) | cm->sendToUser | target user | onKickedFromRoom(data) | chat.js line 185-187; fn in chat-roomevents.js line 5 |
| `banned_from_room` | EventRouter::onRoomAction (banned branch) | cm->sendToUser | target user | onBannedFromRoom(data) | chat.js line 188-190; fn in chat-roomevents.js line 20 |
| `muted_in_room` | EventRouter::onRoomAction (muted branch) | cm->sendToUser | target user | onMutedInRoom(data) | chat.js line 191-193; fn in chat-roomevents.js line 36 |
| `room_deleted` | EventRouter::onRoomAction (deleted branch) | cm->sendToRoom | room | onRoomDeleted(data) | chat.js line 194-196; fn in chat-roomevents.js line 43 |
| `room_updated` | EventRouter::onRoomAction (default at end) | cm->sendToRoom | room | inline: update sidebar name + role | chat.js line 197-206 |

### Online users (Numer popup only)

| Event | Sent by | Method | Recipient | Frontend handler | JS file |
|---|---|---|---|---|---|
| `online_users` | EventRouter::onGetOnlineUsers | cm->sendToConnection | connection | renderInviteDropdown(d.users) | NumerPage.php inline line 357 |

### Friend events (stub handlers — NOT sent by PHP)

| Event | Sent by | Method | Recipient | Frontend handler | JS file | Note |
|---|---|---|---|---|---|---|
| `friend_online` | NOT IMPLEMENTED in PHP | — | — | loadFriends() | chat.js line 207 | Case handler exists, no PHP sender found |
| `friend_offline` | NOT IMPLEMENTED in PHP | — | — | loadFriends() | chat.js line 208 | Case handler exists, no PHP sender found |

---

## Event name discrepancy check

Verified by grep across EventRouter.php and chat.js:

| PHP sends (EventRouter.php) | JS handles (chat.js) | Match |
|---|---|---|
| `kicked_from_room` (line 507) | `case 'kicked_from_room'` (line 185) | MATCH |
| `banned_from_room` (line 520) | `case 'banned_from_room'` (line 188) | MATCH |
| `muted_in_room` (line 532) | `case 'muted_in_room'` (line 191) | MATCH |

There are no events named `user_kicked`, `user_banned`, or `user_muted` in the codebase.
The canonical names are `kicked_from_room`, `banned_from_room`, `muted_in_room`.

---

## Disconnect flow (verified in Server.php and EventRouter.php)

```
TCP close / Ratchet::onClose(conn)
  -> ConnectionManager::remove(conn)
       -> removes from connections[], sessions[], userConnections[]
       -> returns {session, rooms[], went_offline}

  -> if went_offline (no other connections for this userId):
       Server.php: pendingDisconnectTimers[userId] = ReactPHP timer (12s)

  -> after 12 seconds, if cm->isUserOnline(userId) is false:
       foreach rooms as roomId:
         EventRouter::handleRoomLeave(userId, roomId)
           -> if room.type == public:
                cm->leaveRoom(userId, roomId)          [WS presence only]
                cm->sendToRoom(room, user_left)
                SystemMessageService::emitRoomLifecycle (room_leave)
                broadcastRoomCount(roomId)
           -> if room.type == numer:
                cm->leaveRoom(userId, roomId)          [WS presence only]
                cm->sendToRoom(room, numer_participant_left)
                broadcastRoomCount(roomId)
                [room_members NOT touched]
```

RECONNECT_GRACE_SECONDS = 12 (Server.php line 15, verified).
If user reconnects within 12s, timer is cancelled, presence is preserved.
