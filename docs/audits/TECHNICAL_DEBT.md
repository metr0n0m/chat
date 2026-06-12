# Technical Debt
> Last updated: 2026-06-12 | Sanctions engine S0+S1 landed: SanctionService is the single
> apply/lift path, AccessContext is wired (DB-first role resolution), test infrastructure
> exists (PHPUnit 13, 115 integration/unit tests). See docs/architecture/SANCTIONS_ENGINE.md.
> Previous: 2026-06-02 | Full architectural audit + RBAC validation complete.
> Status markers: [ACTIVE] current sprint | [BACKLOG] no immediate action | [DECISION] owner approval required | [FIXED] resolved in code | [CLOSED] invalid or superseded

---

## FIXED

### TD-1: RBAC — resolvePermission duplication
Fixed: 6e14243 2026-06-04
Files:
    src/Admin/Access.php
    src/Chat/RoomController.php

    resolvePermission() больше не дублирует level-логику.
    Access::resolveLevel() стал единственным источником шкалы уровней.
    RoomController::resolvePermission() остался thin wrapper:
      вызывает Access::resolveLevel() для числового уровня,
      отдельно читает room_role только для room-local ролей (level 0–3).
    Контракт ?array{level:int, role?:string} сохранён.
    AccessContext не использовался.

---

### BUG-1..BUG-4 — RBAC security bugs
Fixed: efe117c 2026-06-02
Files: src/Chat/RoomController.php

    BUG-1: Self-action guard added to manage() — actor cannot kick/ban/mute/set_role themselves.
    BUG-2: kick() — platform_owner target now protected (u.global_role added to SELECT + guard).
    BUG-3: ban() — platform_owner target now protected (identical fix to BUG-2).
    BUG-4: setRoomRole() — explicit policy enforcement for local_admin and local_moderator
           assignment and demotion. Replaced level-threshold with role-explicit checks.
           local_moderator cannot assign or remove local_moderator (per owner-defined policy).

47/47 validation scenarios pass (RBAC_VALIDATION_AUDIT.md).

---

## CLOSED

### TD-12: Global ban second WS write path
Status: CLOSED AS INVALID
Verified by grep + full read of RoomController::manage() switch.
No 'global_ban' action exists. HTTP admin path is the only confirmed write path.
Removed from active tracking.

---

## ACTIVE

### TD-2: EventRouter — timer state ownership
Priority: MEDIUM (downgraded from HIGH — decomposition not the goal)
File: src/WebSocket/EventRouter.php (690 lines)

Background:
    EVENT_ROUTER_MAP.md completed 2026-05-31. All 22 methods mapped with DB ops,
    call sites, and outbound events.

What the audit found:
    Business logic is ALREADY separated into controllers:
    RoomController::manage(), MessageController::send(), NumerController::leave(), etc.
    EventRouter primarily orchestrates calls and broadcasts WS events.
    Handlers do not call each other. Inter-handler coupling is zero.
    "Too many lines in one file" is not an architectural problem when coupling is absent.

Real problem 1: numerTimers[] state ownership
    EventRouter holds:
        private array $numerTimers = [];
    This is long-lifetime state (timers fire up to 30 minutes later) on an object
    whose primary role is event routing.
    startNumerCountdown() creates a closure capturing $self — a reference to the
    entire EventRouter object — held by ReactPHP for up to 1800 seconds.
    This state does not belong on the router.

    Candidate action: move numerTimers[] and the three timer methods
    (scheduleInviteExpiry, startNumerCountdown, cancelNumerCountdown)
    to a dedicated object, or consolidate with Server::pendingDisconnectTimers[]
    which already handles similar long-lifetime timer state.
    Not a full decomposition — a state ownership correction.

Real problem 2: executeForceLogout — 0 call sites
    EventRouter::executeForceLogout() (lines 683-690) is defined and documented
    but has zero call sites in EventRouter itself.
    The ban check in route() (lines 43-50) duplicates part of its logic directly:
        Session::destroyAllForUser + cm->closeUser inline.
    Either: activate executeForceLogout (replace inline logic with method call),
    or: remove it (dead code).
    Current state creates silent drift risk between two paths.

Full EventRouter decomposition (NumerTimerService, DisconnectHandler, RoomActionHandler,
NumerEventHandler) is NOT an active goal. The file size is not a problem.
The two issues above are the only confirmed real problems.

