# GOD OBJECTS
> Version: 1.0 | Audit date: 2026-05-25
> Line counts verified by `wc -l` against actual files.
> `[UNVERIFIED]` marks any claim not confirmed directly in code.

---

## Threshold: PHP > 300 lines, JS > 300 lines

---

## PHP: EventRouter.php

**Size:** 690 lines
**Path:** src/WebSocket/EventRouter.php

**Responsibilities (12):**
1. WS event routing (match block, lines 54–68)
2. Room join flow (onJoinRoom: DB check, cm->joinRoom, broadcast)
3. Room leave flow (onLeaveRoom: presence + broadcast)
4. Message send (onSendMessage: rate limit + MessageController + broadcast)
5. Message delete (onDeleteMessage: MessageController + broadcast)
6. Whisper send (onSendWhisper: rate limit + WhisperController + dual send)
7. Invite flow (onInviteUser: NumerController + ReactPHP 30s timer scheduling)
8. Invite respond flow (onInviteRespond: NumerController + dual notify)
9. Numer leave flow (onLeaveNumer: NumerController::leave + owner transfer broadcast + countdown start/stop)
10. Room action dispatch (onRoomAction: RoomController::manage + broadcast per result type)
11. ReactPHP timer management (numerTimers[] — numer countdown; scheduleInviteExpiry — invite expiry)
12. Disconnect cleanup (handleRoomLeave: public vs numer branch; executePresenceCleanup; executeForceLogout)

**Dependencies (used by EventRouter):**
- ConnectionManager (injected via constructor)
- DB\Connection (direct SQL in getOnlineList, onGetRoomCounts, onGetOnlineUsers, onLeaveNumer)
- Chat\MessageController
- Chat\WhisperController
- Chat\RoomController
- Chat\NumerController
- Chat\SystemMessageService
- Security\Session (isUserBlocked, destroyAllForUser)
- Support\Lang
- Support\Timestamp

**Used by:**
- WebSocket\Server::onMessage (route())
- WebSocket\Server::onClose (handleRoomLeave())

**Candidates for extraction:**

| Module | What to extract | Lines | Priority |
|---|---|---|---|
| NumerTimerService | scheduleInviteExpiry + startNumerCountdown + cancelNumerCountdown | ~80 | HIGH |
| DisconnectHandler | handleRoomLeave + executePresenceCleanup + executeForceLogout | ~60 | MEDIUM |
| RoomActionHandler | onRoomAction (all branches) | ~80 | MEDIUM |
| NumerEventHandler | onInviteUser + onInviteRespond + onLeaveNumer | ~120 | MEDIUM |

**Blocker:** All extractions require PREP-C (full caller audit) due to tight coupling with ConnectionManager state.

---

## PHP: UserManager.php

**Size:** 601 lines
**Path:** src/Admin/UserManager.php

**Responsibilities (11):**
1. User list with pagination and search (list)
2. Admin update of user fields (update: global_role, is_banned, can_create_room, custom_status)
3. User deletion with cascade guard (delete)
4. User profile read with friend count (profile)
5. Self-service settings update (updateSettings: username, bio, social links, colors)
6. Password change (inside updateSettings)
7. Avatar upload via file upload with GD resize/crop (uploadAvatar)
8. Avatar URL validation with SSRF guard (headRequest)
9. Ban metadata recording (inside update: banned_at, banned_by, banned_until)
10. Ban list aggregation: global bans + room bans + mutes in one method (listBanned)
11. Room unban / room unmute (roomUnban, roomUnmute)

**Dependencies:**
- DB\Connection
- Http\JsonResponse
- Security\CSRF
- Security\Session
- Support\Timestamp
- Admin\AdminPanel (isAdminStatusOverrideEnabled, in canEditCustomStatus)
- Validation\UsernameRules
- GD (PHP built-in, image processing in uploadAvatar)
- PHP stream_context (headRequest SSRF guard)

**Used by:**
- Router::dispatchApi (profile, updateSettings)
- Router::dispatchAdmin (list, update, delete, listBanned, roomUnban, roomUnmute)

**Candidates for extraction:**

| Module | What to extract | Lines | Priority |
|---|---|---|---|
| AvatarService | uploadAvatar + headRequest | ~100 | MEDIUM |
| UserSettingsHandler | updateSettings (self-service, POST /api/settings) | ~150 | MEDIUM |
| BanRepository | listBanned + roomUnban + roomUnmute | ~100 | MEDIUM |
| UserAdminHandler | update + delete + list (admin-only operations) | ~120 | LOW |

