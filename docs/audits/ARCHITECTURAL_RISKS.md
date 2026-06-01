# Architectural Risks
> Audit: 2026-05-25 | Based on full architectural audit of the project.
> Severity: CRITICAL / HIGH / MEDIUM / LOW
> [UNVERIFIED] = not confirmed directly in code.

---

## RISK-1: No IPC between PHP-FPM and WS process

Severity: HIGH

PHP-FPM (HTTP) and WS process (ReactPHP) are two separate processes with NO IPC channel.

Consequences:
    - Global ban from admin panel is NOT pushed to open WS connections immediately
    - Admin force-close of numer (HTTP) does NOT send numer_destroyed to connected users
    - Role changes via HTTP admin panel are not reflected until WS reconnect
    - System-wide announcements from HTTP are impossible without WS restart

Mitigation in place: Session::isUserBlocked() called on EVERY WS event (lazy enforcement).
Remaining gap: Admin numer close, admin room delete, admin role change via HTTP.

---

## RISK-2: All WS state lost on process restart

Severity: HIGH

ConnectionManager state (connections, sessions, roomMembers, userRooms, rate limits) is in-memory.
EventRouter timers (numerTimers, invite expiry timers) are in-memory.
Server.php disconnect timers are in-memory.

On WS restart (deployment, crash, OOM):
    - All online users appear offline immediately
    - All room presence is cleared (empty rooms until users rejoin)
    - All pending invite expiry timers are lost
    - All numer countdown timers are lost
    - All reconnect grace timers are lost

Mitigations:
    - Invite expiry: DB periodic cleanup (10s) keeps DB state correct, no WS events sent
    - Numer countdown: NOT mitigated (NUMER_IDLE_TIMEOUT timer simply does not fire)

---

## RISK-3: Three parallel RBAC implementations can diverge

Severity: HIGH

AdminAccess.php, ChatRoomController::resolvePermission(), SecurityAccessContext.php
all implement the same numeric permission scale.

AccessContext is the most correct (covers all MODERATION_POLICY.md invariants including
I-1 self-action guard, I-3 platform_owner protection, scope check, maxDurationType).
It is NOT CONNECTED (0 call sites).

Risk: A permission change (new role, new action, change threshold) must be applied to
all three implementations. It is easy to miss one.

Current state: RoomController uses its own resolvePermission().
               EventRouter calls RoomController (goes through resolvePermission).
               Admin routes use Access.php.
               No code uses AccessContext.

---

## RISK-4: owner_id / room_role='owner' sync enforced only by application code

Severity: MEDIUM

rooms.owner_id and room_members.room_role='owner' must always agree.
There is no DB trigger or constraint enforcing this.

Code paths that update both:
    NumerController::leave (owner transfer) -- UPDATE both in sequence (not a transaction)
    RoomManager::changeOwner -- UPDATE both in a transaction (correct)
    RoomController::create -- INSERT both (correct)
    findOrCreateOwnedNumer -- INSERT both (correct)

Risk: If a code path updates one field and crashes/fails before updating the other,
the two fields become inconsistent.

---

## RISK-5: messages.room_id has no CASCADE DELETE

Severity: MEDIUM

When a room is deleted (RoomDeletionService::deleteWithDependencies or RoomManager::delete),
messages for that room remain as orphan rows.

This may be intentional (archive preservation) but is not documented.
Query performance degrades over time as orphan messages accumulate.

---

## RISK-6: WS invite timer fires vs DB cleanup race condition

Severity: LOW

If B responds to invite between the DB periodic cleanup (PATH 2) and the ReactPHP timer (PATH 1):
    - PATH 2 expires the invite in DB
    - PATH 1 fires, reads status='expired', sends invite_expired to both users
    But the invite was already accepted/declined. The status check in PATH 1 guards against this:
        if (inv && inv['status'] === 'pending') { ... }
    So PATH 1 is a no-op if already responded. This is safe.

If WS restarts before timer fires:
    - PATH 1 never fires
    - B's modal stays open indefinitely (UI stuck in pending state)
    - DB state is correctly expired by PATH 2

---

## RISK-7: Session snapshot staleness in WS

Severity: MEDIUM

On WS connect, Session::validate() returns a snapshot of the user row.
This snapshot is stored in ConnectionManager::sessions[connId] and used for the
entire lifetime of the WS connection.

Changes NOT reflected until reconnect:
    - username change (updateSettings)
    - nick_color / text_color change
    - global_role change (admin promotion/demotion)
    - can_create_room change
    - avatar_url change

The WS session could be stale for hours if the user does not reconnect.

---

## RISK-8: Invite spam via pending invites (partial mitigation only)

Severity: LOW

NumerController::invite checks: pending invites from A < INVITE_PENDING_MAX.
This limits the number of pending outgoing invites per user.
But there is no rate limit on the rate of sending (e.g., 10 invites per second then decline each).

---

## RISK-9: Admin close of numer leaves popup open (no IPC)

Severity: MEDIUM

POST /api/admin/numera/{id}/close updates rooms.is_closed=1 but sends no WS event.
Users with the numer popup open continue to see the popup as active.
Any message they send will fail (room_id is closed, join_room will fail).
Popup only closes when user navigates away or next WS event causes a check.

---

## RISK-10: No rate limit on invite_user WS event at flow level

Severity: LOW

NumerController::invite checks A is not muted.
NumerController::invite checks pending invites < INVITE_PENDING_MAX.
But there is no general rate limit on invite_user events in cm->checkRateLimit (that only covers messages).
A user could spam invite_user events as fast as the WS allows (within INVITE_PENDING_MAX pending limit).

---

## RISK-11: findOrCreateOwnedNumer shares existing numer across invitations

Severity: MEDIUM (UX and privacy)

findOrCreateOwnedNumer(fromUserId):
    SELECT rooms WHERE owner_id=fromUserId AND type='numer' AND is_closed=0
    HAVING member_count < 4 LIMIT 1

If A already has an active numer with C, and A invites B,
B joins the SAME numer as C. A, B, and C are now in the same room.

This is by design (4-person numer) but may surprise users A or B
who expected a private 1-on-1 room.

---

## Summary risk matrix

| ID | Risk | Severity | Mitigation |
|----|------|----------|-----------|
| RISK-1 | No IPC between processes | HIGH | Lazy ban check on every WS event |
| RISK-2 | WS state lost on restart | HIGH | DB periodic cleanup for invites only |
| RISK-3 | 3 parallel RBAC implementations | HIGH | None -- all 3 must be manually synced |
| RISK-4 | owner_id sync not atomic everywhere | MEDIUM | Partial (RoomManager::changeOwner uses transaction) |
| RISK-5 | messages orphan on room delete | MEDIUM | None -- orphan rows accumulate |
| RISK-6 | Timer vs DB race on invite | LOW | PATH 1 guards with status='pending' check |
| RISK-7 | WS session snapshot staleness | MEDIUM | None -- reconnect required |
| RISK-8 | Invite spam | LOW | INVITE_PENDING_MAX limit |
| RISK-9 | Admin numer close no WS push | MEDIUM | None -- requires IPC |
| RISK-10 | invite_user rate limit gap | LOW | INVITE_PENDING_MAX indirect limit |
| RISK-11 | findOrCreateOwnedNumer shares rooms | MEDIUM | By design, but undocumented UX |
