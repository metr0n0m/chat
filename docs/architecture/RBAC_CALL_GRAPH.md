# RBAC CALL GRAPH
> Audit date: 2026-05-31
> All permission checks verified by grep and direct code read across all PHP files in src/.
> [UNVERIFIED] marks any claim not confirmed directly in code.

---

## Source of Truth for Roles

| Role type | Source of Truth | Column | Table |
|-----------|----------------|--------|-------|
| Global role | users.global_role | ENUM(user/moderator/admin/platform_owner) | users |
| Room role | room_members.room_role | ENUM(owner/local_admin/local_moderator/member/banned) | room_members |
| Ban state | users.is_banned | TINYINT(1) | users |
| Room ban | room_members.room_role = 'banned' | — | room_members |
| Room mute | room_members.muted_until | DATETIME NULL | room_members |
| Create room permission | users.can_create_room | TINYINT(1) | users |

Session snapshot (from Session::validate) carries: global_role, can_create_room, is_banned.
WS session snapshot is stale until reconnect — changes not reflected mid-connection.

---

## Numeric scales

### Scale A — Admin\Access::resolveLevel (and RoomController::resolvePermission — identical)

| Level | Role | Scope |
|-------|------|-------|
| 6 | platform_owner | global |
| 5 | admin | global |
| 4 | moderator | global |
| 3 | owner | room local |
| 2 | local_admin | room local |
| 1 | local_moderator | room local |
| 0 | member | room local |
| -1 | not in room / banned / no access | — |

DB query: `SELECT room_role FROM room_members WHERE room_id=? AND user_id=?`
If global role is platform_owner/admin/moderator: returns that level WITHOUT a DB query.
DB is only queried for room-local roles.

### Scale B — UserManager::GLOBAL_ROLE_LEVELS (internal to UserManager only)

| Level | Role |
|-------|------|
| 4 | platform_owner |
| 3 | admin |
| 2 | moderator |
| 1 | user |

DIFFERENT from Scale A. Used only in UserManager::assertRoleManagementAllowed and assertHigherRole.
This is a fourth, isolated role level implementation.

### Scale C — AccessContext::getModerationContext (NOT CONNECTED, 0 call sites)

| Level | Role |
|-------|------|
| 7 | root_owner (reserved) |
| 6 | platform_owner |
| 5 | admin |
| 4 | moderator |
| 3 | owner |
| 2 | local_admin |
| 1 | local_moderator |
| 0 | member |
| -1 | not in room |

---

## PERMISSION DECISION MAP

---

### PD-1: Session validation (global ban check on every request)

**Class:** `Security\Session`
**Method:** `Session::validate(token, ip, ua)`
**Called by:** `Router::dispatch()` (every HTTP request), `Server::onOpen` (every WS connect)
**What it checks:**
- `Session::isUserBlocked(userId)`:
  - DB WRITE: `UPDATE users SET is_banned=0... WHERE banned_until <= NOW()` (auto-expire)
  - DB READ: `SELECT is_banned FROM users WHERE id=?`
  - Decision: if is_banned=1 → return null → request rejected
**Roles checked:** is_banned field only
**DB tables:** users
**Decision:** allow / deny HTTP request or WS connection

---

### PD-2: WS event-level ban check

**Class:** `WebSocket\EventRouter`
**Method:** `EventRouter::route()` lines 43–50
**Called by:** Server::onMessage (every inbound WS event)
**What it checks:**
- `Session::isUserBlocked((int) $session['id'])`:
  - DB WRITE: `UPDATE users SET is_banned=0...` (auto-expire)
  - DB READ: `SELECT is_banned FROM users WHERE id=?`
  - If blocked: `Session::destroyAllForUser` (DB DELETE sessions), cm->closeUser (WS force_logout)
**Roles checked:** is_banned field only
**DB tables:** users, sessions (on ban confirmed)
**Decision:** allow processing / force logout

---

### PD-3: Admin panel access gate

**Class:** `Admin\AdminPanel`
**Method:** `AdminPanel::requireAdmin()`
**Called by:** `Router::dispatchAdmin()` line 119 — called ONCE, covers ALL admin routes
**What it checks:**
- `Session::current()` → session exists
- `Access::canOpenAdminPanel($user)` → `Access::isGlobalAdmin($user)`:
  - `in_array($user['global_role'], ['platform_owner', 'admin'], true)`
  - No DB query — uses session snapshot
