# Invite Lifecycle
> Version: 1.0 | Audit date: 2026-05-25
> All facts verified against NumerController.php and EventRouter.php.
> `[UNVERIFIED]` marks any claim not confirmed directly in code.

---

## States

```
ENUM(pending / accepted / declined / cancelled / expired)
```

Verified in: migrations.sql (invitations table definition).

| State | Description | Terminal |
|---|---|---|
| pending | Invitation created, awaiting response | No |
| accepted | B responded accept | Yes |
| declined | B responded decline | Yes |
| expired | 30s elapsed without response (DB or timer) | Yes |
| cancelled | Reserved in ENUM, never set by code | Yes (dead state) |

---

## State Diagram

```
                     [invite_user WS]
                           |
                           v
                       +-------+
                       |pending| (room_id=NULL)
                       +-------+
                      /    |    \
   [respond:accept]  /     |     \  [30s elapsed]
                    /   [respond:  \
                   /     decline]   \
                  v                  v
            +---------+         +---------+
            |accepted |         |declined |
            |(room_id  |         |         |
            | set)     |         |         |
            +---------+         +---------+

                             +-------+          +-----------+
                             |expired|          |cancelled  |
                             |       |          |(DEAD STATE|
                             +-------+          |never set) |
                                                +-----------+
```

---

## Transition: [NONE] -> pending

**Trigger:** `invite_user` WS event from User A
**Caller:** EventRouter::onInviteUser -> NumerController::invite

**DB changes:**
```sql
INSERT INTO invitations
  (room_id, from_user_id, to_user_id, expires_at)
VALUES
  (NULL, ?, ?, DATE_ADD(NOW(), INTERVAL 30 SECOND))
-- status defaults to 'pending' (ENUM default)
```

**WS events sent:**
- `invite_sent` -> cm->sendToConnection(A, {event:'invite_sent', invitation:{invitation_id, from, to_user_id, to_username, expires_at}})
- `invite_received` -> cm->sendToUser(B, {event:'invite_received', invitation:{...same payload...}})

**ReactPHP timer scheduled:**
- EventRouter::scheduleInviteExpiry(invId, toId=B, fromId=A, 30s)

**Guards checked before INSERT:**
- A not muted in any room (SELECT room_members WHERE user_id=A AND muted_until > NOW())
- A has fewer than INVITE_PENDING_MAX pending invitations
- B exists and is not banned (users.is_banned=0)

---

## Transition: pending -> accepted

**Trigger:** `invite_respond` WS event from User B, response='accept'
**Caller:** EventRouter::onInviteRespond -> NumerController::respond(invId, B, 'accept')

**DB changes:**
```sql
-- 1. Validate: must be pending and not expired
SELECT * FROM invitations
WHERE id=? AND to_user_id=B AND status='pending' AND expires_at > NOW()

-- 2. Update invitation status
UPDATE invitations
SET status='accepted', responded_at=NOW()
WHERE id=?

-- 3. Find or create numer owned by A
-- (findOrCreateOwnedNumer - see NUMER_MODEL.md)

-- 4. Set room_id on invitation
UPDATE invitations SET room_id=? WHERE id=?

-- 5. Add B as member
INSERT IGNORE INTO room_members (room_id, user_id, room_role)
VALUES (roomId, B, 'member')

-- rooms INSERT + room_members(A, owner) only if no existing numer
```

**WS events sent:**
- `numer_joined` -> cm->sendToConnection(B, {event:'numer_joined', room_id, room_name, members[]})
- `invite_accepted` -> cm->sendToUser(A, {event:'invite_accepted', invitation_id, room_id, room_name, members[], user:{B info}})

**Side effect:**
- EventRouter::cancelNumerCountdown(roomId) — cancels solo countdown if A was alone

---

## Transition: pending -> declined

**Trigger:** `invite_respond` WS event from User B, response='decline'
**Caller:** EventRouter::onInviteRespond -> NumerController::respond(invId, B, 'decline')

**DB changes:**
```sql
UPDATE invitations SET status='declined', responded_at=NOW() WHERE id=?
```

**WS events sent:**
- `invite_declined` -> cm->sendToUser(A, {event:'invite_declined', invitation_id})

**No DB changes to rooms or room_members.**

---

## Transition: pending -> expired (Path 1: ReactPHP timer)

**Trigger:** ReactPHP addTimer fires after 30 seconds
**Caller:** EventRouter::scheduleInviteExpiry callback

**DB changes:**
```sql
-- Guard: only if still pending
SELECT status FROM invitations WHERE id=?
-- if status == 'pending':
UPDATE invitations SET status='expired' WHERE id=?
```

**WS events sent:**
- `invite_expired` -> cm->sendToUser(B, {event:'invite_expired', invitation_id})
- `invite_expired` -> cm->sendToUser(A, {event:'invite_expired', invitation_id})

**Note:** This timer is in-memory. Lost on WS restart.

---

## Transition: pending -> expired (Path 2: Periodic DB cleanup)

**Trigger:** ReactPHP periodic timer every 10 seconds (ws-server.php)
**Caller:** NumerController::expireInvitations()

**DB changes:**
```sql
UPDATE invitations
SET status='expired'
WHERE status='pending' AND expires_at <= NOW()
```

**WS events sent:** NONE. No WS events sent from this path.

**Purpose:** Belt-and-suspenders for missed Path 1 timers (WS restart, crash).

---

## Transition: pending -> cancelled

**DEAD TRANSITION.** This state is defined in the ENUM but is never set by any code.

Verified by grep: no `status = 'cancelled'` or `status='cancelled'` assignment found in any PHP file.

---

## Edge cases and gaps

| Gap | Description |
|---|---|
| G1 | WS restart between invite_user and timer: B's modal stays open, DB gets expired via periodic cleanup, but no WS event is pushed to B |
| G2 | Numer full at accept time: NumerController::respond checks count >= 4 after creating room, sets status='expired', returns error. B receives error WS event, not invite_expired |
| G3 | Accepted invitations not cleaned up when numer closes: orphan rows remain with stale room_id |
| G4 | No rate limiting on invite_user beyond INVITE_PENDING_MAX pending count check |
| G5 | from_user_id CASCADE DELETE on users — invite disappears if A is deleted. to_user_id CASCADE DELETE — invite disappears if B is deleted |

---

## Payload reference (verified in NumerController::invite return)

**invite_received / invite_sent payload:**
```json
{
  "invitation_id": 42,
  "from": {
    "id": 1,
    "username": "alice",
    "avatar_url": "..."
  },
  "to_user_id": 2,
  "to_username": "bob",
  "expires_at": "2026-05-25T12:00:30Z"
}
```

**numer_joined payload** (EventRouter::onInviteRespond):
```json
{
  "event": "numer_joined",
  "room_id": 10,
  "room_name": "Нумер #1",
  "members": [...]
}
```

**invite_accepted payload** (EventRouter::onInviteRespond):
```json
{
  "event": "invite_accepted",
  "invitation_id": 42,
  "room_id": 10,
  "room_name": "Нумер #1",
  "members": [...],
  "user": { "id": 2, "username": "bob", ... }
}
```