Next recommended steps:
    Step 2a: Decide fate of executeForceLogout (activate or delete). ~5 lines either way.
             Does NOT require test infrastructure. Atomic change, easily verified manually.

    Step 2b: Resolve numerTimers ownership. Scope to be determined.
             Recommended AFTER resolution of TD-NEW-1 (test coverage decision).
             Rationale: moving numerTimers[] touches EventRouter constructor, Server.php,
             and ws-server.php. A regression in numer countdown or invite expiry timing
             is only detectable in production without automated coverage.
             Step 2b is blocked on TD-NEW-1 infrastructure decision, not on Step 2a.

---

## BACKLOG

### TD-3: UserManager.php mixes admin + self-service + file I/O (601 lines)
Priority: LOW (no active plan)
File: src/Admin/UserManager.php

Extraction candidates documented in GOD_OBJECTS.md:
    AvatarService (uploadAvatar + headRequest ~100 ln)
    UserSettingsHandler (updateSettings ~150 ln)
    BanRepository (listBanned + roomUnban + roomUnmute ~100 ln)

No immediate action. Revisit when a specific extraction has a concrete use case.

---

### TD-4: Inline friend logic in Router.php
Priority: LOW
File: src/Http/Router.php

handleGetFriends, handleAddFriend, handleRespondFriend are inline private methods in Router.
No separate FriendController.
~60 lines of business logic.

Note: 3 of the 9 untraced API routes (TD-NEW-2) are these friend endpoints.
Clarify caller status before extracting.

---

### TD-5: WS in-memory timers lost on process restart
Priority: MEDIUM (monitoring, no active fix planned)

Affected:
    invite expiry (30s): EventRouter::scheduleInviteExpiry — anonymous one-shot timer
    numer countdown (30min): EventRouter::numerTimers[roomId]
    disconnect grace (12s): Server::pendingDisconnectTimers[userId]

On WS restart:
    invite expiry: DB periodic cleanup (10s) corrects DB state. No WS push to client.
                   Client modal stays open until manually closed. Acceptable.
    numer countdown: not mitigated. Solo participant never receives auto-close.
    disconnect grace: users in grace period left in limbo. Reconnect resolves it.

Partial mitigation possible: persist numer countdown deadline to DB (rooms.close_at column).
No schema change planned until confirmed production need.

---

### TD-6: messages.room_id has no CASCADE DELETE
Priority: LOW (monitoring)
File: migrations.sql

Orphan rows accumulate after room deletion. Likely intentional (archive preservation).
Not documented. No performance complaints. Revisit if message table grows unexpectedly.

---

### TD-7: NumerPage.php has ~200 lines of inline JS
Priority: LOW
File: src/Chat/NumerPage.php

Self-contained WS client inside PHP renderer. Not in JS dependency graph.
Extract to numer-popup.js when NumerPage is otherwise being modified.
Not worth a standalone change.

---

### TD-9: No UNIQUE constraint on friendships reverse pair
Priority: LOW (schema change required)
Table: friendships

INSERT IGNORE prevents exact duplicates but not (B,A) after (A,B).
Schema change needed. No confirmed production impact. Backlog.

---

### TD-10: status='cancelled' dead ENUM in invitations
Priority: LOW (schema change required)
Table: invitations

'cancelled' defined in ENUM, never set by any code. Confirmed by grep.
Either remove (schema change) or document as reserved for future use.
No active plan.

---

### TD-11: Admin HTTP close of numer sends no WS event
Priority: MEDIUM (known gap, no fix path without IPC)

POST /api/admin/numera/{id}/close sets rooms.is_closed=1 but sends no numer_destroyed.
Connected popup stays open until next WS interaction.
Fix requires IPC between PHP-FPM and WS process. Deferred.

---

### TD-NEW-1: Zero automated test coverage
Status: FIXED 2026-06-12 — PHPUnit 13 + integration DB (chat_test, rebuilt from real schema
each run inside chat_php container). 115 tests / 219 assertions: full RBAC matrix
(RoomModerationRbacTest), numer lifecycle (NumerControllerTest), sanctions engine
(SanctionServiceTest), SSRF guards (SafeHttpClientTest).
Run: docker exec -w /var/www/chat chat_php php vendor/bin/phpunit
Original assessment kept below for history.

Priority: HIGH (acknowledged, no immediate action)

Current state: 0 tests. rbac_test.php (temporary simulation) deleted after BUG-4 fix.
47 RBAC scenarios exist in RBAC_VALIDATION_AUDIT.md.

