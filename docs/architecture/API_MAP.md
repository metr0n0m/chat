# API MAP
> Version: 1.0 | Audit date: 2026-05-25
> All routes verified against src/Http/Router.php.
> `[UNVERIFIED]` marks any claim not confirmed directly in code.

---

## Auth Routes

| Method | Path | Router method | Controller | Service deps | Frontend function | JS file |
|---|---|---|---|---|---|---|
| POST | /auth/login | dispatchAuth | LoginHandler::handle | Session::create, CSRF | form submit | chat-auth.js |
| POST | /auth/register | dispatchAuth | RegisterHandler::handle | Session::create, UsernameRules, Mailer, DefaultRoomMembership, EmailVerification | form submit | chat-auth.js |
| GET | /auth/verify | dispatchAuth | EmailVerification::verify | Session::create | email link | — |
| POST | /auth/resend-verification | dispatchAuth | EmailVerification::resend | Mailer | button click | chat-auth.js |
| GET | /auth/google | dispatchAuth | GoogleOAuth::redirect | — | button click | chat-auth.js |
| GET | /auth/google/callback | dispatchAuth | GoogleOAuth::callback | Session::create | redirect | — |
| GET | /auth/logout | dispatch (inline) | Session::destroy, Session::clearCookie | — | sidebar logout btn | chat.js ONLINE USER ACTIONS |
| GET | /api/csrf | dispatch (inline) | CSRF::token | — | app init, form init | chat.js, chat-auth.js |

---

## Chat / Room Routes

| Method | Path | Router method | Controller | Service deps | Frontend function | JS file |
|---|---|---|---|---|---|---|
| GET | /api/rooms | dispatchApi | RoomController::list | — | loadRooms() | chat.js |
| POST | /api/rooms | dispatchApi | RoomController::create | CSRF | initSidebar form submit | chat-sidebar.js |
| GET | /api/numera | dispatchApi | RoomController::numera | — | loadRooms() | chat.js |
| GET | /numer/{id} | dispatchApi | NumerPage::render | Access, Session, CSRF | openNumerWindow() | chat.js |
| GET | /api/rooms/{id}/members | dispatchApi | RoomController::members | — | refreshNumer() | NumerPage.php (inline JS) |
| GET | /api/rooms/{id}/messages | dispatchApi | MessageController::history | SystemMessageService | loadHistory() | chat.js |

---

## User / Settings Routes

| Method | Path | Router method | Controller | Service deps | Frontend function | JS file |
|---|---|---|---|---|---|---|
| GET | /api/users/{id} | dispatchApi | UserManager::profile | Timestamp | openUserInfo() | chat.js ONLINE USER ACTIONS |
| POST | /api/settings | dispatchApi | UserManager::updateSettings | CSRF, UsernameRules, GD | initSettings submit | chat-settings.js |
| GET | /api/users/check | dispatchApi | Router::handleUsernameCheck (inline) | — | username availability check | chat-settings.js `[UNVERIFIED]` |
| GET | /api/users/find | dispatchApi | Router::handleFindUser (inline) | — | [DEAD — no caller found] | — |

---

## Friends Routes

| Method | Path | Router method | Controller | Service deps | Frontend function | JS file |
|---|---|---|---|---|---|---|
| GET | /api/friends | dispatchApi | Router::handleGetFriends (inline) | Timestamp | loadFriends() | chat-friends.js |
| POST | /api/friends | dispatchApi | Router::handleAddFriend (inline) | CSRF | ctx-menu action | chat.js ONLINE USER ACTIONS |
| POST | /api/friends/{id}/respond | dispatchApi | Router::handleRespondFriend (inline) | CSRF | [DEAD — no caller found; friendship accept/decline UI absent] | — |

---

## Admin Routes

All admin routes require `AdminPanel::requireAdmin()` (verified: dispatchAdmin line 119).

