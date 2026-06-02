# EVENT ROUTER MAP
> Audit date: 2026-05-31
> Source: src/WebSocket/EventRouter.php (690 lines, verified by full code read)
> All DB operations, service calls, and WS events verified against actual code.
> Line numbers reference EventRouter.php unless stated otherwise.

---

## File overview

```
src/WebSocket/EventRouter.php  690 lines
  __construct                   lines   26–31    6 ln
  route                         lines   32–70   39 ln   [ban check + dispatch]
  onJoinRoom                    lines   71–143  73 ln
  onLeaveRoom                   lines  144–169  26 ln
  onSendMessage                 lines  170–200  31 ln
  onDeleteMessage               lines  201–218  18 ln
  onSendWhisper                 lines  219–258  40 ln
  onInviteUser                  lines  259–282  24 ln
  scheduleInviteExpiry          lines  283–304  22 ln   [ReactPHP timer — in-memory]
  onInviteRespond               lines  305–357  53 ln
  onLeaveNumer                  lines  358–423  66 ln
  onGetRoomCounts               lines  424–436  13 ln
  onGetOnlineUsers              lines  437–450  14 ln
  handleRoomLeave               lines  451–487  37 ln   [disconnect cleanup, public]
  onRoomAction                  lines  488–562  75 ln
  getOnlineList                 lines  563–580  18 ln   [helper]
  userPayload                   lines  581–593  13 ln   [helper]
  startNumerCountdown           lines  594–628  35 ln   [ReactPHP timer — in-memory]
  cancelNumerCountdown          lines  629–641  13 ln   [ReactPHP timer]
  broadcastRoomCount            lines  642–657  16 ln   [helper]
  executePresenceCleanup        lines  658–682  25 ln   [force logout helper]
  executeForceLogout            lines  683–690   8 ln   [force logout helper]
```

---

## PRE-HANDLER: route() — ban check (lines 32–70)

Every inbound WS event passes through route() first.

```
Inbound: ANY event
→ EventRouter::route
→ Session::isUserBlocked(userId)
    DB Read:  users WHERE id=? (auto-expire timed bans + is_banned check)
    DB Write: users UPDATE is_banned=0... WHERE banned_until <= NOW() (auto-expire)
→ if blocked:
    Session::destroyAllForUser(userId)
    DB Write: sessions DELETE WHERE user_id=?
    cm->closeUser → Outbound: force_logout {reason:'banned_global'} → connection closed
→ if not blocked: dispatch to on*() handler
```

Outbound (ban case):
- `force_logout` → cm->closeUser (all user connections) → chat.js line 213 → forcedLogout + redirect

---

## HANDLER: onJoinRoom (lines 71–143, 73 lines)

```
Inbound Event: join_room {room_id}
→ EventRouter::onJoinRoom
```

### Services / Controllers called
- `cm->isInRoom(userId, roomId)` — WS presence check
- `cm->joinRoom(conn, roomId)` — WS presence set
- `RoomController::join(roomId, userId)` — DB INSERT room_members (public rooms only, if not already member)
- `SystemMessageService::emitRoomLifecycle(cm, roomId, userId, content, 'room_join')` — (public rooms only)
- `getOnlineList(roomId, db)` — DB READ users+room_members

### DB Read
- `rooms WHERE id=? (id, type, is_closed)` — room existence + type check
- `room_members WHERE room_id=? AND user_id=?` — membership + role check
- `users JOIN room_members` (via getOnlineList) — online user list for room_joined payload
- `users JOIN room_members WHERE room_id=? AND user_id=?` (inside RoomController::join) — duplicate check

### DB Write
- `room_members INSERT (roomId, userId, 'member')` — via RoomController::join, public rooms only, if not already member
- `messages INSERT type=system` — via SystemMessageService::emitRoomLifecycle, public rooms only

### Outbound WS events
| Event | Target | Condition |
|-------|--------|-----------|
| `room_joined {room_id, my_role, online[]}` | sendToConnection | always |
| `user_joined {room_id, user{}}` | sendToRoom (excl. sender) | public room, !alreadyInRoom |
| `system_message {room_join}` | sendToRoom | public room, !alreadyInRoom |
| `room_count_changed {room_id, count}` | sendToAll | public room, !alreadyInRoom |
| `numer_participant_joined {room_id, user{}}` | sendToRoom (excl. sender) | numer type, !alreadyInRoom |
| `room_count_changed` | sendToAll | numer type, !alreadyInRoom |
| `error` | sendToConnection | room not found / access denied |

### Frontend handlers
| Event | Handler | JS file |
|-------|---------|---------|
| `room_joined` | onRoomJoined(data) | chat.js line 132, fn line 340 |
| `user_joined` | onUserJoined(data) | chat.js line 133, fn line 355 |
| `system_message` | onSystemMessage(data.message) | chat.js line 141; chat-messages.js line 83 |
| `room_count_changed` | onRoomCountChanged(data) | chat.js line 174, fn line 690 |
| `numer_participant_joined` | case 'numer_participant_joined' | NumerPage.php inline JS line 331 |

