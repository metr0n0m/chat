# SOURCE OF TRUTH
> Version: 1.0 | Audit date: 2026-05-25
> All facts verified against source code.
> `[UNVERIFIED]` marks any claim not confirmed directly in code.

---

## User Identity

| Field | Source of Truth | Secondary copies | Consumers | Writers | Risks |
|---|---|---|---|---|---|
| User identity | users table | sessions (snapshot at login time) | Session::validate, UserManager::profile, EventRouter | RegisterHandler, UserManager::update | sessions may have stale username/color until re-login |
| Username | users.username | sessions.username (snapshot) | EventRouter::userPayload, chat.js CURRENT_USER | UserManager::updateSettings | WS session carries snapshot; rename not reflected until reconnect |
| Global role | users.global_role | sessions.global_role (snapshot) | EventRouter, Access, RoomController | UserManager::update | Promotion/demotion not reflected until WS reconnect |
| Ban state | users.is_banned + banned_until | — | Session::isUserBlocked (lazy check per WS event) | UserManager::update (HTTP), EventRouter::onRoomAction (WS) | Two write paths; ban not pushed immediately to open WS connections |

---

## Authentication

| Domain | Source of Truth | Secondary copies | Consumers | Writers | Risks |
|---|---|---|---|---|---|
| Session tokens | sessions table (token_hash) | Browser cookie (raw token) | Session::validate (every HTTP req + WS connect) | Session::create, Session::destroy, Session::destroyAllForUser | — |
| Session validity | sessions.expires_at | — | Session::validate (WHERE expires_at > NOW()) | Session::create (sets), Session::create (deletes expired on INSERT) | — |
| OAuth tokens | oauth_tokens table | — | GoogleOAuth::callback | GoogleOAuth | — |

---

## Rooms

| Domain | Source of Truth | Secondary copies | Consumers | Writers | Risks |
|---|---|---|---|---|---|
| Room list | rooms table (is_closed=0) | — | RoomController::list, EventRouter, NumerPage | RoomController::create, RoomDeletionService, RoomManager | — |
| Room ownership | rooms.owner_id + room_members.room_role=owner | — | NumerController::findOrCreateOwnedNumer | NumerController::leave (transfer), RoomManager::changeOwner | Two fields must stay in sync, no DB trigger |
| Room membership | room_members table | ConnectionManager::roomMembers (WS presence) | EventRouter::onJoinRoom, RoomController, NumerController | RoomController::join/kick/ban, NumerController | For numera: DB is truth, WS is cache. For public rooms: auto-joined on first WS join |
| Room settings | rooms.name, rooms.description, rooms.room_category | — | RoomManager, RoomController | RoomManager::rename/setCategory, RoomController::manage | — |

---

## Numera

| Domain | Source of Truth | Secondary copies | Consumers | Writers | Risks |
|---|---|---|---|---|---|
| Numer access/membership | room_members (persistent) | ConnectionManager::roomMembers (ephemeral) | NumerPage::render, EventRouter::onJoinRoom | NumerController::respond (add), NumerController::leave (remove) | WS presence lost on popup close; DB access preserved |
| Numer WS popup presence | ConnectionManager::roomMembers | — | EventRouter (isInRoom, sendToRoom, getRoomUserIds) | cm->joinRoom, cm->leaveRoom, cm->clearRoom | Lost on WS restart |
| Numer countdown timer | EventRouter::numerTimers[roomId] (ReactPHP) | — | EventRouter::startNumerCountdown/cancelNumerCountdown | EventRouter | Lost on WS restart; no DB record of countdown state |

---

## Invitations

| Domain | Source of Truth | Secondary copies | Consumers | Writers | Risks |
|---|---|---|---|---|---|
| Invitation status | invitations.status | — | NumerController::respond (check pending), scheduleInviteExpiry | NumerController::invite, respond, expireInvitations | — |
| Invite expiry timer | ReactPHP in-memory timer | invitations.expires_at (DB) | scheduleInviteExpiry | EventRouter::onInviteUser | Timer lost on WS restart; DB cleanup (10s periodic) is belt-and-suspenders but sends no WS events |
| Room assignment | invitations.room_id (NULL until accept) | — | NumerController::respond | NumerController::respond (sets room_id on accept) | Orphan rows after numer close |

