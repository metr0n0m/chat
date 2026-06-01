# Backend Inventory
> Audit: 2026-05-25 | All facts verified against source code.
> Line counts verified by wc -l.

---

## Entry Points

| File | Purpose |
|------|---------|
| public/index.php | HTTP entry point -- renders HTML shell, dispatches via Router |
| ws-server.php | WS process entry point -- starts ReactPHP IoServer |

---

## PHP Source Files (src/)

### src/Http/

| File | Lines | Purpose |
|------|-------|---------|
| Router.php | 232 | HTTP request dispatcher: dispatchAuth / dispatchApi / dispatchAdmin |
| JsonResponse.php | ~30 | Uniform JSON response helper (success/error) |

Router::dispatchAuth  -- POST /auth/*, GET /auth/*, GET /auth/google*
Router::dispatchApi   -- GET/POST /api/rooms, /api/numera, /numer/{id}, /api/users/*, /api/friends, /api/settings
Router::dispatchAdmin -- /api/admin/* (all require AdminPanel::requireAdmin)

### src/Auth/

| File | Lines | Purpose |
|------|-------|---------|
| LoginHandler.php | 56 | POST /auth/login |
| RegisterHandler.php | 84 | POST /auth/register |
| GoogleOAuth.php | ~120 | /auth/google + /auth/google/callback |
| EmailVerification.php | ~80 | GET /auth/verify + POST /auth/resend-verification |

### src/Chat/

| File | Lines | Purpose |
|------|-------|---------|
| RoomController.php | 367 | list, create, join, manage (rename/delete/kick/ban/mute/set_role), numera, members |
| MessageController.php | 196 | send (INSERT), delete (UPDATE is_deleted), history (SELECT) |
| NumerController.php | 254 | invite, respond, leave, expireInvitations, findOrCreateOwnedNumer |
| NumerPage.php | 464 | GET /numer/{id} HTML renderer with inline WS JS (~200 lines inline JS) |
| WhisperController.php | ~150 | send, ownerSessionList, ownerSessionDetail, archive, deleteWhisper, clearWhispers |
| SystemMessageService.php | ~170 | emitRoomLifecycle (INSERT+sendToRoom), emitModerationCall (sendToUser), visibility rules |
| EmbedProcessor.php | ~50 | URL embed detection called from MessageController::send |
| RoomDeletionService.php | ~30 | deleteWithDependencies -- shared by RoomController + RoomManager |
| DefaultRoomMembership.php | ~20 | Add new users to default rooms on registration |

### src/Admin/

| File | Lines | Purpose |
|------|-------|---------|
| Access.php | 162 | RBAC helpers: isOwner, resolveLevel, canAccessRoom, canModerateRoom, requireOwnerOnly... |
| AdminPanel.php | 266 | dashboard, createUser, globalModerators, roomCreators, settings, statusOverride |
| UserManager.php | 601 | profile, update, delete, updateSettings, uploadAvatar (GD), listBanned, roomUnban, roomUnmute |
| RoomManager.php | 417 | list, rename, delete, members, setMemberRole, changeOwner, numeraActive, numeraArchive, ... |

### src/Security/

| File | Lines | Purpose |
|------|-------|---------|
| Session.php | 148 | validate, create, destroy, destroyAllForUser, isUserBlocked, current, setCookie, clearCookie |
| CSRF.php | 54 | Token generate (session-bound) + verify (request header check) |
| HMAC.php | ~20 | Message content signing |
| OriginGuard.php | ~30 | WS origin whitelist (WS_ALLOWED_ORIGINS config) |
| ColorContrast.php | ~50 | WCAG contrast ratio validator |
| AccessContext.php | 186 | Per-request RBAC resolver -- WRITTEN, NOT CONNECTED (0 call sites) |

### src/DB/

| File | Lines | Purpose |
|------|-------|---------|
| Connection.php | ~60 | PDO singleton, fetchOne/fetchAll/execute/beginTransaction/commit/rollBack |

### src/Mail/

| File | Lines | Purpose |
|------|-------|---------|
| Mailer.php | ~40 | Email sending (email verification) |

### src/Support/

| File | Lines | Purpose |
|------|-------|---------|
| Lang.php | ~30 | Error message i18n (APP_LOCALE config) |
| Timestamp.php | ~80 | ISO UTC normalization, normalizeRows, normalizeOutgoingPayload |

### src/Validation/

| File | Lines | Purpose |
|------|-------|---------|
| UsernameRules.php | ~30 | Username validation rules (length, chars, reserved words) |

### src/WebSocket/

| File | Lines | Purpose |
|------|-------|---------|
| Server.php | 193 | Ratchet MessageComponentInterface -- onOpen/onMessage/onClose/onError |
| ConnectionManager.php | 231 | In-memory WS state -- connections, sessions, roomMembers, rate limits |
| EventRouter.php | 690 | WS event routing + all business logic handlers |

---

## God Objects (PHP >300 lines)

| File | Lines | Severity |
|------|-------|---------|
| EventRouter.php | 690 | CRITICAL -- 12 responsibilities |
| UserManager.php | 601 | HIGH -- 11 responsibilities |
| RoomManager.php | 417 | MEDIUM -- 15 responsibilities |
| NumerPage.php | 464 | MEDIUM -- 5 responsibilities + 200 lines inline JS |
| RoomController.php | 367 | MEDIUM -- 11 responsibilities + inline RBAC duplicate |

---

## Dependency graph (class -> uses)

EventRouter     -> ConnectionManager (injected), Connection (direct SQL), MessageController,
                   WhisperController, RoomController, NumerController, SystemMessageService,
                   Session (isUserBlocked, destroyAllForUser), Lang, Timestamp

UserManager     -> Connection, JsonResponse, CSRF, Session, Timestamp, AdminPanel (isAdminStatusOverrideEnabled),
                   UsernameRules, GD (image processing), stream_context (SSRF guard)

RoomController  -> Connection, JsonResponse, CSRF, Timestamp, RoomDeletionService

NumerController -> Connection, Lang, Timestamp

RoomManager     -> Connection, JsonResponse, CSRF, Session, Access, RoomDeletionService, Timestamp

AdminPanel      -> Connection, Access, Session, CSRF, JsonResponse, Timestamp

Access          -> Connection

Session         -> Connection

SystemMessageService -> Connection, Timestamp, ConnectionManager

NumerPage       -> Connection, Session, CSRF, Access, JsonResponse

MessageController -> Connection, HMAC, EmbedProcessor, JsonResponse, Timestamp, Lang

WhisperController -> Connection, JsonResponse, Timestamp, Session

Router          -> Session, CSRF, LoginHandler, RegisterHandler, GoogleOAuth, EmailVerification,
                   RoomController, MessageController, NumerPage, AdminPanel, UserManager, RoomManager,
                   Connection, Timestamp

---

## Configuration constants (config/config.php)

APP_SECRET, APP_LOCALE, WS_HOST, WS_PORT, WS_BIND_HOST, WS_ALLOWED_ORIGINS
SESSION_LIFETIME, COOKIE_DOMAIN
AVATAR_PATH, AVATAR_URL_PREFIX
MSG_RATE_LIMIT_SEC, WHISPER_RATE_LIMIT_MIN
INVITE_PENDING_MAX
NUMER_IDLE_TIMEOUT
MAX_MESSAGE_LENGTH

---

## Autoload

composer.json with PSR-4:
    Chat -> src/