---

## HANDLER: onLeaveRoom (lines 144–169, 26 lines)

```
Inbound Event: leave_room {room_id}
→ EventRouter::onLeaveRoom
```

### Services called
- `cm->isInRoom(userId, roomId)` — guard
- `cm->leaveRoom(userId, roomId)` — WS presence clear
- `SystemMessageService::emitRoomLifecycle(cm, roomId, userId, content, 'room_leave')`
- `broadcastRoomCount(roomId)`

### DB Read
- none direct (isInRoom is in-memory)

### DB Write
- `messages INSERT type=system scope='room_leave'` — via SystemMessageService

### Outbound WS events
| Event | Target |
|-------|--------|
| `user_left {room_id, user_id}` | sendToRoom |
| `system_message {room_leave}` | sendToRoom |
| `room_count_changed` | sendToAll |

### Frontend handlers
| Event | Handler | JS file |
|-------|---------|---------|
| `user_left` | onUserLeft(data) | chat.js line 134, fn line 362 |
| `system_message` | onSystemMessage | chat.js line 141; chat-messages.js line 83 |
| `room_count_changed` | onRoomCountChanged | chat.js line 174 |

---

## HANDLER: onSendMessage (lines 170–200, 31 lines)

```
Inbound Event: send_message {room_id, content}
→ EventRouter::onSendMessage
```

### Services / Controllers called
- `cm->isInRoom(userId, roomId)` — guard
- `cm->checkRateLimit(userId)` — in-memory rate limit
- `MessageController::send(roomId, userId, session, data)`:
  - DB READ: room_members (muted_until check)
  - DB READ: rooms (type, is_closed via canPost)
  - DB READ: room_members (ban check via canPost)
  - DB WRITE: messages INSERT (type=text)
  - DB READ: messages WHERE id=msgId (created_at fetch)
  - calls EmbedProcessor::process(raw)
- `SystemMessageService::emitModerationCall(cm, roomId, session)` — if content contains '@!'
  - DB READ: rooms WHERE id=?
  - DB READ: users WHERE global_role IN (platform_owner, admin, moderator) AND is_banned=0
  - DB READ: room_members (via canUserSeeAnyLayer, per-target)
  - NO DB WRITE (moderation call is ephemeral system message, not persisted)

### DB Read
- `room_members WHERE room_id=? AND user_id=? AND muted_until > NOW()`
- `rooms WHERE id=? (type, is_closed)`
- `room_members WHERE room_id=? AND user_id=?` (ban check)
- `messages WHERE id=? (created_at)`
- (if @!) `rooms WHERE id=?`, `users WHERE global_role IN (...)`, `room_members` per target

### DB Write
- `messages INSERT (room_id, user_id, sender_session_id, content, content_hmac, type, embed_data, nick_color, text_color)`

### Outbound WS events
| Event | Target |
|-------|--------|
| `new_message {message{}}` | sendToRoom |
| `system_message {moderation_call}` | sendToUser (per staff member, if @!) |
| `error` | sendToConnection (muted / not in room / rate limit) |

### Frontend handlers
| Event | Handler | JS file |
|-------|---------|---------|
| `new_message` | onNewMessage(data.message) | chat.js line 135; chat-messages.js line 74 |
| `system_message` | onSystemMessage | chat.js line 141; chat-messages.js line 83 |

---

## HANDLER: onDeleteMessage (lines 201–218, 18 lines)

```
Inbound Event: delete_message {message_id}
→ EventRouter::onDeleteMessage
```

### Services / Controllers called
- `MessageController::delete(msgId, userId, session)`:
  - DB READ: messages WHERE id=? AND is_deleted=0
  - Access::canDeleteMessage (DB READ: rooms type, room_members role)
  - DB WRITE: messages UPDATE is_deleted=1, deleted_by=?, deleted_at=NOW()

### DB Read
- `messages WHERE id=? AND is_deleted=0 (id, room_id, user_id)`
- `rooms WHERE id=? (type)` — via Access::canDeleteMessage
- `room_members WHERE room_id=? AND user_id=?` — via Access::canDeleteMessage

### DB Write
- `messages UPDATE is_deleted=1, deleted_by=?, deleted_at=NOW() WHERE id=?`

### Outbound WS events
| Event | Target |
|-------|--------|
| `message_deleted {message_id, room_id}` | sendToRoom |
| `error` | sendToConnection |

### Frontend handlers
| Event | Handler | JS file |
|-------|---------|---------|
| `message_deleted` | onMessageDeleted(data) | chat.js line 138; chat-messages.js line 79 |

---