---

## PHP: RoomManager.php

**Size:** 417 lines
**Path:** src/Admin/RoomManager.php

**Responsibilities (15):**
1. List rooms with pagination (list)
2. Rename room (rename)
3. Delete room (delete via RoomDeletionService)
4. List room members (members)
5. Set member role (setMemberRole)
6. Change room owner (changeOwner — in transaction)
7. List active numera with participants (numeraActive)
8. Force close numer (closeNumer)
9. List closed numera archive with filters (numeraArchive)
10. Read numer messages from archive (numeraMessages)
11. Read room messages with pagination/filter (roomMessages)
12. Bulk clear room messages (clearMessages)
13. Bulk clear messages for specific user in room (clearUserMessages)
14. Bulk clear numer archive messages (clearNumerArchive)
15. Set room category (setCategory) + read ENUM options from DB schema (roomCategoryOptions)

**Dependencies:**
- DB\Connection
- Http\JsonResponse
- Security\CSRF
- Security\Session
- Admin\Access
- Admin\RoomDeletionService (via use Chat\Chat\RoomDeletionService)
- Support\Timestamp

**Used by:**
- Router::dispatchAdmin

**Candidates for extraction:**

| Module | What to extract | Lines | Priority |
|---|---|---|---|
| NumerArchiveController | numeraActive + numeraArchive + numeraMessages + clearNumerArchive + closeNumer | ~150 | MEDIUM |
| RoomMessageAdmin | roomMessages + clearMessages + clearUserMessages | ~80 | LOW |
| RoomMemberAdmin | members + setMemberRole + changeOwner | ~70 | LOW |

---

## PHP: RoomController.php

**Size:** 367 lines
**Path:** src/Chat/RoomController.php

**Responsibilities (11):**
1. List public rooms with member count and my_role (list)
2. Create room with category permissions (create)
3. Join room with ban check and max_members check (join)
4. Room management dispatch: rename/delete/set_role/kick/ban/mute (manage)
5. Rename room (inside manage)
6. Delete room via RoomDeletionService (inside manage)
7. Set room role (setRoomRole private)
8. Kick user (kick private — DELETE room_members)
9. Ban user (ban private — UPDATE room_role=banned)
10. Mute user (mute private — UPDATE muted_until)
11. Inline RBAC resolver (resolvePermission — duplicates Admin\Access::resolveLevel)

**Dependencies:**
- DB\Connection
- Http\JsonResponse
- Security\CSRF
- Support\Timestamp
- Chat\RoomDeletionService

**Used by:**
- Router::dispatchApi (list, create, numera, members)
- EventRouter::onJoinRoom (join)
- EventRouter::onRoomAction (manage)

**Critical note:** resolvePermission() is a private duplicate of Admin\Access::resolveLevel().
Both implement the same numeric scale (6/5/4/3/2/1/0/-1). This is the RBAC divergence risk.

---

## PHP: NumerPage.php

**Size:** 464 lines
**Path:** src/Chat/NumerPage.php

**Responsibilities (5):**
1. Access control (Access::canAccessRoom check)
2. DB fetch of numer room data, participants, initial messages
3. HTML rendering of the numer popup window
4. Inline CSS for popup styling
5. Inline JavaScript: own WS connection, join_room, leave_numer, get_online_users, invite_user, send_message, all numer event handlers

**Dependencies:**
- DB\Connection
- Security\Session
- Security\CSRF
- Admin\Access
- Http\JsonResponse

**Used by:** Router::dispatchApi (GET /numer/{id})

**Note:** The inline JS section (lines ~280–464) is a self-contained WS client for the popup.
It handles: room_joined, new_message, numer_participant_joined, numer_participant_left,
numer_owner_changed, numer_countdown, numer_countdown_cancelled, online_users, numer_destroyed, numer_left.

**Candidates for extraction:**
- Inline JS could become a separate static file (numer-popup.js) loaded by NumerPage
- PHP render logic could be a Twig/blade template — but this requires templating infrastructure change

---

## JS: chat-admin.js

**Size:** 767 lines
**Path:** public/assets/js/chat-admin.js

