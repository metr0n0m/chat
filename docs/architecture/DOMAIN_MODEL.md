# DOMAIN MODEL
> Version: 1.0 | Audit date: 2026-05-25 | All facts verified against source code.
> `[UNVERIFIED]` marks any claim not confirmed directly in code.

---

## Domain: Users

**Source of Truth:** `users` table

**Tables:**
- `users` — id, username UNIQUE, email UNIQUE, password_hash, reactor_raw [plaintext, explicit product decision], global_role ENUM(user/moderator/admin/platform_owner), is_banned, banned_until, ban_reason, banned_at, banned_by, nick_color, text_color, custom_status, can_create_room, bio, social_telegram, social_whatsapp, social_vk, hide_last_seen, show_system_messages, last_seen_at, created_at

**Services / Classes:**
- `Security\Session` — validate, create, destroy, isUserBlocked, current
- `Auth\LoginHandler` — reads users, writes sessions
- `Auth\RegisterHandler` — writes users, sessions, email_verifications
- `Auth\GoogleOAuth` — reads/writes users, oauth_tokens, sessions
- `Auth\EmailVerification` — reads/writes email_verifications, users
- `Admin\UserManager` — profile, update, delete, updateSettings, listBanned, roomUnban, roomUnmute
- `Admin\AdminPanel` — createUser, globalModerators, roomCreators

**Consumers (read):**
- Session::validate — every HTTP request + every WS connect
- Session::isUserBlocked — every WS event (EventRouter::route line 43, verified)
- UserManager::profile — GET /api/users/{id}
- EventRouter::getOnlineList — on join_room
- EventRouter::onGetOnlineUsers

**Writers (write):**
- LoginHandler: sessions INSERT
- RegisterHandler: users INSERT, sessions INSERT
- UserManager::update: users UPDATE (global_role, is_banned, can_create_room, custom_status)
- UserManager::updateSettings: users UPDATE (username, colors, bio, social, password, avatar_url)
- UserManager::listBanned: users UPDATE auto-expire timed bans
- Session::isUserBlocked: users UPDATE auto-expire timed bans
- Session::validate: users UPDATE last_seen_at

**Invariants:**
- I-U1: username UNIQUE (DB constraint)
- I-U2: email UNIQUE (DB constraint)
- I-U3: global_role ENUM only 4 values
- I-U4: is_banned checked lazily on every WS event; HTTP session checked every request
- I-U5: reactor_raw plaintext (explicit product decision, do not change without owner approval)

---

## Domain: Rooms

**Source of Truth:** `rooms` table + `room_members` table

**Tables:**
- `rooms` — id, name, description, type ENUM(public/numer), owner_id FK->users SET NULL, is_closed, close_reason, max_members, is_default, room_category, created_at, closed_at
- `room_members` — (room_id, user_id) COMPOSITE PK, room_role ENUM(owner/local_admin/local_moderator/member/banned), joined_at, banned_at, banned_by, ban_reason, muted_until, mute_reason

**Services / Classes:**
- `Chat\RoomController` — list, create, join, manage (rename/delete/kick/ban/mute/set_role), numera, members
- `Chat\RoomDeletionService` — deleteWithDependencies
- `Admin\RoomManager` — admin CRUD + numer archive
- `WebSocket\EventRouter` — onJoinRoom, onLeaveRoom, onRoomAction, handleRoomLeave

**Writers (write):**
- RoomController::create: rooms INSERT, room_members INSERT (owner)
- RoomController::join: room_members INSERT (member)
- RoomController::manage/kick: room_members DELETE
- RoomController::manage/ban: room_members UPDATE room_role=banned
- RoomController::manage/mute: room_members UPDATE muted_until
- RoomManager::closeNumer: rooms UPDATE is_closed=1
- RoomManager::changeOwner: room_members UPDATE + rooms UPDATE owner_id (in transaction)

**Invariants:**
- I-R1: rooms.owner_id and room_members.room_role=owner must be in sync — no DB trigger enforces this
- I-R2: type=numer max_members=4
- I-R3: is_closed=1 rooms hidden from API
- I-R4: room_category=permanent cannot be deleted (checked in RoomController::manage and RoomManager::delete)