## HANDLER: onSendWhisper (lines 219–258, 40 lines)

```
Inbound Event: send_whisper {room_id, to_user_id, content}
→ EventRouter::onSendWhisper
```

### Services / Controllers called
- `cm->isInRoom(fromId, roomId)` — sender room check
- `cm->isInRoom(toId, roomId)` — recipient room check
- `cm->checkWhisperLimit(fromId)` — in-memory rate limit
- `WhisperController::send(roomId, fromId, session, toId, content)`:
  - DB READ: room_members (muted_until check for sender)
  - DB READ: room_members JOIN users (recipient room_role + username)
  - DB WRITE: messages INSERT (type=whisper, whisper_to=toId)
  - DB READ: messages WHERE id=? (created_at)
  - DB READ: users WHERE id=toId

### DB Read
- `room_members WHERE room_id=? AND user_id=? AND muted_until > NOW()` (sender mute)
- `room_members rm JOIN users u WHERE rm.room_id=? AND rm.user_id=? AND rm.room_role != 'banned'`
- `messages WHERE id=?` (created_at)
- `users WHERE id=toId`

### DB Write
- `messages INSERT (room_id, user_id, sender_session_id, content, content_hmac, type='whisper', whisper_to)`

### Outbound WS events
| Event | Target | Condition |
|-------|--------|-----------|
| `whisper_sent {message{}}` | sendToUser(fromId) | always |
| `whisper_received {message{}}` | sendToUser(toId) | toId != fromId |
| `error` | sendToConnection | not in room / rate limit / muted |

### Frontend handlers
| Event | Handler | JS file |
|-------|---------|---------|
| `whisper_sent` | onWhisperMessage(data.message, true) | chat.js line 144; chat-messages.js line 97 |
| `whisper_received` | onWhisperMessage(data.message, false) | chat.js line 147; chat-messages.js line 97 |

---

## HANDLER: onInviteUser (lines 259–282, 24 lines) + scheduleInviteExpiry (lines 283–304, 22 lines)

```
Inbound Event: invite_user {to_user_id}
→ EventRouter::onInviteUser
```

### Services / Controllers called
- `NumerController::invite(fromId, session, toId)`:
  - DB READ: room_members (muted_until check for sender)
  - DB READ: invitations WHERE from_user_id=? AND status='pending' (pending count)
  - DB READ: users WHERE id=toId (exists, is_banned)
  - DB WRITE: invitations INSERT (room_id=NULL, from_user_id, to_user_id, expires_at=+30s)
- `scheduleInviteExpiry(invId, toId, fromId, 30)`:
  - ReactPHP Loop::addTimer(30s) — in-memory timer
  - Timer callback: DB READ invitations WHERE id=?, DB WRITE invitations SET status='expired'

### DB Read
- `room_members WHERE user_id=? AND muted_until > NOW()` (any room)
- `invitations WHERE from_user_id=? AND status='pending' (COUNT)`
- `users WHERE id=toId (id, username, is_banned)`

### DB Write
- `invitations INSERT (room_id=NULL, from_user_id, to_user_id, expires_at)`
- (timer, 30s later) `invitations UPDATE status='expired' WHERE id=? AND status='pending'`

### Outbound WS events
| Event | Target | Timing |
|-------|--------|--------|
| `invite_sent {invitation{}}` | sendToConnection (sender) | immediate |
| `invite_received {invitation{}}` | sendToUser (recipient) | immediate |
| `invite_expired {invitation_id}` | sendToUser(toId) + sendToUser(fromId) | T+30s (ReactPHP timer) |
| `error` | sendToConnection | muted / too many pending / user not found |

### Frontend handlers
| Event | Handler | JS file |
|-------|---------|---------|
| `invite_sent` | onInviteSent(data.invitation) | chat.js line 153; chat-numer.js line 19 |
| `invite_received` | onInviteReceived(data.invitation) | chat.js line 150; chat-numer.js line 37 |
| `invite_expired` | onInviteExpired(data) | chat.js line 162; chat-numer.js line 33 |

---

## HANDLER: onInviteRespond (lines 305–357, 53 lines)

```
Inbound Event: invite_respond {invitation_id, response: 'accept'|'decline'}
→ EventRouter::onInviteRespond
```

### Services / Controllers called
- `NumerController::respond(invId, userId, response)`:
  - DB READ: invitations WHERE id=? AND to_user_id=? AND status='pending' AND expires_at>NOW()
  - DB WRITE: invitations UPDATE status='accepted'|'declined', responded_at=NOW()
  - (accept) NumerController::findOrCreateOwnedNumer(fromUserId):
    - DB READ: rooms JOIN room_members WHERE owner_id=? AND type='numer' AND is_closed=0 (HAVING count<4)
    - DB WRITE: rooms INSERT (if new numer)
    - DB WRITE: room_members INSERT (owner, if new numer)
  - DB WRITE: invitations UPDATE room_id=? (accept)
  - DB READ: room_members WHERE room_id=? (member count check)
  - DB WRITE: room_members INSERT IGNORE (userId, 'member')