Real cost assessment (2026-06-02 review):
    phpunit/phpunit is not the blocker — it is a composer require-dev.
    The blocker is that all production code uses Connection::getInstance() as a
    static singleton with no DI. Unit tests for controllers require either:
    (a) integration test DB — 1-2 days infrastructure setup, or
    (b) refactoring for mockability — production code changes, not zero-risk.
    The rbac_test.php approach (simulate logic without calling actual code) does not
    provide regression protection against refactoring.

Status: acknowledged as important, not immediately actionable without infrastructure decision.
Prerequisite for: TD-1 (resolvePermission replacement), TD-2b (numerTimers ownership move).
Does NOT block: TD-2a (executeForceLogout — atomic, manually verifiable),
                TD-NEW-2 (API routes — docs only, no code change).

---

### TD-NEW-2: 9 API routes with no confirmed JS caller
Status: CLOSED — all 9 classified 2026-06-04

Results (grep across public/assets/js/, public/index.php, src/Chat/NumerPage.php):

    GET  /api/rooms/{id}/members    → ACTIVE — called by NumerPage.php refreshNumer()
    GET  /api/users/find            → DEAD   — no caller found anywhere
    POST /api/friends/{id}/respond  → DEAD   — friendship accept/decline UI does not exist
    GET  /api/admin/rooms/{id}/members → DEAD — no caller found
    GET  /api/admin/whispers        → DEAD   — JS uses /api/admin/whispers/sessions only
    DELETE /api/admin/whispers/{id} → DEAD   — no caller found
    POST /api/admin/whispers/clear  → DEAD   — no caller found
    GET  /api/admin/moderators      → DEAD   — no caller found
    GET  /api/admin/room-creators   → DEAD   — no caller found

API_MAP.md updated with confirmed statuses.
Code not changed (endpoints not deleted per constraints).

---

### TD-NEW-4: Namespace notation in 11 docs files
Priority: LOW (cosmetic, docs only)

DOCUMENTATION_QA.md §3 documents 11 files where PHP namespace backslashes were stripped
during node.js-based generation (Chat\RoomController → ChatRoomController, etc.).
Text-only fix. No code changes. No urgency.

---

## OWNER DECISIONS PENDING

### TD-NEW-3: BUG-5 — cross-room mute blocks numer invite
Status: OWNER DECISION REQUIRED. No code change until decision received.

Confirmed in RBAC_VALIDATION_AUDIT.md Claim 5:
    NumerController::invite checks muted_until WITHOUT room_id filter.
    Any active mute in any room blocks sending a numer invite globally.
    WhisperController and MessageController scope mute to current room only.
    Inconsistency is confirmed. Whether it is intentional is a policy question.

Options:
    A — Remove mute check from invite entirely.
        Rationale: invite is not a message; mute is a room-scoped restriction;
        there is no "current room" context at invite time.
        Change: src/Chat/NumerController.php lines 24-30 (-7 lines).
    B — Document as intentional policy in MODERATION_POLICY.md. No code change.

### TD-8: reactor_raw plaintext password field
Status: OWNER DECISION REQUIRED (explicit product decision — do not change without approval)
Field: users.reactor_raw

### Phase M: moderation_events / active_restrictions tables
Status: OWNER DECISION REQUIRED (deferred)
Tables created in DB, never written by any code.
Activate when moderation audit trail becomes a real product need.

### friend_online / friend_offline WS stub handlers
Status: OWNER DECISION REQUIRED
Case handlers exist in chat.js (lines 207-208) but no PHP sender found anywhere.
Options: implement the server-side push, or remove the dead handlers.

---

## FROZEN (do not modify without explicit owner decision)

    ONLINE USER ACTIONS in chat.js (~267 lines)
        Requires PREP-B (showUserCtxMenu audit) before any extraction.
        Frozen until PREP-B is explicitly approved and completed.

    reactor_raw field
        See TD-8 above.

## UNFROZEN 2026-06-12 (Phase M activated as sanctions engine S0/S1, owner decision 2026-06-09)

    Phase M tables (moderation_events, active_restrictions)
        LIVE since S1: SanctionService writes both tables on every manual sanction.
        Migration 016 added actor_ip/target_ip/trigger_code, stop_words,
        sanction_rules, login_attempts.

    AccessContext.php
        WIRED since S1: SanctionService resolves actor/target contexts through it
        (fresh instance per operation — no stale cache in the WS process) and
        enforces invariants I-1/I-3 at engine level.