---

## Domain: Numera

**Source of Truth:** `room_members` table (persistent DB access)
**Presence cache:** `ConnectionManager::roomMembers` (in-memory, ephemeral, lost on WS restart)
Decoupled by design (commit 40035d7).

**Tables:** rooms (type=numer), room_members

**Services / Classes:**
- `Chat\NumerController` — invite, respond, leave, expireInvitations, findOrCreateOwnedNumer
- `Chat\NumerPage` — render GET /numer/{id} HTML with inline WS JS
- `WebSocket\EventRouter` — onInviteUser, onInviteRespond, onLeaveNumer, handleRoomLeave (numer branch), startNumerCountdown, cancelNumerCountdown

**Invariants:**
- I-N1: max 4 participants (checked in NumerController::respond)
- I-N2: popup disconnect does NOT delete room_members (only cm->leaveRoom WS presence)
- I-N3: explicit leave_numer DOES delete room_members (NumerController::leave)
- I-N4: findOrCreateOwnedNumer reuses existing open numer if owner has one with fewer than 4 members
- I-N5: owner transfer on explicit leave — oldest member (joined_at ASC, user_id ASC)
- I-N6: idle 30-min timer started when 1 participant remains (EventRouter::startNumerCountdown)
- I-N7: numer countdown timer in ReactPHP memory — lost on WS restart

---

## Domain: Invitations

**Source of Truth:** `invitations` table

**Tables:**
- `invitations` — id, from_user_id FK->users CASCADE, to_user_id FK->users CASCADE, room_id FK->rooms NULLABLE, status ENUM(pending/accepted/declined/cancelled/expired), created_at, expires_at, responded_at

**Services / Classes:**
- `Chat\NumerController` — invite (INSERT), respond (UPDATE), expireInvitations (batch UPDATE)
- `WebSocket\EventRouter` — onInviteUser, onInviteRespond, scheduleInviteExpiry (ReactPHP 30s timer)
- `ws-server.php` — periodic 10s timer calling NumerController::expireInvitations

**Invariants:**
- I-I1: room_id=NULL until accept (numer created at accept time)
- I-I2: expires_at = created_at + 30 seconds (NumerController::invite verified)
- I-I3: status=cancelled ENUM value NEVER SET by any code path (verified by grep)
- I-I4: ReactPHP 30s timer in-memory — lost on WS restart; 10s DB cleanup is belt-and-suspenders
- I-I5: No cleanup of accepted invitations when numer closes — orphan rows with stale room_id

---

## Domain: Messages

**Source of Truth:** `messages` table

**Tables:**
- `messages` — id BIGINT PK, room_id FK->rooms, user_id FK->users, type ENUM(text/system/whisper), content TEXT, content_hmac, system_importance ENUM(normal/optional/important), system_scope, whisper_to FK->users, sender_session_id FK->sessions, embed_data JSON, is_deleted, deleted_by FK->users, deleted_at, created_at

**Services / Classes:**
- `Chat\MessageController` — send (INSERT), delete (UPDATE is_deleted), history (SELECT)
- `Chat\SystemMessageService` — emitRoomLifecycle (INSERT + sendToRoom), emitModerationCall (sendToUser)
- `Chat\EmbedProcessor` — URL embed detection, called from MessageController::send

**Invariants:**
- I-M1: messages.room_id NO CASCADE DELETE — orphan rows remain if room deleted
- I-M2: content_hmac set via HMAC (verified in MessageController)
- I-M3: is_deleted=1 soft-delete; content preserved
- I-M4: type=whisper excluded from clearMessages (WHERE type != whisper, verified in RoomManager)

---

## Domain: Whisper

**Source of Truth:** `messages` table (type=whisper)

**Tables:** messages (type=whisper, whisper_to FK->users)

**Services / Classes:**
- `Chat\WhisperController` — send, ownerSessionList, ownerSessionDetail, archive, deleteWhisper, clearWhispers
- `WebSocket\EventRouter` — onSendWhisper (calls WhisperController::send)