- `cancelNumerCountdown(roomId)` — cancel in-memory timer if active
- DB READ: `invitations WHERE id=? (from_user_id)` — direct in EventRouter
- DB READ: `rooms WHERE id=? (name)` — direct in EventRouter

### DB Read
- `invitations WHERE id=? AND to_user_id=? AND status='pending' AND expires_at>NOW()`
- `rooms JOIN room_members WHERE owner=fromId AND type='numer'` (findOrCreate)
- `room_members WHERE room_id=? (COUNT, member check)`
- `invitations WHERE id=? (from_user_id)` — EventRouter line 322
- `rooms WHERE id=? (name)` — EventRouter line 336

### DB Write
- `invitations UPDATE status, responded_at`
- `invitations UPDATE room_id` (accept)
- `rooms INSERT` (if new numer)
- `room_members INSERT` (owner of new numer, if new)
- `room_members INSERT IGNORE` (new member)
- (accept+full) `invitations UPDATE status='expired'` if numer full

### Outbound WS events
| Event | Target | Condition |
|-------|--------|-----------|
| `numer_joined {room_id, room_name, members[]}` | sendToConnection (responder) | accept |
| `invite_accepted {invitation_id, room_id, room_name, members[], user{}}` | sendToUser(fromId) | accept |
| `invite_declined {invitation_id}` | sendToUser(fromId) | decline |
| `error` | sendToConnection | invite not found / numer full |

### Frontend handlers
| Event | Handler | JS file |
|-------|---------|---------|
| `numer_joined` | onNumerJoined(data) | chat.js line 165; chat-numer.js line 14 |
| `invite_accepted` | onInviteAccepted(data) | chat.js line 156; chat-numer.js line 23 |
| `invite_declined` | onInviteDeclined(data) | chat.js line 159; chat-numer.js line 29 |

---

## HANDLER: onLeaveNumer (lines 358–423, 66 lines)

```
Inbound Event: leave_numer {room_id}
→ EventRouter::onLeaveNumer
```

### Services / Controllers called
- Direct DB READ: `room_members WHERE room_id=? AND user_id != ? AND room_role != 'banned'` (participants before leave)
- `NumerController::leave(roomId, userId)`:
  - DB READ: rooms WHERE id=? AND type='numer'
  - DB READ: room_members WHERE room_id=? AND user_id=? (role check)
  - DB WRITE: room_members DELETE WHERE room_id=? AND user_id=?
  - DB READ: room_members WHERE room_id=? AND room_role != 'banned' (remaining count)
  - DB WRITE: rooms UPDATE is_closed=1, close_reason='last_left' (if remaining=0)
  - (if wasOwner) DB READ: room_members WHERE room_id=? AND room_role='owner'
  - (if wasOwner) DB READ: room_members WHERE room_id=? ORDER BY joined_at ASC LIMIT 1
  - (if wasOwner) DB WRITE: room_members UPDATE room_role='owner'
  - (if wasOwner) DB WRITE: rooms UPDATE owner_id=newOwnerId
- `cm->leaveRoom(userId, roomId)` — WS presence clear
- `broadcastRoomCount(roomId)`
- `cancelNumerCountdown(roomId)` — (if destroyed)
- `startNumerCountdown(roomId)` — (if remaining=1)
- Direct DB READ: `rooms WHERE id=? (name)` — EventRouter line ~336 (via onInviteRespond ref, not here)
- (owner transfer) Direct DB READ: `users WHERE id=newOwnerId` — EventRouter line 404

### DB Read
- `room_members WHERE room_id=? AND user_id != ? AND room_role != 'banned'` (before leave)
- `rooms WHERE id=? AND type='numer'`
- `room_members WHERE room_id=? AND user_id=?` (role check)
- `room_members WHERE room_id=? AND room_role != 'banned'` (remaining count)
- `room_members WHERE room_id=? AND room_role='owner'` (guard)
- `room_members WHERE room_id=? ORDER BY joined_at ASC, user_id ASC LIMIT 1` (transfer candidate)
- `users WHERE id=newOwnerId (username, custom_status, nick_color, avatar_url, global_role)` (owner changed payload)
- (startNumerCountdown timer) `rooms WHERE id=? AND is_closed=0`
- (startNumerCountdown timer) `room_members WHERE room_id=?` (participant list for numer_destroyed)

### DB Write
- `room_members DELETE WHERE room_id=? AND user_id=?`
- `rooms UPDATE is_closed=1, close_reason='last_left'` (if destroyed)
- `room_members UPDATE room_role='owner'` (if transfer)
- `rooms UPDATE owner_id=newOwnerId` (if transfer)
- (startNumerCountdown timer, fires after NUMER_IDLE_TIMEOUT) `rooms UPDATE is_closed=1, close_reason='idle'`

