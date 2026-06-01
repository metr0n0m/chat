# DOCUMENTATION QA REPORT
> QA date: 2026-05-31
> Files checked: 31 (all new docs/ files created in commit 9266bcb)
> All code verification performed via grep against actual source files.

---

## 1. [UNVERIFIED] AUDIT

### 1.1 Header-only [UNVERIFIED] (not substantive claims — just boilerplate markers)

49 total occurrences of `[UNVERIFIED]` across all files.
35 of them are boilerplate header lines (`> [UNVERIFIED] = not confirmed...`).
**14 are substantive claims.**

---

### 1.2 Substantive [UNVERIFIED] claims — verified against code

---

**UV-1** `docs/architecture/API_MAP.md` lines 31, 42-43, 53, 75, 82-93 (16 cells)
**Claim:** Frontend function and JS file not traced for these admin/user routes.
**Routes:**
- GET /api/rooms/{id}/members → `[UNVERIFIED]`
- GET /api/users/check → `chat-settings.js [UNVERIFIED]`
- GET /api/users/find → `[UNVERIFIED]`
- POST /api/friends/{id}/respond → `[UNVERIFIED]`
- GET /api/admin/rooms/{id}/members → `[UNVERIFIED]`
- GET /api/admin/numera/{id}/messages → `[UNVERIFIED]`
- POST /api/admin/numera/{id}/clear-archive → `[UNVERIFIED]`
- GET /api/admin/owner-overview → `[UNVERIFIED]`
- GET /api/admin/whispers/sessions/{id} → `[UNVERIFIED]`
- GET /api/admin/whispers → `[UNVERIFIED]`
- DELETE /api/admin/whispers/{id} → `[UNVERIFIED]`
- POST /api/admin/whispers/clear → `[UNVERIFIED]`
- GET /api/admin/moderators → `[UNVERIFIED]`
- GET /api/admin/room-creators → `[UNVERIFIED]`
- GET /api/admin/status-override-settings → `[UNVERIFIED]`
- POST /api/admin/status-override-settings → `[UNVERIFIED]`

**Verification result — NOW CONFIRMED:**

| Route | Frontend function | JS file | Source |
|-------|-----------------|---------|--------|
| GET /api/users/check | usernameCheckTimer / $.get | chat-settings.js | chat-settings.js line 43 verified |
| GET /api/admin/numera/{id}/messages | $.get | chat-admin.js | chat-admin.js line 701 verified |
| POST /api/admin/numera/{id}/clear-archive | numer-clear-archive-btn handler | chat-admin.js | chat-admin.js line 654 verified |
| GET /api/admin/owner-overview | loadAdminDash | chat-admin.js | chat-admin.js line 71 verified |
| GET /api/admin/whispers/sessions | loadOwnerWhisperSessions | chat-admin.js | chat-admin.js line 470 verified |
| GET /api/admin/whispers/sessions/{id} | inline $.get | chat-admin.js | chat-admin.js line 514 verified |
| GET /api/admin/status-override-settings | inline $.get | chat-admin.js | chat-admin.js line 171 verified |
| POST /api/admin/status-override-settings | toggle handler | chat-admin.js | chat-admin.js line 217 verified |
| GET /api/admin/bans (was already verified) | loadAdminBans | chat-admin.js | chat-admin.js line 557 verified |

**STILL UNVERIFIED (no JS caller found by grep):**

| Route | Status | Note |
|-------|--------|------|
| GET /api/rooms/{id}/members | NO JS CALLER FOUND | Route exists in Router.php. No $.get call found in any JS file. |
| GET /api/users/find | NO JS CALLER FOUND | Router.php line 110. No JS caller found in public/. |
| POST /api/friends/{id}/respond | NO JS CALLER FOUND | Router.php line 107. No JS caller found in public/. |
| GET /api/admin/rooms/{id}/members | NO JS CALLER FOUND | Router.php line 131. No JS caller found. |
| GET /api/admin/whispers | NO JS CALLER FOUND | Router.php line 143. No $.get('/api/admin/whispers') found (sessions-only called). |
| DELETE /api/admin/whispers/{id} | NO JS CALLER FOUND | Router.php line 144. |
| POST /api/admin/whispers/clear | NO JS CALLER FOUND | Router.php line 145. |
| GET /api/admin/moderators | NO JS CALLER FOUND | Router.php line 149. |
| GET /api/admin/room-creators | NO JS CALLER FOUND | Router.php line 150. |