**Roles checked:** global_role (platform_owner, admin)
**DB tables:** none (session snapshot)
**Decision:** allow admin panel / deny 403

---

### PD-4: Owner-only access gate (multiple routes)

**Class:** `Admin\Access`
**Method:** `Access::requireOwnerOnly($user)` and `Access::requireOwnerPrivateArchive($user)`
**Called by:**
- `AdminPanel::ownerOverview()` → `Access::requireOwnerOnly` (line 64)
- `AdminPanel::requireOwner()` (private) → `Access::canOpenOwnerPanel` — called by getSystemSettings
- `RoomManager::numeraActive()` → `Access::requireOwnerPrivateArchive` (line 133)
- `RoomManager::closeNumer()` → `Access::requireOwnerPrivateArchive` (line 234)
- `RoomManager::numeraArchive()` → `Access::requireOwnerPrivateArchive` (line 255)
- `RoomManager::numeraMessages()` → `Access::requireOwnerPrivateArchive` (line 301)
- `RoomManager::clearNumerArchive()` → `Access::requireOwnerPrivateArchive` (line 401)
- `WhisperController::archive()` → `Access::requireOwnerPrivateArchive` (line 94)
- `WhisperController::deleteWhisper()` → `Access::requireOwnerPrivateArchive` (line 147)
- `WhisperController::clearWhispers()` → `Access::requireOwnerPrivateArchive` (line 160)
- `WhisperController::ownerSessionList()` → `Access::requireOwnerPrivateArchive` (line 201)
- `WhisperController::ownerSessionDetail()` → `Access::requireOwnerPrivateArchive` (line 326)

**What it checks:**
- `Access::isOwner($user)` → `$user['global_role'] === 'platform_owner'`
- No DB query — uses session snapshot
**Roles checked:** global_role = platform_owner only
**DB tables:** none
**Decision:** allow / deny 404 (hides existence)
**Note:** requireOwnerPrivateArchive and requireOwnerOnly are identical in logic.
Two separate methods with the same implementation — dead code duplication.

---

### PD-5: platform_owner-only inline checks (AdminPanel, no Access wrapper)

**Class:** `Admin\AdminPanel`
**Methods:** `createUser()`, `updateSystemSettings()`, `updateStatusOverrideSettings()`
**Called by:** Router::dispatchAdmin
**What it checks:**
- `($actor['global_role'] ?? '') !== 'platform_owner'` — inline string comparison
- No DB query — uses $actor array from requireAdmin()
**Roles checked:** global_role = platform_owner
**DB tables:** none
**Decision:** allow / deny 403
**Note:** Identical to Access::isOwner() logic but NOT using Access.php — inline duplication (3 places).

---

### PD-6: Room action permission resolver (WS)

**Class:** `Chat\RoomController`
**Method:** `RoomController::resolvePermission($roomId, $userId, $actor)` (private, lines 295–320)
**Called by:** `RoomController::manage($roomId, $actorId, $actor, $data)` → called by `EventRouter::onRoomAction`
**What it checks:**
- global_role checks first (no DB): platform_owner→6, admin→5, moderator→4
- Then DB: `SELECT room_role FROM room_members WHERE room_id=? AND user_id=?`
- Maps room_role to numeric level (owner→3, local_admin→2, local_moderator→1, member→0, else→null=deny)
**Roles checked:** global_role (session snapshot), room_role (DB)
**DB tables:** room_members
**Decision:** returns ['level' => N] or null (deny)

Sub-action permission checks inside manage():

| Action | Check | Min level | Exception |
|--------|-------|----------|-----------|
| rename | `$permission['level'] < 3` | owner (3) | — |
| delete | `$permission['level'] < 3` | owner (3) | OR global_role in ['platform_owner','admin'] |
| set_role (local_admin) | `$permission['level'] < 3` | owner (3) | OR global_role in ['platform_owner','admin'] |
| set_role (other) | level < 1 check missing (no explicit check for local_mod assignment level) | ≥1 implicitly | — |
| kick | `$permission['level'] < 2` | local_admin (2) | OR global_role in ['platform_owner','admin','moderator'] |
| ban | `$permission['level'] < 2` | local_admin (2) | OR global_role in ['platform_owner','admin','moderator'] |
| mute | `$permission['level'] < 2` | local_admin (2) | OR global_role in ['platform_owner','admin','moderator'] |