### Outbound WS events
| Event | Target | Condition |
|-------|--------|-----------|
| `numer_left {room_id, destroyed}` | sendToConnection (leaver) | always (before leaveRoom) |
| `numer_destroyed {room_id}` | sendToUser(leaver) | always |
| `numer_destroyed {room_id}` | sendToRoom | if destroyed |
| `numer_destroyed {room_id}` | sendToUser(each participant) | if destroyed |
| `numer_participant_left {room_id, user_id}` | sendToRoom | if NOT destroyed |
| `numer_owner_changed {room_id, owner{}}` | sendToRoom | if owner transferred |
| `numer_countdown {room_id, seconds}` | sendToRoom | if remaining=1 (startNumerCountdown) |
| `numer_destroyed {room_id}` | sendToRoom + sendToUser(each) | after NUMER_IDLE_TIMEOUT timer fires |
| `room_count_changed` | sendToAll | always |
| `error` | sendToConnection | NumerController::leave returns error |

### Frontend handlers
| Event | Handler | JS file |
|-------|---------|---------|
| `numer_left` | ws.close() after cleanup | NumerPage.php inline line 369 |
| `numer_destroyed` | removeNumerFromSidebar(data.room_id) | chat.js line 175 |
| `numer_destroyed` | ws.close() + window.close() | NumerPage.php inline line 360 |
| `numer_participant_left` | members filter + renderParticipants | NumerPage.php inline line 341 |
| `numer_owner_changed` | members map + renderParticipants | NumerPage.php inline line 346 |
| `numer_countdown` | startCountdown(d.seconds) | NumerPage.php inline line 351 |
| `room_count_changed` | onRoomCountChanged | chat.js line 174 |

---

## HANDLER: onGetRoomCounts (lines 424–436, 13 lines)

```
Inbound Event: get_room_counts
→ EventRouter::onGetRoomCounts
```

### Services called
- `cm->getRoomUserIds(roomId)` — in-memory (per each public room)

### DB Read
- `rooms WHERE type='public' AND is_closed=0 (id list)`

### DB Write
- none

### Outbound WS events
| Event | Target |
|-------|--------|
| `room_counts {counts: {roomId: count}}` | sendToConnection |

### Frontend handlers
| Event | Handler | JS file |
|-------|---------|---------|
| `room_counts` | onWSConnected -> updateRoomBadge per room | chat.js line 168, fn line 229 |

---

## HANDLER: onGetOnlineUsers (lines 437–450, 14 lines)

```
Inbound Event: get_online_users
→ EventRouter::onGetOnlineUsers
```

### Services called
- `cm->getOnlineUserIds()` — in-memory

### DB Read
- `users WHERE id IN (?) AND is_banned=0 ORDER BY username (id, username, nick_color)`

### DB Write
- none

### Outbound WS events
| Event | Target |
|-------|--------|
| `online_users {users[]}` | sendToConnection |

### Frontend handlers
| Event | Handler | JS file |
|-------|---------|---------|
| `online_users` | renderInviteDropdown(d.users) | NumerPage.php inline line 357 |

---

## HANDLER: handleRoomLeave (lines 451–487, 37 lines)

```
Trigger: Server::onClose → 12s ReactPHP timer → handleRoomLeave(userId, roomId)
NOT a direct inbound WS event. Called from Server.php, not route().
```

### Services / Controllers called
- `cm->isInRoom(userId, roomId)` — guard
- `cm->leaveRoom(userId, roomId)` — WS presence clear
- `SystemMessageService::emitRoomLifecycle(...)` — public rooms only
- `broadcastRoomCount(roomId)`

### DB Read
- `rooms WHERE id=? (type)` — EventRouter line 458
- (public) `users WHERE id=? (username)` — EventRouter line 466 (for system message content)

### DB Write
- (public) `messages INSERT type=system scope='room_leave'` — via SystemMessageService

### Outbound WS events (public room)
| Event | Target |
|-------|--------|
| `user_left {room_id, user_id}` | sendToRoom |
| `system_message {room_leave}` | sendToRoom |
| `room_count_changed` | sendToAll |

### Outbound WS events (numer room)
| Event | Target |
|-------|--------|
| `numer_participant_left {room_id, user_id}` | sendToRoom |
| `room_count_changed` | sendToAll |

Note: numer branch does NOT touch room_members (presence-only leave, by design, commit 40035d7).

### Frontend handlers
| Event | Handler | JS file |
|-------|---------|---------|
| `user_left` | onUserLeft(data) | chat.js line 134 |
| `system_message` | onSystemMessage | chat.js line 141; chat-messages.js line 83 |
| `numer_participant_left` | members filter | NumerPage.php inline line 341 |
| `room_count_changed` | onRoomCountChanged | chat.js line 174 |

