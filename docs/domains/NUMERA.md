# Domain: Numera
> Audit: 2026-05-25 | All facts verified against source code.
> This file consolidates all Numera domain knowledge from the architectural audit.
> [UNVERIFIED] = not confirmed directly in code.

---

# NUMER MODEL
> Version: 1.0 | Audit date: 2026-05-25
> All facts verified against NumerController.php, EventRouter.php, NumerPage.php, ConnectionManager.php.
> `[UNVERIFIED]` marks any claim not confirmed directly in code.

---

## Core Separation (Critical)

```
room_members (DB table)      = SOURCE OF TRUTH for numer access/membership
ConnectionManager::roomMembers = PRESENCE CACHE for active WS popup window

These two are DECOUPLED.
A user can be in room_members (has access) but NOT in ConnectionManager::roomMembers (popup closed).
A user in ConnectionManager::roomMembers is always in room_members, but not vice versa.
```

This separation was introduced in commit 40035d7 and is an explicit architectural decision.

---

## Membership Model

**Table:** `room_members` (room_id, user_id, room_role=owner|member)

**Who is a member:**
- User added to room_members when invitation is accepted (NumerController::respond)
- User remains in room_members after closing the popup (popup disconnect does NOT delete membership)
- User is removed from room_members ONLY on explicit `leave_numer` WS event (NumerController::leave)
- User is removed from room_members when numer is destroyed (last member leaves or idle timeout)

**Access check:**
- EventRouter::onJoinRoom checks room_members for numer type (verified line 87–103)
- If not in room_members → access denied (no auto-join for numera, unlike public rooms)

---

## Presence Model

**Structure:** `ConnectionManager::roomMembers[roomId][userId] = true`

**When set:**
- cm->joinRoom(conn, roomId) called in EventRouter::onJoinRoom after room_members check passes

**When cleared:**
- cm->leaveRoom(userId, roomId) called in:
  - EventRouter::onLeaveRoom (explicit leave_room, public only)
  - EventRouter::handleRoomLeave — numer branch (popup disconnect, 12s grace period)
  - EventRouter::onLeaveNumer (before numer_destroyed broadcast)
  - EventRouter::onRoomAction (kick/ban — cm->leaveRoom for target)

**NOT cleared by:**
- Browser closing popup (triggers onClose → 12s timer → handleRoomLeave numer branch → cm->leaveRoom only, NOT room_members)

**Lost on WS restart:** yes. All ConnectionManager state is in-memory.

---

## Ownership Model

**Source:** `rooms.owner_id` + `room_members.room_role='owner'` (both must be in sync)

**Initial owner:** user who sent the invitation (from_user_id) — set in findOrCreateOwnedNumer

**Owner transfer (explicit leave):**
```
NumerController::leave(roomId, userId)
  if wasOwner:
    check if another 'owner' row already exists (shouldn't, but guard)
    SELECT user_id FROM room_members WHERE room_id=? AND room_role != 'banned'
      ORDER BY joined_at ASC, user_id ASC LIMIT 1
    UPDATE room_members SET room_role='owner' WHERE room_id=? AND user_id=newOwnerId
    UPDATE rooms SET owner_id=newOwnerId WHERE id=?
    EventRouter sends numer_owner_changed to room
```

**Owner transfer (disconnect):** NOT triggered — no transfer on popup disconnect.
**Owner transfer (idle timeout):** NOT triggered — numer is destroyed entirely.

---

## Creation Flow