---

## Messages

| Domain | Source of Truth | Secondary copies | Consumers | Writers | Risks |
|---|---|---|---|---|---|
| Message history | messages table | — | MessageController::history, RoomManager::roomMessages/numeraMessages | MessageController::send, SystemMessageService::emitRoomLifecycle, WhisperController::send | Orphan rows if room deleted (no CASCADE) |
| Message deleted state | messages.is_deleted | — | MessageController::history (filter), admin views | MessageController::delete, RoomManager::clearMessages/clearNumerArchive | Soft delete only, content preserved |

---

## Moderation

| Domain | Source of Truth | Secondary copies | Consumers | Writers | Risks |
|---|---|---|---|---|---|
| Global ban | users.is_banned, users.banned_until, users.ban_reason | — | Session::isUserBlocked (every WS event + HTTP validate) | UserManager::update (HTTP admin), EventRouter::onRoomAction global_ban (WS) | Two write paths; no immediate WS push |
| Room ban | room_members.room_role=banned | — | EventRouter::onJoinRoom check, isUserBlocked | RoomController::manage/ban | — |
| Room mute | room_members.muted_until | — | MessageController::send (muted_until check, verified: MessageController.php lines 88–92) | RoomController::manage/mute, UserManager::roomUnmute | — |
| Moderation audit | moderation_events table | — | NOBODY (not connected) | NOBODY (not connected) | Phase M DEFERRED — table exists but is dead |
| Active restrictions | active_restrictions table | — | NOBODY (not connected) | NOBODY (not connected) | Phase M DEFERRED |

---

## RBAC

| Domain | Source of Truth | Secondary copies | Consumers | Writers | Risks |
|---|---|---|---|---|---|
| Global role | users.global_role | sessions.global_role (snapshot) | Access::resolveLevel, RoomController::resolvePermission, AccessContext | UserManager::update | 3 parallel implementations; AccessContext not connected |
| Room role | room_members.room_role | — | Access::resolveLevel, RoomController::resolvePermission | RoomController::manage/set_role, NumerController::leave (transfer), RoomManager::setMemberRole | rooms.owner_id not enforced by trigger |

---

## Sessions

| Domain | Source of Truth | Secondary copies | Consumers | Writers | Risks |
|---|---|---|---|---|---|
| Auth token | sessions.token_hash | Browser cookie (raw token) | Session::validate | Session::create, Session::destroy | — |
| Session user snapshot | sessions JOIN users (at validate time) | — | EventRouter (WS session) | Session::validate reads fresh on every connect | WS session is stale snapshot; username changes not reflected until reconnect |

---

## App Settings

| Domain | Source of Truth | Secondary copies | Consumers | Writers | Risks |
|---|---|---|---|---|---|
| Runtime config | app_settings table (KV) | — | AdminPanel::getSettings, AdminPanel::isAdminStatusOverrideEnabled | AdminPanel::updateSystemSettings | — |

---

## Frontend state

| Domain | Source of Truth | Secondary copies | Consumers | Writers | Risks |
|---|---|---|---|---|---|
| UI theme (dark/light) | localStorage | — | chat-utils.js isDarkTheme | chat-settings.js (theme toggle) | Not synced to server |
| Current room | chat.js STATE: currentRoomId, currentPublicRoomId | — | chat-messages.js, chat-input-send.js, chat-sidebar.js | onRoomJoined, joinPublicRoom | In-memory, lost on page reload |
| Online users list | chat.js STATE: currentOnlineUsers | — | chat-sidebar.js, chat-shell.js | onUserJoined, onUserLeft, onWSConnected | In-memory; reloaded on WS reconnect via get_room_counts |
| Numer list (sidebar) | chat.js STATE: numera[] | room_members (DB, canonical) | chat-sidebar.js, chat-numer.js | loadRooms (GET /api/numera), upsertNumerInSidebar, removeNumerFromSidebar | Sidebar state can diverge from DB if WS events missed |
| Ignored users | chat.js STATE: ignoredUserIds[] | — | chat-messages.js shouldRenderMessage | toggleIgnoreUser | In-memory only, lost on reload |