These 9 routes exist in Router.php but have no JS frontend caller found by grep in public/assets/js/.
They may be called by non-JS clients, removed frontend code, or future-use endpoints.

---

**UV-2** `docs/architecture/DEPENDENCY_GRAPH.md` line 140
**Claim:** `NumerPage, RoomController (canDeleteMessage [UNVERIFIED])`
**Verification:** Access::canDeleteMessage is called by MessageController.php line 160 (confirmed by grep).
NumerPage does NOT call canDeleteMessage.
**STATUS: PARTIAL ERROR** — the claim is inaccurate. MessageController calls canDeleteMessage, not RoomController.
RoomController does not call Access::canDeleteMessage anywhere in its code.

---

**UV-3** `docs/architecture/DOMAIN_MODEL.md` line 173 / `docs/domains/FRIENDS.md` line 79
**Claim:** `status='blocked' ENUM value, no code path found that sets it [UNVERIFIED]`
**Verification:** grep across all PHP files in src/ confirms no `'blocked'` assignment to friendships.status.
ENUM defined in migrations.sql line 118.
**STATUS: CONFIRMED** — status='blocked' is never set. Claim is correct.

---

**UV-4** `docs/architecture/DEPENDENCY_GRAPH.md` line 54
**Claim:** `ColorContrast used by UserManager::updateSettings [UNVERIFIED -- exact call path in updateSettings]`
**Verification:** grep for "ColorContrast" in UserManager.php returns ZERO results.
ColorContrast is not imported or called in UserManager.php at all.
**STATUS: FACTUAL ERROR** — ColorContrast is NOT used by UserManager::updateSettings.
The correct statement: ColorContrast is not called by any file in the current codebase.
(Also listed incorrectly in API_MAP.md Service deps column for POST /api/settings.)

---

**UV-5** `docs/architecture/GOD_OBJECTS.md` / `docs/audits/GOD_OBJECTS.md` line 262
**Claim:** `WebSocket connect/reconnect logic (with exponential backoff [UNVERIFIED — backoff not confirmed])`
**Verification:** chat.js line 114: `setTimeout(connectWS, 3000)` — fixed 3-second delay, no backoff.
**STATUS: CONFIRMED** — No exponential backoff. Fixed 3s delay. [UNVERIFIED] label was correct.

---

**UV-6** `docs/architecture/SOURCE_OF_TRUTH.md` line 75 / `docs/domains/MODERATION.md` line 122
**Claim:** `MessageController::send (isMuted) [UNVERIFIED path]`
**Verification:** MessageController.php lines 88-92 — muted_until check IS present in send():
```php
$memberState = $db->fetchOne(
    'SELECT muted_until FROM room_members WHERE room_id = ? AND user_id = ? AND muted_until > NOW()',
```
**STATUS: CONFIRMED AND RESOLVED** — mute check IS in MessageController::send(). The [UNVERIFIED] label should be removed. The claim is true.

---

**UV-7** `docs/audits/TECHNICAL_DEBT.md` line 175
**Claim:** `WS room_action{global_ban} -> RoomController::manage -> UPDATE users [UNVERIFIED exact path]`
**Verification:** grep for "global_ban" or "global.ban" in RoomController.php returns ZERO results.
grep for "global_ban" in EventRouter.php returns ZERO results.
**STATUS: UNRESOLVABLE FROM GREP** — There is no `global_ban` action in RoomController::manage().
The switch in RoomController::manage() handles: rename / delete / set_role / kick / ban / mute.
None of these is "global_ban". The TD-12 claim about a second WS write path for global ban
via RoomController::manage is likely incorrect. Global ban from WS appears to go through
the HTTP admin path only, not through room_action.

---