---

## HANDLER: onRoomAction (lines 488–562, 75 lines)

```
Inbound Event: room_action {room_id, action, ...}
→ EventRouter::onRoomAction
Actions: rename / delete / set_role / kick / ban / mute
```

### Services / Controllers called
- `RoomController::manage(roomId, userId, session, data)`:
  - resolvePermission (DB READ: room_members for room role)
  - action=rename: DB WRITE rooms UPDATE name
  - action=delete: RoomDeletionService::deleteWithDependencies (DB DELETE rooms + dependencies)
  - action=set_role: DB READ room_members JOIN users; DB WRITE room_members UPDATE room_role
  - action=kick: DB READ room_members JOIN users; DB WRITE room_members DELETE
  - action=ban: DB READ room_members JOIN users; DB WRITE room_members UPDATE room_role='banned', banned_at, banned_by
  - action=mute: DB READ room_members; DB WRITE room_members UPDATE muted_until, mute_reason; DB READ room_members (re-read muted_until)
- `cm->leaveRoom(targetId, roomId)` — (kick, ban)
- `SystemMessageService::emitRoomLifecycle(...)` — (kick, ban, role change)
- `broadcastRoomCount(roomId)` — (kick, ban)

### DB Read
- `room_members WHERE room_id=? AND user_id=?` (actor role, via resolvePermission)
- `room_members JOIN users WHERE room_id=? AND user_id=?` (target, all actions)
- (set_role) users.username via JOIN
- (mute) `room_members WHERE room_id=? AND user_id=? (muted_until re-read)`
- `rooms WHERE room_category` (delete guard)

### DB Write
- (rename) `rooms UPDATE name`
- (delete) rooms DELETE + cascades via RoomDeletionService
- (set_role) `room_members UPDATE room_role`
- (kick) `room_members DELETE WHERE room_id=? AND user_id=?`
- (ban) `room_members UPDATE room_role='banned', banned_at=NOW(), banned_by=?`
- (mute) `room_members UPDATE muted_until=DATE_ADD(NOW(), INTERVAL ? MINUTE), mute_reason=?`
- (kick/ban/role change) `messages INSERT type=system` via SystemMessageService

### Outbound WS events
| Event | Target | Condition |
|-------|--------|-----------|
| `room_deleted {room_id}` | sendToRoom | action=delete |
| `kicked_from_room {room_id}` | sendToUser(target) | action=kick |
| `user_left {room_id, user_id}` | sendToRoom | action=kick, action=ban |
| `system_message {room_kick}` | sendToRoom | action=kick |
| `banned_from_room {room_id}` | sendToUser(target) | action=ban |
| `system_message {room_ban}` | sendToRoom | action=ban |
| `muted_in_room {room_id, muted_until, reason}` | sendToUser(target) | action=mute |
| `room_updated {room_id, data{}}` | sendToRoom | all non-delete actions (at end of handler) |
| `system_message {room_role_changed}` | sendToRoom | action=set_role (if role changed) |
| `room_count_changed` | sendToAll | action=kick, action=ban |
| `error` | sendToConnection | no permission / invalid action |

### Frontend handlers
| Event | Handler | JS file |
|-------|---------|---------|
| `room_deleted` | onRoomDeleted(data) | chat.js line 194; chat-roomevents.js line 43 |
| `kicked_from_room` | onKickedFromRoom(data) | chat.js line 185; chat-roomevents.js line 5 |
| `banned_from_room` | onBannedFromRoom(data) | chat.js line 188; chat-roomevents.js line 20 |
| `muted_in_room` | onMutedInRoom(data) | chat.js line 191; chat-roomevents.js line 36 |
| `user_left` | onUserLeft(data) | chat.js line 134 |
| `room_updated` | inline: sidebar name + role | chat.js line 197 |
| `system_message` | onSystemMessage | chat.js line 141; chat-messages.js line 83 |
| `room_count_changed` | onRoomCountChanged | chat.js line 174 |

---

## SPECIAL: executePresenceCleanup (lines 658–682, 25 lines)

```
Trigger: executeForceLogout only (not directly from any inbound event currently).
Not reachable from any active code path as of this audit — executeForceLogout itself
has 0 call sites in route() or any other handler (verified by grep).
```

### DB Read
- none (getUserRooms is in-memory)

### DB Write
- none

### Outbound WS events
| Event | Target |
|-------|--------|
| `user_left {room_id, user_id}` | sendToRoom (per room) |
| `room_count_changed` | sendToAll (per room) |

---

## SPECIAL: executeForceLogout (lines 683–690, 8 lines)

```
Trigger: 0 call sites in route() handlers (verified by grep in EventRouter.php).
Defined and documented but currently unreachable from event flow.
The ban check in route() uses Session::destroyAllForUser + cm->closeUser directly.
```