| Method | Path | Router method | Controller | Service deps | Frontend function | JS file |
|---|---|---|---|---|---|---|
| GET | /api/admin/dashboard | dispatchAdmin | AdminPanel::dashboard | — | loadAdminDash() | chat-admin.js |
| GET | /api/admin/users | dispatchAdmin | UserManager::list | Timestamp | loadAdminUsers() | chat-admin.js |
| POST | /api/admin/users | dispatchAdmin | AdminPanel::createUser | CSRF | createUser form | chat-admin.js |
| POST | /api/admin/users/{id} | dispatchAdmin | UserManager::update | CSRF, Access | executeGlobalBan() + admin UI | chat.js ONLINE USER ACTIONS, chat-admin.js |
| DELETE | /api/admin/users/{id} | dispatchAdmin | UserManager::delete | CSRF, Access | admin UI btn | chat-admin.js |
| GET | /api/admin/bans | dispatchAdmin | UserManager::listBanned | Timestamp | loadAdminBans() | chat-admin.js |
| POST | /api/admin/rooms/{id}/unban/{uid} | dispatchAdmin | UserManager::roomUnban | CSRF | unban btn | chat-admin.js |
| POST | /api/admin/rooms/{id}/unmute/{uid} | dispatchAdmin | UserManager::roomUnmute | CSRF | unmute btn | chat-admin.js |
| GET | /api/admin/rooms | dispatchAdmin | RoomManager::list | Timestamp | loadAdminRooms() | chat-admin.js |
| POST | /api/admin/rooms/{id}/rename | dispatchAdmin | RoomManager::rename | CSRF | rename btn | chat-admin.js |
| POST | /api/admin/rooms/{id}/category | dispatchAdmin | RoomManager::setCategory | CSRF | category select | chat-admin.js |
| DELETE | /api/admin/rooms/{id} | dispatchAdmin | RoomManager::delete | CSRF, RoomDeletionService | delete btn | chat-admin.js |
| GET | /api/admin/rooms/{id}/members | dispatchAdmin | RoomManager::members | Timestamp | [DEAD — no caller found] | — |
| GET | /api/admin/rooms/{id}/messages | dispatchAdmin | RoomManager::roomMessages | Timestamp | loadRoomMessages() | chat-admin.js |
| POST | /api/admin/rooms/{id}/clear | dispatchAdmin | RoomManager::clearMessages | CSRF | clear btn | chat-admin.js |
| POST | /api/admin/rooms/{id}/clear-user/{uid} | dispatchAdmin | RoomManager::clearUserMessages | CSRF | clear user btn | chat-admin.js |
| GET | /api/admin/numera | dispatchAdmin | RoomManager::numeraActive | Access, Timestamp | loadAdminNumera() | chat-admin.js |
| GET | /api/admin/numera/archive | dispatchAdmin | RoomManager::numeraArchive | Access, Timestamp | loadNumerArchive() | chat-admin.js |
| POST | /api/admin/numera/{id}/close | dispatchAdmin | RoomManager::closeNumer | Access, CSRF | close numer btn | chat-admin.js |
| GET | /api/admin/numera/{id}/messages | dispatchAdmin | RoomManager::numeraMessages | Access, Timestamp | `[UNVERIFIED]` | `[UNVERIFIED]` |
| POST | /api/admin/numera/{id}/clear-archive | dispatchAdmin | RoomManager::clearNumerArchive | Access, CSRF | `[UNVERIFIED]` | `[UNVERIFIED]` |
| GET | /api/admin/owner-overview | dispatchAdmin | AdminPanel::ownerOverview | — | `[UNVERIFIED]` | `[UNVERIFIED]` |
| GET | /api/admin/whispers/sessions | dispatchAdmin | WhisperController::ownerSessionList | Access | loadOwnerWhisperSessions() | chat-admin.js |
| GET | /api/admin/whispers/sessions/{id} | dispatchAdmin | WhisperController::ownerSessionDetail | Access | `[UNVERIFIED]` | `[UNVERIFIED]` |
| GET | /api/admin/whispers | dispatchAdmin | WhisperController::archive | Access | [DEAD — JS uses /sessions only] | — |
| DELETE | /api/admin/whispers/{id} | dispatchAdmin | WhisperController::deleteWhisper | Access | [DEAD — no caller found] | — |
| POST | /api/admin/whispers/clear | dispatchAdmin | WhisperController::clearWhispers | Access, CSRF | [DEAD — no caller found] | — |
| GET | /api/admin/moderators | dispatchAdmin | AdminPanel::globalModerators | — | [DEAD — no caller found] | — |
| GET | /api/admin/room-creators | dispatchAdmin | AdminPanel::roomCreators | — | [DEAD — no caller found] | — |
| GET | /api/admin/status-override-settings | dispatchAdmin | AdminPanel::statusOverrideSettings | — | `[UNVERIFIED]` | `[UNVERIFIED]` |
| POST | /api/admin/status-override-settings | dispatchAdmin | AdminPanel::updateStatusOverrideSettings | CSRF | `[UNVERIFIED]` | `[UNVERIFIED]` |
| GET | /api/admin/system-settings | dispatchAdmin | AdminPanel::getSystemSettings | — | loadAdminSettings() | chat-admin.js |
| POST | /api/admin/system-settings | dispatchAdmin | AdminPanel::updateSystemSettings | CSRF | settings form | chat-admin.js |

---

## Static asset

| Method | Path | Router method | Notes |
|---|---|---|---|
| GET | /storage/avatars/* | dispatch (serveAvatar) | Serves JPEG files from AVATAR_PATH with 7-day cache |

---

## Notes

- All routes verified against Router.php dispatchAuth / dispatchApi / dispatchAdmin methods.
- Routes marked `[UNVERIFIED]` for frontend function/JS file: the HTTP endpoint is confirmed in Router.php but the specific frontend caller was not traced in the current audit pass.
- CSRF::verifyRequest() is called inside individual controller methods, not at Router level.
- Admin access gate: AdminPanel::requireAdmin() called once at top of dispatchAdmin (line 119), covers all admin routes.