**Note:** set_role to local_moderator or member has `$permission['level'] < 3 AND NOT global admin`
check for local_admin assignment but NO minimum level check for assigning local_moderator/member.
Effectively any non-null permission (≥0, i.e. member) can set_role to local_moderator/member.

---

### PD-7: Message delete permission

**Class:** `Admin\Access`
**Method:** `Access::canDeleteMessage($user, $message)`
**Called by:** `MessageController::delete()` line 160 (via `MessageController::canDelete`)
**What it checks:**
- `Access::isGlobalAdmin($user)` → platform_owner or admin → immediate allow
- `Access::isGlobalModerator($user)` → moderator → allow only for public rooms:
  - DB READ: `SELECT type FROM rooms WHERE id=?`
- Else: DB READ: `SELECT room_role FROM room_members WHERE room_id=? AND user_id=?`
  - Allow if room_role in [owner, local_admin, local_moderator]
- Own message check: NOT CHECKED HERE — handled upstream [UNVERIFIED — need to confirm if MessageController checks own message]
**Roles checked:** global_role (session), room_role (DB)
**DB tables:** rooms, room_members

---

### PD-8: Room access check (message history)

**Class:** `Admin\Access`
**Method:** `Access::canAccessRoom($user, $roomId)`
**Called by:** `MessageController::canAccess()` line 193 → `MessageController::history()`
**What it checks:**
- DB READ: `SELECT type, is_closed FROM rooms WHERE id=?`
- `Access::isGlobalAdmin($user)` → immediate allow
- For public rooms: `SELECT room_role FROM room_members WHERE room_id=? AND user_id=?`
  → allow if no member row (auto-access) OR room_role != 'banned'
- For numer: `SELECT room_role FROM room_members WHERE room_id=? AND user_id=?`
  → allow only if member exists AND room_role != 'banned'
**Roles checked:** global_role (session), room_role (DB), is_closed (DB)
**DB tables:** rooms, room_members

---

### PD-9: WS join_room access check

**Class:** `WebSocket\EventRouter`
**Method:** `EventRouter::onJoinRoom()` lines 71–143
**Called by:** route() on join_room event
**What it checks (inline, not via Access.php):**
- DB READ: `SELECT id, type, is_closed FROM rooms WHERE id=?` → room exists check
- DB READ: `SELECT room_role FROM room_members WHERE room_id=? AND user_id=?`
  - If banned or no row:
    - public room: call RoomController::join() → auto-join (INSERT room_members)
    - numer: deny (error event)
**Roles checked:** room_role (DB), is_closed (DB)
**DB tables:** rooms, room_members
**Note:** Does NOT use Access.php. Inline logic in EventRouter.

---

### PD-10: Room create permission

**Class:** `Chat\RoomController`
**Method:** `RoomController::create()` lines 39–74
**Called by:** Router::dispatchApi (POST /api/rooms)
**What it checks (inline, not via Access.php):**
- `!$actor['can_create_room'] && !in_array($actor['global_role'], ['platform_owner', 'admin'], true)`
- No DB query — uses session snapshot
**Roles checked:** global_role (session snapshot), can_create_room (session snapshot)
**DB tables:** none
**Decision:** allow / deny 403

**Also controls room_category assignment:**
- `$isPrivileged = in_array($actor['global_role'], ['platform_owner', 'admin'], true)`
- Only privileged users can set room_category to 'permanent' or 'commercial'

---

### PD-11: Numer page access check (HTTP)

**Class:** `Chat\NumerPage`
**Method:** `NumerPage::render()` lines 11–68
**Called by:** Router::dispatchApi (GET /numer/{id})
**What it checks (inline, NOT via Access.php):**
- DB READ: `SELECT id, name, owner_id FROM rooms WHERE id=? AND type='numer' AND is_closed=0`
  → 404 if not found
- DB READ: `SELECT room_role FROM room_members WHERE room_id=? AND user_id=? AND room_role != 'banned'`
  → 403 if not found