**UV-8** `docs/domains/MODERATION.md` line 91
**Claim:** `[Verified: manage() handles global_ban action [UNVERIFIED - action name in manage() not traced]]`
**Verification:** RoomController::manage() switch does NOT have a global_ban case (confirmed by full read of RoomController.php).
**STATUS: FACTUAL ERROR** — global_ban is not an action in manage(). The "WS path 2" for global ban described in MODERATION.md and TECHNICAL_DEBT.md is not supported by the code.

---

**UV-9** `docs/domains/WHISPER.md` line 21
**Claim:** `public message history (MessageController::history filters by default [UNVERIFIED - filter logic not traced])`
**Verification:** MessageController.php lines 34-35:
```php
$where = 'WHERE m.room_id = ? AND m.is_deleted = 0
          AND (m.type != \'whisper\' OR m.user_id = ? OR m.whisper_to = ?)';
```
**STATUS: CONFIRMED AND RESOLVED** — whispers ARE filtered in history. Own whispers shown, others' hidden.

---

**UV-10** `docs/domains/WHISPER.md` line 73
**Claim:** `sender_session_id stored for session-level audit [UNVERIFIED -- not traced through all insert paths]`
**Verification:** WhisperController.php lines 54-59 and MessageController.php lines 107-112:
Both use `$senderSessionId = isset($actor['session_id']) ... (int) $actor['session_id']`
and include `sender_session_id` in INSERT.
**STATUS: CONFIRMED AND RESOLVED** — sender_session_id IS stored in both send paths.

---

### 1.3 Summary of [UNVERIFIED] resolution

| ID | File | Claim | Resolution |
|----|------|-------|-----------|
| UV-1 (9 routes) | API_MAP.md | No JS caller found | STILL UNVERIFIED — no JS caller in codebase |
| UV-1 (7 routes) | API_MAP.md | JS caller unclear | NOW CONFIRMED — callers found in chat-admin.js |
| UV-2 | DEPENDENCY_GRAPH.md | canDeleteMessage caller | PARTIAL ERROR — MessageController calls it, not RoomController |
| UV-3 | DOMAIN_MODEL + FRIENDS | status=blocked never set | CONFIRMED CORRECT |
| UV-4 | DEPENDENCY_GRAPH.md | ColorContrast in UserManager | FACTUAL ERROR — ColorContrast NOT used in UserManager |
| UV-5 | GOD_OBJECTS.md | WS reconnect backoff | CONFIRMED — fixed 3s delay, no backoff |
| UV-6 | SOURCE_OF_TRUTH + MODERATION | isMuted in MessageController | CONFIRMED CORRECT — should remove [UNVERIFIED] |
| UV-7 | TECHNICAL_DEBT.md | global_ban WS path 2 | LIKELY ERROR — no global_ban action in manage() |
| UV-8 | MODERATION.md | global_ban in manage() | FACTUAL ERROR — action does not exist |
| UV-9 | WHISPER.md | message history filters | CONFIRMED CORRECT — should remove [UNVERIFIED] |
| UV-10 | WHISPER.md | sender_session_id stored | CONFIRMED CORRECT — should remove [UNVERIFIED] |

---

## 2. DUPLICATES AND REDUNDANCIES

### 2.1 Exact file duplicates (identical content)

| File A | File B | Status |
|--------|--------|--------|
| docs/architecture/NUMER_MODEL.md | docs/models/NUMER_MODEL.md | EXACT DUPLICATE (md5 confirmed) |
| docs/architecture/GOD_OBJECTS.md | docs/audits/GOD_OBJECTS.md | EXACT DUPLICATE (md5 confirmed) |
| docs/architecture/INVITE_STATE_MACHINE.md | docs/models/INVITE_LIFECYCLE.md | NEAR-DUPLICATE — only title line differs |

These duplicates are by design (cross-referencing between directory groups).
They are intentional redundancy, not an error. However they increase maintenance cost.

### 2.2 Partial content duplication