```
Precondition: User A invites User B

invite_user WS (from A)
  -> EventRouter::onInviteUser
  -> NumerController::invite(fromId=A, session, toId=B)
       checks: A not muted, pending invites < INVITE_PENDING_MAX
       checks: B exists, B not banned
       INSERT invitations (room_id=NULL, status='pending', expires_at=+30s)
       returns invitation_id
  -> cm->sendToConnection(A, invite_sent)
  -> cm->sendToUser(B, invite_received)
  -> EventRouter::scheduleInviteExpiry(invId, toId=B, fromId=A, 30s)  [ReactPHP timer]

invite_respond WS (from B, response='accept')
  -> EventRouter::onInviteRespond
  -> NumerController::respond(invId, userId=B, 'accept')
       SELECT invitations WHERE status='pending' AND expires_at > NOW()
       UPDATE invitations SET status='accepted', responded_at=NOW()
       NumerController::findOrCreateOwnedNumer(fromUserId=A):
         SELECT rooms WHERE owner_id=A AND type='numer' AND is_closed=0
           HAVING member_count < 4 LIMIT 1
         if found: return existing roomId
         else:
           INSERT rooms (name='Нумер #A', type='numer', owner_id=A, max_members=4)
           INSERT room_members (roomId, userId=A, room_role='owner')
       UPDATE invitations SET room_id=roomId
       check: member count < 4
       INSERT IGNORE room_members (roomId, userId=B, room_role='member')
       return {accepted, room_id, members[]}
  -> EventRouter::cancelNumerCountdown(roomId)
  -> cm->sendToConnection(B, numer_joined {room_id, room_name, members})
  -> cm->sendToUser(A, invite_accepted {room_id, room_name, members, user{B info}})

Both A and B open /numer/{roomId} in popup window.
Each popup opens its own WS connection and sends join_room.
```

---

## Invite Flow

```
State: pending (in DB)
Timer: ReactPHP 30s in EventRouter::scheduleInviteExpiry

invite_user -> INSERT invitations(status='pending', room_id=NULL)
            -> sendToUser(B, invite_received)
            -> sendToConnection(A, invite_sent)
            -> schedule 30s timer
```

---

## Accept Flow

```
invite_respond(accept) -> NumerController::respond(accept)
  -> UPDATE invitations SET status='accepted', room_id=X
  -> INSERT room_members(A, owner) if not exists
  -> INSERT IGNORE room_members(B, member)
  -> sendToConnection(B, numer_joined)
  -> sendToUser(A, invite_accepted)
  -> cancelNumerCountdown(roomId) -- cancels solo countdown if A was alone
```

---

## Decline Flow

```
invite_respond(decline) -> NumerController::respond(decline)
  -> UPDATE invitations SET status='declined', responded_at=NOW()
  -> return {declined: true}
  -> EventRouter: sendToUser(A, invite_declined {invitation_id})
  [No DB change to rooms or room_members]
```

---

## Expire Flow

Two paths (both verified in code):

**Path 1 — ReactPHP timer (primary, EventRouter::scheduleInviteExpiry):**
```
T+30s: ReactPHP addTimer callback fires
  -> SELECT status FROM invitations WHERE id=?
  -> if status == 'pending':
       UPDATE invitations SET status='expired'
       sendToUser(B, invite_expired {invitation_id})
       sendToUser(A, invite_expired {invitation_id})
  -> else: no-op (already responded)
```

**Path 2 — Periodic DB cleanup (belt-and-suspenders, ws-server.php every 10s):**
```
NumerController::expireInvitations()
  -> UPDATE invitations SET status='expired'
     WHERE status='pending' AND expires_at <= NOW()
  [No WS events sent — only DB state update]
```

**Note:** Path 2 does NOT send WS events. If WS restarts and Path 1 timer was lost, B's modal stays open until they close it manually. DB state is correctly expired via Path 2.

---

## Disconnect Flow

```
Browser closes numer popup window (TCP disconnect, no explicit leave_numer)

Server::onClose(conn)
  -> cm->remove(conn)
  -> went_offline? (true if no other connections for this userId)
  -> pendingDisconnectTimers[userId] = ReactPHP 12s timer

After 12s, if user still offline:
  EventRouter::handleRoomLeave(userId, roomId)
    room.type == 'numer':
      cm->leaveRoom(userId, roomId)          <- WS presence only
      cm->sendToRoom(roomId, numer_participant_left {room_id, user_id})
      broadcastRoomCount(roomId)
      [room_members NOT touched]
      [NumerController::leave NOT called]
```