**Roles checked:** room_role (DB)
**DB tables:** rooms, room_members
**Note:** Does NOT use Access.php. Inline logic.

---

### PD-12: Numer invite sender checks

**Class:** `Chat\NumerController`
**Method:** `NumerController::invite()` lines 20–67
**Called by:** EventRouter::onInviteUser
**What it checks:**
1. Mute check: DB READ `SELECT muted_until FROM room_members WHERE user_id=? AND muted_until > NOW() LIMIT 1`
   → cross-room mute check (any room, not just the current room)
2. Pending limit: DB READ `SELECT COUNT(*) FROM invitations WHERE from_user_id=? AND status='pending'`
   → deny if >= INVITE_PENDING_MAX
3. Target ban check: DB READ `SELECT id, username, is_banned FROM users WHERE id=?`
   → deny if is_banned=1
**Roles checked:** muted_until (DB), is_banned (DB)
**DB tables:** room_members, invitations, users
**Note:** Mute check is global across ALL rooms — being muted in any one room blocks all invites.
This is a cross-room side effect of the mute mechanism.

---

### PD-13: Whisper sender mute check (in WhisperController)

**Class:** `Chat\WhisperController`
**Method:** `WhisperController::send()` lines 24–86
**Called by:** EventRouter::onSendWhisper
**What it checks:**
- DB READ: `SELECT muted_until FROM room_members WHERE room_id=? AND user_id=? AND muted_until > NOW()`
  → room-scoped mute (only the current room)
- DB READ: `SELECT rm.room_role, u.username FROM room_members rm JOIN users u WHERE rm.room_id=? AND rm.user_id=? AND rm.room_role != 'banned'`
  → recipient must be non-banned member of the room
**Roles checked:** muted_until, room_role (DB)
**DB tables:** room_members, users

---

### PD-14: Message sender mute check (in MessageController)

**Class:** `Chat\MessageController`
**Method:** `MessageController::send()` lines 84–135
**Called by:** EventRouter::onSendMessage
**What it checks:**
- DB READ: `SELECT muted_until FROM room_members WHERE room_id=? AND user_id=? AND muted_until > NOW()`
  → room-scoped mute
- `MessageController::canPost()`:
  - DB READ: `SELECT type, is_closed FROM rooms WHERE id=?`
  - DB READ: `SELECT room_role FROM room_members WHERE room_id=? AND user_id=?`
    → deny if room_role = 'banned'
**Roles checked:** muted_until (DB), room_role (DB), is_closed (DB)
**DB tables:** room_members, rooms

---

### PD-15: User admin update permission (role change, ban)

**Class:** `Admin\UserManager`
**Method:** `UserManager::update()` lines 49–135, using private helpers
**Called by:** Router::dispatchAdmin (POST /api/admin/users/{id})
**What it checks:**
1. Privileged field detection: if any of [global_role, is_banned, can_create_room] is in $data:
2. `assertRoleManagementAllowed($actor, $target, $targetId, $data)`:
   - Self-action guard: `$actorId === $targetId` → deny
   - `roleLevel($actorRole) <= roleLevel($targetRole)` → deny (must outrank target)
   - If changing global_role: `roleLevel($newRole) >= roleLevel($actorRole)` → deny (cannot assign own level)
3. `canEditCustomStatus($actor)`:
   - platform_owner: always allowed
   - admin: check `AdminPanel::isAdminStatusOverrideEnabled()` → DB READ app_settings
**Role scale used:** UserManager::GLOBAL_ROLE_LEVELS (Scale B: user=1, moderator=2, admin=3, platform_owner=4)
**DIFFERENT from Scale A (Access.php) which uses: platform_owner=6, admin=5, moderator=4**
**DB tables:** app_settings (for status override check)

---

### PD-16: User delete permission

**Class:** `Admin\UserManager`
**Method:** `UserManager::delete()` lines 137–160
**Called by:** Router::dispatchAdmin (DELETE /api/admin/users/{id})
**What it checks:**
- `assertHigherRole($actor, $target, $targetId)`:
  - Self-action guard: `$actorId === $targetId` → deny
  - `roleLevel($actorRole) <= roleLevel($targetRole)` → deny (must outrank)