### DB Write
- `sessions DELETE WHERE user_id=?` — via Session::destroyAllForUser

### Outbound WS events
| Event | Target |
|-------|--------|
| `force_logout {reason}` | cm->closeUser (all user connections) |

---

## HANDLER SUMMARY TABLE

| Handler | Lines | Code lines | Responsibilities | Direct DB ops | External deps | Outbound events |
|---------|-------|------------|-----------------|---------------|---------------|----------------|
| route() | 32–70 | 39 | Ban check + dispatch | 2 (read+write users, write sessions) | Session | force_logout |
| onJoinRoom | 71–143 | 73 | Join room (WS+DB), send initial state | 4 read, 2 write | RoomController, SystemMessageService | 6 |
| onLeaveRoom | 144–169 | 26 | Leave room (WS), lifecycle message | 0 direct, 1 write (via Svc) | SystemMessageService | 3 |
| onSendMessage | 170–200 | 31 | Send text message, moderation call | 4 read, 1 write | MessageController, SystemMessageService, EmbedProcessor | 2 (+conditional) |
| onDeleteMessage | 201–218 | 18 | Soft-delete message | 3 read, 1 write | MessageController, Access | 2 |
| onSendWhisper | 219–258 | 40 | Send whisper (DB persist, dual send) | 4 read, 1 write | WhisperController | 3 |
| onInviteUser | 259–282 | 24 | Create invitation, schedule 30s timer | 3 read, 1 write | NumerController, ReactPHP | 3 |
| scheduleInviteExpiry | 283–304 | 22 | ReactPHP 30s in-memory timer | 1 read, 1 write (deferred) | ReactPHP Loop | invite_expired |
| onInviteRespond | 305–357 | 53 | Accept/decline, create/find numer | 5 read, 5 write (accept) | NumerController | 3 |
| onLeaveNumer | 358–423 | 66 | Explicit numer leave, owner transfer, countdown | 8 read, 5 write | NumerController, ReactPHP | 8 |
| onGetRoomCounts | 424–436 | 13 | Send room counts to connection | 1 read | none | 1 |
| onGetOnlineUsers | 437–450 | 14 | Send online users list to connection | 1 read | none | 1 |
| handleRoomLeave | 451–487 | 37 | Disconnect cleanup (public + numer) | 2 read, 1 write (public only) | SystemMessageService | 3–4 |
| onRoomAction | 488–562 | 75 | 6 sub-actions (rename/delete/kick/ban/mute/role) | 4–6 read, 2–4 write | RoomController, SystemMessageService | 6 |
| startNumerCountdown | 594–628 | 35 | ReactPHP 30min idle timer | 2 read, 1 write (deferred) | ReactPHP Loop | numer_countdown, numer_destroyed |
| executePresenceCleanup | 658–682 | 25 | Force remove from all rooms | 0 | none | user_left, room_count_changed per room |
| executeForceLogout | 683–690 | 8 | Destroy sessions + presence + close conn | 1 write | Session | force_logout |

---

## ANALYSIS 1: Obligations currently inside EventRouter

1. **WS event routing** — match block in route()
2. **Auth/ban enforcement** — Session::isUserBlocked call + destroyAllForUser + closeUser in route()
3. **Room join business logic** — direct DB queries + delegate to RoomController + SystemMessageService
4. **Room leave business logic** — direct SystemMessageService call + WS broadcast
5. **Message send orchestration** — delegate + broadcast
6. **Message delete orchestration** — delegate + broadcast
7. **Whisper send orchestration** — delegate + dual broadcast
8. **Invite creation** — delegate to NumerController + **ReactPHP timer scheduling**
9. **Invite expiry timer** — ReactPHP addTimer, DB state update, WS events in-memory
10. **Invite response orchestration** — delegate + complex dual notify
11. **Numer leave orchestration** — delegate + owner transfer broadcast + countdown management
12. **Room action dispatch** — 6 sub-actions via RoomController + SystemMessageService + WS broadcasts
13. **Disconnect cleanup** — handleRoomLeave (public vs numer branching)
14. **Numer idle countdown timer** — startNumerCountdown/cancelNumerCountdown, ReactPHP timers, DB write on fire
15. **Room count broadcasting** — broadcastRoomCount helper (sendToAll)
16. **Force logout** — executeForceLogout (currently unreachable from event flow)

---

## ANALYSIS 2: Obligations that should be in separate services