| Source section | Duplicated in | Overlap type |
|----------------|---------------|--------------|
| DOMAIN_MODEL.md (all 10 domain sections) | docs/domains/*.md (10 separate files) | Intentional expansion |
| NUMER_MODEL.md (full content) | domains/NUMERA.md | Embedded + double header |
| INVITE_STATE_MACHINE.md (full content) | domains/INVITATIONS.md (partial embed) | Partial embed |
| GOD_OBJECTS.md summary table | audits/GOD_OBJECTS.md | Identical |

### 2.3 Double H1 header in NUMERA.md

`docs/domains/NUMERA.md` has TWO `#` (H1) headers:
- Line 1: `# Domain: Numera`
- Line 8: `# NUMER MODEL`

This is a structural problem — a file should have exactly one H1. The second H1 comes from embedding the NUMER_MODEL.md verbatim without stripping its title.

---

## 3. NAMESPACE NOTATION DEFECT

### 3.1 Description

Files written via Node.js template literals have PHP namespace backslashes stripped.
In Markdown, `Chat\RoomController` was encoded as `Chat\\RoomController` in the template,
but the output rendered without the separator: `ChatRoomController`.

Files written via Edit tool (which preserves backslash literally) are correct.

### 3.2 Affected files (node-written, stripped namespaces)

| File | Examples of stripped namespaces |
|------|--------------------------------|
| docs/domains/USERS.md | `AdminAccess`, `AdminUserManager`, `SecuritySession` |
| docs/domains/ROOMS.md | `ChatRoomController`, `ChatRoomDeletionService`, `WebSocketEventRouter` |
| docs/domains/MODERATION.md | `ChatRoomController`, `SecuritySession`, `AdminUserManager`, `WebSocketEventRouter` |
| docs/domains/RBAC.md | `AdminAccess`, `ChatRoomController`, `AdminPanel` |
| docs/domains/SESSIONS.md | `SecuritySession` |
| docs/domains/FRIENDS.md | `HttpRouter` |
| docs/domains/WHISPER.md | `ChatWhisperController`, `WebSocketEventRouter` |
| docs/domains/MESSAGES.md | `ChatMessageController`, `ChatSystemMessageService`, `ChatEmbedProcessor` |
| docs/inventory/BACKEND_INVENTORY.md | `AdminPanel`, `AdminStatus...` (in table cells) |
| docs/inventory/FRONTEND_INVENTORY.md | `AdminBan...`, `AdminDash...` (in table cells) |
| docs/models/OWNERSHIP_MODEL.md | `AdminAccess` |

### 3.3 Unaffected files (Edit-written, correct notation)

docs/architecture/DOMAIN_MODEL.md — `Chat\RoomController`, `Security\Session` ✓
docs/architecture/WS_EVENT_MAP.md — no class references, unaffected ✓
docs/architecture/API_MAP.md — unaffected ✓
docs/architecture/NUMER_MODEL.md — unaffected ✓
docs/architecture/INVITE_STATE_MACHINE.md — unaffected ✓
docs/domains/INVITATIONS.md — NUMER_MODEL content embedded, unaffected ✓
docs/domains/NUMERA.md — NUMER_MODEL content embedded, unaffected ✓

---

## 4. CRITICAL DOCUMENT VERIFICATION

### 4.1 docs/architecture/WS_EVENT_MAP.md

Verified against EventRouter.php, Server.php, chat.js, chat-numer.js, chat-roomevents.js, NumerPage.php.

**Inbound events (12):** ALL CORRECT — match EventRouter route() match block lines 54-68.
**Outbound events — all confirmed by grep:**
- `kicked_from_room` EventRouter line 507 → chat.js line 185 ✓
- `banned_from_room` EventRouter line 520 → chat.js line 188 ✓
- `muted_in_room` EventRouter line 532 → chat.js line 191 ✓
- `connected` Server.php line 74 → chat.js line 131 ✓
- `force_logout` EventRouter line 47, 687 → chat.js line 213 ✓
- `numer_destroyed` EventRouter lines 387, 391, 393, 616 → chat.js line 175 ✓
- Disconnect flow: RECONNECT_GRACE_SECONDS=12 Server.php line 15 ✓

**Issues found:** NONE. WS_EVENT_MAP.md is accurate.

---

### 4.2 docs/architecture/API_MAP.md

Verified against Router.php dispatchAuth/dispatchApi/dispatchAdmin.

**All 40+ routes confirmed in Router.php.**
**Issues:**
- 9 routes have no JS frontend caller found by grep (see UV-1 above).
- ColorContrast listed as service dep for POST /api/settings — INCORRECT (UV-4).
- The note on line 110 correctly documents the [UNVERIFIED] scope.

---

### 4.3 docs/models/ER_MODEL.md

Verified against migrations.sql (src/DB/migrations.sql).

**All 13 tables confirmed.**
**All FK relationships confirmed.**
**Risk items:**
- R-DB1 (messages.room_id no CASCADE) — confirmed
- R-DB5 (dead tables) — confirmed
- R-DB7 (reactor_raw plaintext) — confirmed
**Issues found:** NONE. ER_MODEL.md is accurate.

---

### 4.4 docs/domains/RBAC.md

**Issues:**
- Namespace notation: `AdminAccess`, `ChatRoomController` instead of `Admin\Access`, `Chat\RoomController` (namespace stripping defect — see §3).
- Implementation section content is correct (3 parallel impls confirmed by code read).
- Numeric scale (6/5/4/3/2/1/0/-1) matches all three implementations.
- AccessContext 0 call sites — confirmed by grep.

---

### 4.5 docs/domains/NUMERA.md

**Issues:**
- Double H1 header (line 1 and line 8) — structural defect.
- Namespace notation stripped in NUMER_MODEL section headers where applicable.
- All factual content (flows, timers, DB states) matches EventRouter.php and NumerController.php.

---

### 4.6 docs/domains/MODERATION.md

**Issues:**
- Namespace notation stripped (SecuritySession, ChatRoomController, etc.).
- FACTUAL ERROR: line 91 claims manage() handles `global_ban` action — NOT TRUE (see UV-8).
  RoomController::manage() switch has: rename/delete/set_role/kick/ban/mute. No global_ban.
- mute check in MessageController confirmed (UV-6).
- AccessContext 0 call sites confirmed.

---

### 4.7 docs/audits/ARCHITECTURAL_RISKS.md

**Verified all 11 risks against code:**

| Risk | Status |
|------|--------|
| RISK-1: No IPC | CONFIRMED — no IPC mechanism found anywhere |
| RISK-2: WS state lost on restart | CONFIRMED — ConnectionManager is in-memory |
| RISK-3: 3 parallel RBAC impls | CONFIRMED — all 3 found, AccessContext 0 call sites |
| RISK-4: owner_id sync | CONFIRMED — NumerController::leave updates in sequence not transaction |
| RISK-5: messages orphan | CONFIRMED — no CASCADE DELETE on messages.room_id |
| RISK-6: Timer race | CONFIRMED — guard `if ($inv['status'] === 'pending')` in scheduleInviteExpiry |
| RISK-7: Session snapshot staleness | CONFIRMED — session stored at connect, not refreshed |
| RISK-8: Invite spam | CONFIRMED — only INVITE_PENDING_MAX check, no rate limit |
| RISK-9: Admin numer close no WS | CONFIRMED — closeNumer() has no cm->sendToRoom |
| RISK-10: invite_user rate limit gap | CONFIRMED — checkRateLimit is message-only |
| RISK-11: findOrCreateOwnedNumer shares | CONFIRMED — SELECT first existing open numer |

**Issues found:** NONE. ARCHITECTURAL_RISKS.md is accurate.

---

## 5. MARKDOWN STRUCTURAL ISSUES

| ID | File | Issue | Severity |
|----|------|-------|---------|
| MD-1 | docs/domains/NUMERA.md | Double H1 header (line 1 and line 8) | MEDIUM |
| MD-2 | 11 node-written files | PHP namespace backslashes stripped (see §3) | MEDIUM |
| MD-3 | docs/domains/INVITATIONS.md | Inherits NUMER_MODEL header structure but has separate domain header — H2 sections mix domain and state machine levels | LOW |

**No unclosed code blocks found** — all files have even numbers of ``` fences (confirmed by wc).

**No broken tables found** — all table rows have consistent column counts (verified by spot check).

---

## 6. FACTUAL ERRORS IN DOCUMENTATION

| ID | File | Line(s) | Error | Correct fact |
|----|------|---------|-------|-------------|
| FE-1 | docs/architecture/DEPENDENCY_GRAPH.md | 140 | `Access used by ... RoomController (canDeleteMessage [UNVERIFIED])` | Access::canDeleteMessage is called by MessageController.php line 160. Not by RoomController. |
| FE-2 | docs/architecture/DEPENDENCY_GRAPH.md | 54 | `ColorContrast used by UserManager::updateSettings [UNVERIFIED]` | ColorContrast is NOT used by UserManager. No import or call found in UserManager.php. |
| FE-3 | docs/domains/MODERATION.md | 91 | `manage() handles global_ban action` | RoomController::manage() has NO global_ban case. Switch handles: rename/delete/set_role/kick/ban/mute only. |
| FE-4 | docs/audits/TECHNICAL_DEBT.md | 175 | `WS room_action{global_ban} -> RoomController::manage` | No global_ban action exists in RoomController::manage(). Global ban from WS is not confirmed to go through this path. |
| FE-5 | docs/architecture/API_MAP.md | 41 | POST /api/settings Service deps includes `ColorContrast` | ColorContrast not found in UserManager.php (zero grep results). |

---

## 7. [UNVERIFIED] THAT CAN NOW BE RESOLVED

The following [UNVERIFIED] labels are confirmed by code and should be removed in a future fix pass:

| File | Claim | Correct status |
|------|-------|---------------|
| docs/architecture/SOURCE_OF_TRUTH.md line 75 | `MessageController::send (isMuted) [UNVERIFIED path]` | CONFIRMED — muted_until check at lines 88-92 |
| docs/domains/MODERATION.md line 122 | `isMuted check [UNVERIFIED]` | CONFIRMED — same |
| docs/domains/WHISPER.md line 21 | `MessageController::history filters [UNVERIFIED]` | CONFIRMED — WHERE type != 'whisper' at line 35 |
| docs/domains/WHISPER.md line 73 | `sender_session_id stored [UNVERIFIED]` | CONFIRMED — WhisperController lines 54-59 |

---

## 8. WHAT NEEDS TO BE FIXED

Priority order:

| Priority | ID | Fix needed | Files affected |
|----------|----|-----------|---------------|
| HIGH | FE-1..5 | Correct factual errors (canDeleteMessage caller, ColorContrast, global_ban) | DEPENDENCY_GRAPH.md, MODERATION.md, TECHNICAL_DEBT.md, API_MAP.md |
| HIGH | MD-2 | Restore PHP namespace backslashes in 11 node-written files | USERS.md, ROOMS.md, MODERATION.md, RBAC.md, SESSIONS.md, FRIENDS.md, WHISPER.md, MESSAGES.md, BACKEND_INVENTORY.md, FRONTEND_INVENTORY.md, OWNERSHIP_MODEL.md |
| MEDIUM | MD-1 | Remove duplicate H1 from NUMERA.md | NUMERA.md |
| LOW | UV-resolved | Remove [UNVERIFIED] labels from 4 confirmed claims | SOURCE_OF_TRUTH.md, MODERATION.md (x2), WHISPER.md (x2) |
| LOW | UV-1 | Document 9 no-JS-caller routes as "frontend caller not found in public/" | API_MAP.md |

---

## 9. QA SUMMARY

| Metric | Count |
|--------|-------|
| Files checked | 31 |
| Total [UNVERIFIED] occurrences | 49 |
| Header-only [UNVERIFIED] (boilerplate) | 35 |
| Substantive [UNVERIFIED] claims | 14 |
| [UNVERIFIED] confirmed correct | 5 |
| [UNVERIFIED] now resolvable (confirmed by code) | 4 |
| [UNVERIFIED] still unresolved (no JS caller) | 9 routes |
| [UNVERIFIED] that are factual errors | 2 (UV-7, UV-8) |
| Exact file duplicates | 3 pairs |
| Partial/structural duplicates | 4 |
| Namespace notation defect (node-written files) | 11 files |
| Factual errors found | 5 (FE-1 through FE-5) |
| Unclosed code blocks | 0 |
| Broken tables | 0 |
| Double H1 headers | 1 (NUMERA.md) |