**Role scale:** Scale B (UserManager::GLOBAL_ROLE_LEVELS)
**DB tables:** none (role from session + target row from DB)

---

### PD-17: Ban check on login (HTTP)

**Class:** `Auth\LoginHandler`
**Method:** `LoginHandler::handle()` line 39
**Called by:** Router::dispatchAuth (POST /auth/login)
**What it checks:**
- `Session::isUserBlocked((int) $user['id'])` after password verify
**Roles checked:** is_banned (DB)
**DB tables:** users (via Session::isUserBlocked)

---

### PD-18: Ban check on OAuth login

**Class:** `Auth\GoogleOAuth`
**Method:** `GoogleOAuth::callback()` line 77
**Called by:** Router::dispatchAuth (GET /auth/google/callback)
**What it checks:**
- `Session::isUserBlocked((int) $user['id'])` after OAuth user lookup
**Roles checked:** is_banned (DB)
**DB tables:** users (via Session::isUserBlocked)

---

### PD-19: Ban check on email verification

**Class:** `Auth\EmailVerification`
**Method:** `EmailVerification::verify()` line 47
**Called by:** Router::dispatchAuth (GET /auth/verify)
**What it checks:**
- `Session::isUserBlocked((int) $user['id'])` after token verification
**Roles checked:** is_banned (DB)
**DB tables:** users (via Session::isUserBlocked)

---

### PD-20: AccessContext (NOT CONNECTED — 0 call sites)

**Class:** `Security\AccessContext`
**Method:** `getModerationContext()`, `canModerate()`, `canModerateUser()`, `maxDurationType()`
**Called by:** NOBODY — 0 call sites verified by grep
**What it would check:**
- Single JOIN: `SELECT u.global_role, rm.room_role FROM users u LEFT JOIN room_members rm ON rm.user_id=u.id AND rm.room_id=?`
- Returns unified {level, source, role, room_id} context object
- canModerate: I-1 (self-action), I-3 (platform_owner untouchable), scope, level checks
- maxDurationType: duration limits per role (local_mod→24h, local_admin→7d for mute, owner+admin→unlimited)
**Roles checked:** global_role + room_role in ONE query
**DB tables:** users, room_members
**Status:** DEAD CODE — fully implemented, 0 call sites

---

## ALL PERMISSION DECISION POINTS (summary table)

| ID | Location | Method | Check type | Roles checked | DB query? | Via Access.php? |
|----|----------|--------|-----------|---------------|-----------|----------------|
| PD-1 | Session::validate | isUserBlocked | Ban check | is_banned | YES (users) | — |
| PD-2 | EventRouter::route | isUserBlocked | Ban check | is_banned | YES (users, sessions) | — |
| PD-3 | AdminPanel::requireAdmin | canOpenAdminPanel | Global role | platform_owner, admin | NO (snapshot) | YES |
| PD-4 | Access::requireOwnerOnly/requireOwnerPrivateArchive | isOwner | Global role | platform_owner | NO (snapshot) | YES (is the check) |
| PD-5 | AdminPanel::createUser/updateSystemSettings/updateStatusOverrideSettings | inline | Global role | platform_owner | NO (snapshot) | NO (inline dup) |
| PD-6 | RoomController::resolvePermission | resolvePermission | Global + room role | all | YES (room_members) | NO (parallel impl) |
| PD-7 | Access::canDeleteMessage | canDeleteMessage | Global + room role | all | YES (rooms, room_members) | YES |
| PD-8 | Access::canAccessRoom | canAccessRoom | Global + room role + closed | all | YES (rooms, room_members) | YES |
| PD-9 | EventRouter::onJoinRoom | inline | Room role + is_closed | room_role, is_closed | YES (rooms, room_members) | NO (inline) |
| PD-10 | RoomController::create | inline | Global role + can_create_room | platform_owner, admin, can_create_room | NO (snapshot) | NO (inline) |
| PD-11 | NumerPage::render | inline | Room role | room_role | YES (rooms, room_members) | NO (inline) |
| PD-12 | NumerController::invite | inline | Mute + pending + target ban | muted_until, is_banned | YES (room_members, invitations, users) | NO (inline) |
| PD-13 | WhisperController::send | inline | Mute + target room membership | muted_until, room_role | YES (room_members) | NO (inline) |
| PD-14 | MessageController::send | inline | Mute + room membership | muted_until, room_role, is_closed | YES (room_members, rooms) | NO (via canPost) |
| PD-15 | UserManager::update | assertRoleManagementAllowed | Global role hierarchy | global_role levels | NO (snapshot) + YES (app_settings) | NO (inline, Scale B) |
| PD-16 | UserManager::delete | assertHigherRole | Global role hierarchy | global_role levels | NO (snapshot) | NO (inline, Scale B) |
| PD-17 | LoginHandler::handle | isUserBlocked | Ban check | is_banned | YES (users) | — |
| PD-18 | GoogleOAuth::callback | isUserBlocked | Ban check | is_banned | YES (users) | — |
| PD-19 | EmailVerification::verify | isUserBlocked | Ban check | is_banned | YES (users) | — |
| PD-20 | AccessContext (all) | getModerationContext | Global + room role | all | YES (users, room_members) | IS the impl — UNUSED |