**User retains access.** On next popup open → join_room → room_members check passes → rejoins.

---

## Leave Flow (explicit)

```
User clicks "Покинуть" in numer popup

NumerPage sends: leave_numer WS event

EventRouter::onLeaveNumer(conn, session, data)
  -> SELECT room_members WHERE room_id=? AND user_id != ? AND room_role != 'banned'
     (participants before leave, for notification)
  -> NumerController::leave(roomId, userId)
       DELETE room_members WHERE room_id=? AND user_id=?
       count remaining members
       if remaining == 0:
         UPDATE rooms SET is_closed=1, close_reason='last_left'
         return {destroyed: true}
       if wasOwner:
         transfer to oldest remaining member
         return {owner_transferred, new_owner_id}
  -> sendToConnection(leaver, numer_left {room_id, destroyed})
  -> cm->leaveRoom(userId, roomId)          <- WS presence
  -> broadcastRoomCount(roomId)
  -> sendToUser(leaver, numer_destroyed)    <- always, so sidebar updates

  if destroyed:
    cancelNumerCountdown(roomId)
    sendToRoom(roomId, numer_destroyed)
    foreach participantsBeforeLeave:
      sendToUser(p.user_id, numer_destroyed)
  else:
    sendToRoom(roomId, numer_participant_left)
    if owner_transferred:
      sendToRoom(roomId, numer_owner_changed {owner: newOwnerData})
    if remaining == 1:
      startNumerCountdown(roomId)  <- 30min idle timer
```

---

## Destroy Flow

Three destroy paths:

**Path 1 — Last member explicit leave:**
```
NumerController::leave -> remaining == 0
  -> UPDATE rooms SET is_closed=1, close_reason='last_left'
  -> EventRouter sends numer_destroyed to room + each participant
```

**Path 2 — 30-minute idle timeout (1 participant solo):**
```
EventRouter::startNumerCountdown(roomId)
  -> ReactPHP addTimer(NUMER_IDLE_TIMEOUT ?? 1800, callback)
  -> sends numer_countdown {seconds} to room
  callback fires after 30min:
    -> check rooms WHERE id=? AND is_closed=0 (guard)
    -> UPDATE rooms SET is_closed=1, close_reason='idle'
    -> SELECT room_members WHERE room_id=? (all participants)
    -> sendToRoom(roomId, numer_destroyed)
    -> foreach participants: sendToUser(user_id, numer_destroyed)
    -> cm->clearRoom(roomId)
    -> broadcastRoomCount(roomId)
```

**Path 3 — Admin force close (HTTP):**
```
POST /api/admin/numera/{id}/close
  -> RoomManager::closeNumer
  -> UPDATE rooms SET is_closed=1, close_reason='admin'
  [No WS numer_destroyed event sent — HTTP action, no IPC to WS]
```

**Note on Path 3:** Admin close via HTTP does NOT push numer_destroyed to connected users.
Popup window stays open until next WS event or refresh. This is a known gap (no IPC).

---

## Timer management (in-memory, lost on WS restart)

| Timer | Location | Duration | Started by | Cancelled by |
|---|---|---|---|---|
| invite_expiry | EventRouter::numerTimers is wrong — actually anonymous, no handle stored | 30s | onInviteUser | N/A (one-shot), or no-op if already responded |
| numer_countdown | EventRouter::numerTimers[roomId] | NUMER_IDLE_TIMEOUT (default 1800s) | onLeaveNumer (remaining=1) | cancelNumerCountdown (on invite accept, on numer activity) |
| disconnect_grace | Server::pendingDisconnectTimers[userId] | 12s | onClose | onOpen (reconnect) |