| Obligation | Current location | Candidate service |
|-----------|-----------------|------------------|
| ReactPHP invite expiry timer (schedule + callback + DB + WS) | scheduleInviteExpiry lines 283–304 | NumerTimerService |
| ReactPHP numer countdown timer (start + cancel + DB + WS) | startNumerCountdown lines 594–628 + cancelNumerCountdown lines 629–641 | NumerTimerService |
| Disconnect cleanup logic (public vs numer branch) | handleRoomLeave lines 451–487 | DisconnectHandler |
| Force logout orchestration | executeForceLogout lines 683–690 | DisconnectHandler |
| Presence cleanup (remove from all rooms) | executePresenceCleanup lines 658–682 | DisconnectHandler |
| Room action sub-dispatch (6 actions, 75 lines) | onRoomAction lines 488–562 | RoomActionHandler |
| Numer event handling (invite user + respond + leave = 24+53+66 = 143 lines) | onInviteUser + onInviteRespond + onLeaveNumer | NumerEventHandler |

---

## ANALYSIS 3: Handlers with no shared dependencies — safe to extract first

Criteria: handlers that do NOT share in-memory state mutations with other handlers,
and whose DB operations are self-contained.

| Handler | Shared state? | Shared deps? | Extract risk |
|---------|--------------|--------------|--------------|
| `onGetRoomCounts` | reads cm only (no mutate) | none | **LOWEST** — pure read+send |
| `onGetOnlineUsers` | reads cm only (no mutate) | none | **LOWEST** — pure read+send |
| `onDeleteMessage` | no WS state mutation | MessageController, Access only | **LOW** |
| `onLeaveRoom` | cm->leaveRoom (mutates) | SystemMessageService | LOW-MEDIUM |
| `scheduleInviteExpiry` | no WS state mutation | ReactPHP Loop | **LOW** (but timer lifecycle couples it to onInviteUser) |
| `executeForceLogout` | 0 call sites currently | Session, cm | LOW (unreachable) |

**Best first candidates: onGetRoomCounts, onGetOnlineUsers** — zero business logic, zero external service calls, zero DB writes. Pure query-and-respond.

---

## ANALYSIS 4: Highest-risk handlers for refactoring

| Handler | Risk | Reason |
|---------|------|--------|
| `onLeaveNumer` | **CRITICAL** | 66 lines, 8 DB read, 5 write, 8 outbound events, ReactPHP timer side effects (start/cancel), owner transfer chain, all tightly coupled to ConnectionManager state |
| `onRoomAction` | **CRITICAL** | 75 lines, 6 sub-actions, direct coupling to RoomController result shape (duck-typed array), each branch has different outbound event set, SystemMessageService calls interleaved |
| `onJoinRoom` | **HIGH** | 73 lines, dual type handling (public/numer), alreadyInRoom guard, getOnlineList on every join, delegates to RoomController but also has direct DB |
| `onInviteRespond` | **HIGH** | 53 lines, multi-step transaction (invite→numer→members), cancelNumerCountdown side effect, result shape from NumerController drives branching, 5 outbound event variants |
| `startNumerCountdown` | **HIGH** | 35 lines, in-memory timer with 30min lifetime, closure captures $cm and $self (reference to EventRouter instance), DB write inside timer fires after possible restart |
| `onSendMessage` | **MEDIUM-HIGH** | 31 lines + @! conditional path triggers emitModerationCall which queries all staff users — N+1 risk at scale |
| `handleRoomLeave` | **MEDIUM** | Public vs numer branching, called from Server.php not from route(), shared with disconnect flow |

---

## TOP-10 HANDLERS BY COMPLEXITY

Scoring: lines × (DB ops + outbound events + external deps + branching paths)

| Rank | Handler | Lines | DB ops | Outbound events | External deps | Branch paths | Score |
|------|---------|-------|--------|----------------|---------------|--------------|-------|
| 1 | **onLeaveNumer** | 66 | 13 | 8 | 2 + ReactPHP | 4 (destroyed/not, owner/not, remaining=1) | **HIGHEST** |
| 2 | **onRoomAction** | 75 | 10 | 8 | 2 | 6 (one per action) | HIGH |
| 3 | **onJoinRoom** | 73 | 6 | 6 | 3 | 3 (public/numer, already/not) | HIGH |
| 4 | **onInviteRespond** | 53 | 10 | 3 | 2 | 3 (accept/decline/full) | HIGH |
| 5 | **startNumerCountdown** | 35 | 3 (deferred) | 2 (immediate+deferred) | ReactPHP | 2 (cancel+timer) | MEDIUM-HIGH |
| 6 | **onSendWhisper** | 40 | 5 | 3 | 1 | 2 (self/other) | MEDIUM-HIGH |
| 7 | **handleRoomLeave** | 37 | 3 | 4 | 1 | 2 (public/numer) | MEDIUM |
| 8 | **onInviteUser + scheduleInviteExpiry** | 24+22=46 | 4 | 3 | 2 + ReactPHP | 2 | MEDIUM |
| 9 | **onSendMessage** | 31 | 5 | 2+N(@!) | 3 | 2 (@! branch) | MEDIUM |
| 10 | **onDeleteMessage** | 18 | 3 | 2 | 2 | 1 | LOW-MEDIUM |