**Invariants:**
- I-W1: Both sender and recipient must be in same room at send time (cm->isInRoom, verified in EventRouter)
- I-W2: Rate limit via cm->checkWhisperLimit (WHISPER_RATE_LIMIT_MIN constant)
- I-W3: Archive accessible only to platform_owner (Access::requireOwnerPrivateArchive, verified)

---

## Domain: Friends

**Source of Truth:** `friendships` table

**Tables:**
- `friendships` — id, requester_id FK->users, addressee_id FK->users, status ENUM(pending/accepted/declined/blocked), created_at, updated_at

**Services / Classes:**
- `Http\Router` — handleGetFriends, handleAddFriend, handleRespondFriend (inline in Router, no separate FriendController)

**Invariants:**
- I-F1: No UNIQUE constraint on reverse pair — INSERT IGNORE prevents exact duplicate only
- I-F2: status=blocked ENUM value, no code path found that sets it `[UNVERIFIED]`
- I-F3: friend_online / friend_offline — case handlers in chat.js but NOT sent by any PHP code (grep confirmed: no match in Server.php or EventRouter.php)

---

## Domain: Moderation

**Source of Truth (active):**
- Global ban: users.is_banned, users.banned_until, users.ban_reason
- Room ban: room_members.room_role=banned
- Room mute: room_members.muted_until

**NOT source of truth (exist in DB, code not connected):**
- `moderation_events` — Phase M DEFERRED
- `active_restrictions` — Phase M DEFERRED

**Services / Classes:**
- `Chat\RoomController` — manage(kick/ban/mute/set_role)
- `Admin\UserManager` — update (global ban), listBanned (auto-expire), roomUnban, roomUnmute
- `Security\Session` — isUserBlocked (auto-expire + check)
- `WebSocket\EventRouter` — onRoomAction, isUserBlocked check per event (line 43)

**Invariants:**
- I-Mod1: Global ban enforced lazily — checked on next WS event, not pushed immediately
- I-Mod2: Two code paths for global ban: HTTP (UserManager::update) and WS (room_action) — risk of divergence
- I-Mod3: AccessContext.php implements MODERATION_POLICY.md invariants but has 0 call sites
- I-Mod4: moderation_events and active_restrictions exist in DB but are never written

---

## Domain: RBAC

**Source of Truth:** `users.global_role` + `room_members.room_role`

**Three parallel implementations (all verified in code):**

**[1] Admin\Access.php** (162 lines)
- resolveLevel(roomId, userId, actor) returns numeric level
- Used by: AdminPanel, NumerPage, RoomManager

**[2] Chat\RoomController::resolvePermission()** (private inline, ~25 lines)
- Identical numeric scale
- Used only within RoomController::manage()

**[3] Security\AccessContext.php** (186 lines) — **NOT CONNECTED**
- getModerationContext, canModerate, canModerateUser, maxDurationType
- 0 call sites in entire codebase (verified by grep)

**Numeric scale (consistent across all 3):**

| Level | Role |
|---|---|
| 7 | root_owner (reserved, not in DB) |
| 6 | platform_owner |
| 5 | admin |
| 4 | moderator |
| 3 | owner (room local) |
| 2 | local_admin (room local) |
| 1 | local_moderator (room local) |
| 0 | member |
| -1 | no access / banned / not in room |

---

## Domain: Sessions

**Source of Truth:** `sessions` table

**Tables:**
- `sessions` — id, user_id FK->users CASCADE DELETE, token_hash VARCHAR(64) UNIQUE, ip_ua_hash, expires_at

**Services / Classes:**
- `Security\Session` — generate, hash, ipUaHash, create, validate, destroy, destroyAllForUser, isUserBlocked, current, setCookie, clearCookie

**Invariants:**
- I-S1: DB-backed tokens (not JWT) — can be revoked server-side
- I-S2: No IP/UA hard lock — ip_ua_hash stored but NOT checked in validate() (verified: validate method does not use ip_ua_hash for comparison)
- I-S3: SESSION_LIFETIME constant controls expiry
- I-S4: Force logout = Session::destroyAllForUser + cm->closeUser (sends force_logout WS event then closes connection)
