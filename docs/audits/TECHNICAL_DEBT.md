# Technical Debt
> Audit: 2026-05-25 | Based on full architectural audit of the project.
> Priority: HIGH / MEDIUM / LOW
> [UNVERIFIED] = not confirmed directly in code.

---

## TD-1: Three parallel RBAC implementations

Priority: HIGH
Files:
    src/Admin/Access.php (162 ln)
    src/Chat/RoomController.php -- resolvePermission() private inline
    src/Security/AccessContext.php (186 ln) -- NOT CONNECTED

All three implement the same numeric scale (6/5/4/3/2/1/0/-1).
AccessContext is the most complete and correct implementation (covers MODERATION_POLICY.md invariants).
It is not connected.
Any permission change must be made in 3 places.

Ideal fix: Wire AccessContext into EventRouter and RoomController.
Blocker: Requires PREP-C (full caller audit of all permission checks).

---

## TD-2: EventRouter is a God Object (690 lines, 12 responsibilities)

Priority: HIGH
File: src/WebSocket/EventRouter.php

All WS business logic lives in one class:
    - routing
    - room join/leave
    - message send/delete
    - whisper
    - invite create/respond
    - numer leave + owner transfer
    - room actions (kick/ban/mute/role/rename/delete)
    - ReactPHP timer management (invite expiry + numer countdown)
    - disconnect cleanup
    - force logout

Candidates for extraction (see GOD_OBJECTS.md):
    NumerTimerService, DisconnectHandler, RoomActionHandler, NumerEventHandler

Blocker: Tight coupling with ConnectionManager state.

---

## TD-3: UserManager.php mixes admin + self-service + file I/O (601 lines)

Priority: HIGH
File: src/Admin/UserManager.php

Admin user management and self-service user settings are in the same class.
Avatar upload with GD image processing also in the same class.
Ban metadata + list + unban + unmute all in the same class.

Candidates for extraction:
    AvatarService (uploadAvatar + headRequest ~100 ln)
    UserSettingsHandler (updateSettings ~150 ln)
    BanRepository (listBanned + roomUnban + roomUnmute ~100 ln)

---

## TD-4: Inline friend logic in Router.php

Priority: LOW
File: src/Http/Router.php

handleGetFriends, handleAddFriend, handleRespondFriend are inline methods in Router.
No separate FriendController class.
~60 lines of business logic in the router.

Fix: Extract to ChatFriendController.
No blockers.

---

## TD-5: WS in-memory timers lost on process restart

Priority: HIGH

Affected timers:
    invite expiry timers (30s) in EventRouter::scheduleInviteExpiry
    numer countdown timers (30min) in EventRouter::numerTimers[]
    disconnect grace timers (12s) in Server::pendingDisconnectTimers[]

On WS process restart:
    - All invite expiry timers lost. DB periodic cleanup (10s) handles DB state but sends no WS events.
      B's invite modal stays open indefinitely until B closes it manually.
    - All numer countdown timers lost. Solo numer participant never gets auto-closed.
    - Disconnect timers lost. Users who were in the grace period are left in limbo.

Mitigation: numer countdown timer state could be persisted to DB (scheduled_close_at column).
            invite expiry: DB has expires_at, periodic cleanup works for DB state.

---

## TD-6: messages.room_id has no CASCADE DELETE

Priority: MEDIUM
File: migrations.sql

When a room is deleted (RoomDeletionService::deleteWithDependencies),
messages for that room become orphan rows.
This is likely intentional (archive) but is not documented.

---

## TD-7: NumerPage.php has 200+ lines of inline JS

Priority: MEDIUM
File: src/Chat/NumerPage.php

The numer popup HTML renderer contains a full WS client (~200 lines JS).
This is not tested, not documented as a separate module, not in the JS dependency graph.

Fix: Extract to public/assets/js/numer-popup.js as a static file.

---

## TD-8: reactor_raw plaintext password field

Priority: DEFERRED (explicit product decision)
Field: users.reactor_raw

Plaintext password storage. Explicit product decision by owner.
Do not change without owner approval.

---

## TD-9: No UNIQUE constraint on friendships reverse pair

Priority: LOW
Table: friendships

No UNIQUE INDEX on (requester_id, addressee_id) that covers the reverse (addressee_id, requester_id).
INSERT IGNORE prevents exact duplicates but not reverse-pair duplicates.

---

## TD-10: status='cancelled' dead ENUM value in invitations

Priority: LOW
Table: invitations

ENUM(pending/accepted/declined/cancelled/expired)
'cancelled' is defined in the ENUM but never set by any code path.
Creates confusion about the invite lifecycle.

Fix: Either remove from ENUM (requires schema change) or document as reserved.

---

## TD-11: Admin HTTP close of numer sends no WS event

Priority: MEDIUM

POST /api/admin/numera/{id}/close (RoomManager::closeNumer)
    -> UPDATE rooms SET is_closed=1, close_reason='admin'
    [No numer_destroyed WS event sent -- no IPC between HTTP and WS process]

Popup windows for that numer stay open until user's next WS interaction or page refresh.

Fix requires IPC or a WS channel for server-to-client admin actions.

---

## TD-12: Global ban write path — second WS path not confirmed

Priority: LOW (downgraded: second path not found in code)

Path 1 (CONFIRMED): HTTP POST /api/admin/users/{id} -> UserManager::update -> UPDATE users

Path 2 (NOT CONFIRMED): No 'global_ban' action found in RoomController::manage() or EventRouter.php.
    The second WS write path described in earlier analysis does not exist in the current code.
    Global ban from the chat UI appears to route exclusively through the HTTP admin path.

Lazy enforcement applies to path 1: ban not pushed immediately, detected on next WS event.