---

## DUPLICATE LOGIC ANALYSIS

### Duplication cluster 1: resolveLevel / resolvePermission

| Implementation | Location | DB query | Used by |
|---|---|---|---|
| `Access::resolveLevel` | Admin\Access.php lines 141–161 | room_members (if not global role) | AdminPanel, RoomManager, NumerPage (indirect via canAccessRoom etc.) |
| `RoomController::resolvePermission` | Chat\RoomController.php lines 295–320 | room_members (if not global role) | RoomController::manage only |

Code is **character-for-character identical** in logic:
- Same global role priority order (platform_owner→6, admin→5, moderator→4)
- Same DB query (`SELECT room_role FROM room_members WHERE room_id=? AND user_id=?`)
- Same match table (owner→3, local_admin→2, local_moderator→1, member→0, else→null/-1)

### Duplication cluster 2: platform_owner inline check

| Location | Code |
|---|---|
| `AdminPanel::createUser` line 128 | `($actor['global_role'] ?? '') !== 'platform_owner'` |
| `AdminPanel::updateSystemSettings` line 219 | `($actor['global_role'] ?? '') !== 'platform_owner'` |
| `AdminPanel::updateStatusOverrideSettings` line 252 | `($actor['global_role'] ?? 'user') !== 'platform_owner'` |

All three are equivalent to `!Access::isOwner($actor)` but do not call it.

### Duplication cluster 3: requireOwnerPrivateArchive vs requireOwnerOnly

| Method | Location | Logic |
|---|---|---|
| `Access::requireOwnerOnly` | Access.php line 111 | if !isOwner → denyNotFound (404) |
| `Access::requireOwnerPrivateArchive` | Access.php line 119 | if !isOwner → denyNotFound (404) |

**Both methods are identical.** Only the name differs. One of them is unnecessary.

### Duplication cluster 4: global role numeric scale mismatch

| Scale | Location | platform_owner | admin | moderator | user |
|-------|----------|---------------|-------|-----------|------|
| Scale A | Access.php / RoomController | 6 | 5 | 4 | (0 via room role) |
| Scale B | UserManager::GLOBAL_ROLE_LEVELS | 4 | 3 | 2 | 1 |
| Scale C | AccessContext | 6 | 5 | 4 | (0 via room role) |

Scale B is incompatible with Scale A/C. Used only in UserManager for relative ordering.
Risk: if a developer adds a check comparing Scale A levels to Scale B levels, the result is wrong.

### Duplication cluster 5: ban check in Auth layer

| Method | Location |
|---|---|
| `LoginHandler::handle` | LoginHandler.php line 39 |
| `GoogleOAuth::callback` | GoogleOAuth.php line 77 |
| `EmailVerification::verify` | EmailVerification.php line 47 |
| `Session::validate` | Session.php line 65 |
| `EventRouter::route` | EventRouter.php line 43 |

All call `Session::isUserBlocked()` — this is correct (shared implementation, no duplication risk).
The Session::validate call already covers HTTP requests. Auth handlers call it pre-session-create
as an additional safeguard during the login flow.

---

## UNUSED MECHANISMS

### AccessContext (Security\AccessContext, 186 lines)