**Responsibilities (15):**
1. initAdmin — tab routing, load triggers
2. loadAdminDash — GET /api/admin/dashboard, render stats
3. loadAdminUsers — GET /api/admin/users, paginated table, search
4. renderUsersTable — build HTML table with action buttons
5. loadAdminRooms — GET /api/admin/rooms
6. renderRoomsTable — room list with rename/delete/category actions
7. loadAdminBans — GET /api/admin/bans, unified bans/mutes table
8. loadAdminNumera — GET /api/admin/numera, active numera table
9. loadNumerArchive — GET /api/admin/numera/archive with filters
10. loadOwnerWhisperSessions — GET /api/admin/whispers/sessions
11. loadAdminSettings — GET /api/admin/system-settings, POST save
12. loadRoomMessages — GET /api/admin/rooms/{id}/messages
13. Ban/unban/unmute action handlers (POST /api/admin/rooms/{id}/unban|unmute/{uid})
14. User edit/create modal handlers
15. Room rename/delete/category inline action handlers

**Dependencies:**
- chat.js globals: openUserInfo, CURRENT_USER, CSRF_TOKEN, rooms, numera
- chat-utils.js: esc, showToast
- chat-time.js: formatChatDateTime
- dayjs (CDN)

**Candidates for extraction:**

| Module | What to extract | Est. lines | Priority |
|---|---|---|---|
| chat-admin-users.js | loadAdminUsers + renderUsersTable + user edit modal | ~200 | LOW |
| chat-admin-rooms.js | loadAdminRooms + renderRoomsTable + room actions | ~180 | LOW |
| chat-admin-bans.js | loadAdminBans + ban/unban/unmute handlers | ~150 | LOW |
| chat-admin-numera.js | loadAdminNumera + loadNumerArchive + loadOwnerWhisperSessions | ~150 | LOW |

---

## JS: chat.js

**Size:** 741 lines
**Path:** public/assets/js/chat.js

**Responsibilities (15):**
1. STATE declarations (10+ variables: ws, currentRoomId, rooms, numera, etc.)
2. Theme initialization
3. CURRENT_USER injection point and app init
4. WebSocket connect/reconnect logic (with exponential backoff `[UNVERIFIED — backoff not confirmed]`)
5. handleWS — master switch for all inbound WS events
6. wsSend — outbound WS helper
7. loadRooms — GET /api/rooms + GET /api/numera
8. joinPublicRoom — leave current + join new room
9. loadHistory — GET /api/rooms/{id}/messages
10. openNumerWindow — window.open /numer/{id}
11. renderOnlineList / addToOnlineList / removeFromOnlineList / updateOnlineUser
12. buildOnlineUser (FROZEN) — render online user card with action buttons
13. openUserInfo (FROZEN) — GET /api/users/{id} + modal
14. executeRoomAction / executeGlobalBan / toggleIgnoreUser (FROZEN) — moderation actions
15. effectiveColor + scrollToBottom helpers

**Dependencies:**
- CDN: jQuery, dayjs, Bootstrap
- chat-utils.js: showToast, esc, displayName (script-level access)
- chat-display.js: avatarMarkup (script-level access)
- chat-time.js: formatChatTime

**FROZEN section:** ONLINE USER ACTIONS (~267 lines)
- Includes: buildOnlineUser, openUserInfo, canModerateCurrentRoom, canAssignLocalModerator, canAssignLocalAdmin, executeRoomAction, executeGlobalBan, toggleIgnoreUser
- Frozen: requires PREP-B (showUserCtxMenu audit) before any extraction

**Candidates for extraction:**

| Module | What to extract | Est. lines | Priority | Blocker |
|---|---|---|---|---|
| chat-online-actions.js | ONLINE USER ACTIONS section | ~267 | HIGH | FROZEN: requires PREP-B |
| chat-ws.js | WEBSOCKET section (connect/reconnect/wsSend/handleWS) | ~142 | MEDIUM | STATE must be script-level (already is) |
| chat-history.js | loadHistory function | ~40 | LOW | depends on WS globals |

---

## Summary table

| File | Lines | PHP/JS | Severity | Primary issue |
|---|---|---|---|---|
| EventRouter.php | 690 | PHP | CRITICAL | WS god: routing + business logic + timers + cleanup |
| UserManager.php | 601 | PHP | HIGH | Settings + admin + avatar + ban all mixed |
| chat-admin.js | 767 | JS | HIGH | All admin UI in one file |
| chat.js | 741 | JS | HIGH | Core + frozen moderation actions unextractable |
| NumerPage.php | 464 | PHP | MEDIUM | HTML render + inline JS 200+ lines |
| RoomManager.php | 417 | PHP | MEDIUM | 15 distinct admin responsibilities |
| RoomController.php | 367 | PHP | MEDIUM | CRUD + inline RBAC duplicate |