**Status:** Fully implemented, documented, covers all MODERATION_POLICY.md invariants.
**Call sites:** 0 (verified by grep across all PHP files).

**What it implements that no other code implements:**
- I-1: self-action guard (actor cannot moderate themselves) — NOT enforced in RoomController::manage
- I-3: platform_owner is untouchable (cannot be kicked/banned) — NOT enforced in RoomController::manage
- Scope enforcement (local actor cannot act outside their room) — NOT enforced anywhere
- `maxDurationType()` — duration limits per role — NOT enforced anywhere
- Single-query resolution (one JOIN instead of two separate queries)

**What the current code lacks because AccessContext is unused:**
- RoomController::kick can kick platform_owner (no guard)
- RoomController::ban can ban platform_owner (no guard)
- No duration enforcement on mutes

### executeForceLogout (EventRouter lines 683–690)

**Status:** Defined, documented, 0 call sites within EventRouter itself.
The ban check in route() (PD-2) duplicates part of its logic directly.

---

## RBAC FLOW DIAGRAMS

### HTTP request flow

```
HTTP request arrives
  → Session::validate (PD-1)
      → isUserBlocked (users DB)
      → if blocked: return null → 401/redirect
      → if ok: return session snapshot
  → Router::dispatchAdmin?
      → AdminPanel::requireAdmin (PD-3)
          → Access::canOpenAdminPanel (global_role check, NO DB)
          → if denied: 403
      → per-route additional checks (PD-4, PD-5, PD-15, PD-16...)
  → Router::dispatchApi?
      → per-controller checks inline (PD-10, PD-11...)
```

### WS event flow

```
WS message arrives
  → EventRouter::route
      → cm->getSession (in-memory)
      → Session::isUserBlocked (PD-2, users DB)
          → if blocked: destroyAllForUser + closeUser (force_logout)
      → dispatch to handler
          → onJoinRoom: inline room+role check (PD-9, rooms+room_members DB)
          → onSendMessage: MessageController mute+room check (PD-14)
          → onSendWhisper: WhisperController mute+membership check (PD-13)
          → onInviteUser: NumerController mute+pending+ban check (PD-12)
          → onRoomAction: RoomController::resolvePermission (PD-6, room_members DB)
              → sub-action inline checks
```

---

## TOP-10 PERMISSION DECISION POINTS BY COMPLEXITY/RISK

| Rank | ID | Location | Complexity | Risk | Reason |
|------|----|----------|-----------|------|--------|
| 1 | PD-6 | RoomController::resolvePermission + sub-action checks | HIGH | HIGH | Parallel RBAC impl, 6 sub-action branches, inline global_role strings, no I-1/I-3 guards |
| 2 | PD-9 | EventRouter::onJoinRoom inline | HIGH | HIGH | Inline logic, numer vs public branching, no Access.php wrapper, handles auto-join |
| 3 | PD-7 | Access::canDeleteMessage | MEDIUM-HIGH | MEDIUM | 3 code paths (global admin / moderator / room role), separate DB query per path |
| 4 | PD-15 | UserManager::assertRoleManagementAllowed | MEDIUM | MEDIUM | Uses Scale B (incompatible with Scale A), DB read for status override, 3 assertion layers |
| 5 | PD-12 | NumerController::invite inline | MEDIUM | MEDIUM | Cross-room mute check (any room, not current), 3 separate DB reads, inline not via Access |
| 6 | PD-8 | Access::canAccessRoom | MEDIUM | LOW | Public vs numer branching, 2 DB queries, but well-encapsulated in Access.php |
| 7 | PD-3+PD-4 | AdminPanel::requireAdmin + requireOwnerPrivateArchive | LOW | LOW | Session-only checks, no DB, but two identical methods (requireOwnerOnly/Private) |
| 8 | PD-5 | AdminPanel inline platform_owner checks | LOW | MEDIUM | 3 inline duplications of Access::isOwner(), risk of divergence |
| 9 | PD-14 | MessageController::send inline mute+canPost | MEDIUM | LOW | Two separate DB reads, but logic is simple and isolated |
| 10 | PD-20 | AccessContext (NOT CONNECTED) | N/A | HIGH | Correct implementation exists but is dead — risk is that developers modify the wrong RBAC path |
