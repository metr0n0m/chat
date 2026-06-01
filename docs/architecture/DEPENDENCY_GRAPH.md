# Dependency Graph — Backend
> Audit: 2026-05-25 | All facts verified against source code.
> Format: class -> uses -> used-by
> [UNVERIFIED] = not confirmed directly in code.

---

## Core Infrastructure

### DBConnection
Uses: PDO (PHP ext)
Used by: ALL classes that perform SQL (every controller, every handler, EventRouter, Session, ...)
Note: Singleton pattern. Connection::getInstance() everywhere.

### HttpJsonResponse
Uses: php:header(), json_encode()
Used by: Router, all HTTP controllers and handlers, Access::deny*

### SupportTimestamp
Uses: DateTime
Used by: UserManager, RoomManager, MessageController, WhisperController, AdminPanel,
         NumerController, ConnectionManager (encodeEvent -> normalizeOutgoingPayload)

### SupportLang
Uses: PHP array (lang files by APP_LOCALE)
Used by: EventRouter, NumerController, RoomController

---

## Security Layer

### SecuritySession
Uses: Connection
Used by: Router (current, validate), LoginHandler, RegisterHandler, GoogleOAuth,
         EmailVerification, EventRouter (isUserBlocked, destroyAllForUser),
         AdminPanel (requireAdmin calls Session::current), UserManager (current in update/delete),
         NumerPage

### SecurityCSRF
Uses: session PHP superglobal (token bound to session), php:header()
Used by: Router (verifyRequest inline), UserManager (verifyRequest in update/updateSettings),
         RoomManager (verifyRequest), AdminPanel (verifyRequest), NumerPage

### SecurityHMAC
Uses: PHP hash_hmac (APP_SECRET)
Used by: MessageController::send

### SecurityOriginGuard
Uses: WS_ALLOWED_ORIGINS config constant
Used by: WebSocketServer::onOpen

### SecurityColorContrast
Uses: (pure math)
Used by: UserManager::updateSettings [UNVERIFIED -- exact call path in updateSettings]

### SecurityAccessContext  [NOT CONNECTED]
Uses: Connection
Used by: NOBODY -- 0 call sites (verified by grep)

---

## Auth Layer

### AuthLoginHandler
Uses: Connection, Session, CSRF, JsonResponse
Used by: Router::dispatchAuth

### AuthRegisterHandler
Uses: Connection, Session, CSRF, JsonResponse, UsernameRules, Mailer, DefaultRoomMembership, EmailVerification
Used by: Router::dispatchAuth

### AuthGoogleOAuth
Uses: Connection, Session, JsonResponse
Used by: Router::dispatchAuth

### AuthEmailVerification
Uses: Connection, Session, JsonResponse, Mailer
Used by: Router::dispatchAuth, RegisterHandler

### MailMailer
Uses: php:mail() or SMTP config
Used by: RegisterHandler, EmailVerification

---

## Chat Layer

### ChatRoomController
Uses: Connection, JsonResponse, CSRF, Timestamp, RoomDeletionService
Used by: Router::dispatchApi (list, create, numera, members)
         EventRouter::onJoinRoom (join)
         EventRouter::onRoomAction (manage)

### ChatMessageController
Uses: Connection, HMAC, EmbedProcessor, JsonResponse, Timestamp, Lang
Used by: EventRouter::onSendMessage (send)
         EventRouter::onDeleteMessage (delete)
         Router::dispatchApi (history)

### ChatNumerController
Uses: Connection, Lang, Timestamp
Used by: EventRouter::onInviteUser (invite)
         EventRouter::onInviteRespond (respond)
         EventRouter::onLeaveNumer (leave)
         ws-server.php periodic timer (expireInvitations)

### ChatNumerPage
Uses: Connection, Session, CSRF, Access, JsonResponse
Used by: Router::dispatchApi (GET /numer/{id})

### ChatWhisperController
Uses: Connection, JsonResponse, Timestamp, Session
Used by: EventRouter::onSendWhisper (send)
         Router::dispatchAdmin (archive, ownerSessionList, ownerSessionDetail, deleteWhisper, clearWhispers)

### ChatSystemMessageService
Uses: Connection, Timestamp, ConnectionManager
Used by: EventRouter (onJoinRoom, onLeaveRoom, onDeleteMessage, onRoomAction, handleRoomLeave)

### ChatRoomDeletionService
Uses: Connection
Used by: RoomController::manage(delete)
         RoomManager::delete

### ChatEmbedProcessor
Uses: (regex/string ops)
Used by: MessageController::send

### ChatDefaultRoomMembership
Uses: Connection
Used by: RegisterHandler

---

## Admin Layer

### AdminAccess
Uses: Connection
Used by: AdminPanel (requireAdmin, panel gates), RoomManager (requireOwnerPrivateArchive),
         NumerPage, RoomController (canDeleteMessage [UNVERIFIED])
NOT used by: EventRouter (uses RoomController::resolvePermission instead -- RISK)

### AdminAdminPanel
Uses: Connection, Access, Session, CSRF, JsonResponse, Timestamp, UserManager (createUser)
Used by: Router::dispatchAdmin

### AdminUserManager
Uses: Connection, JsonResponse, CSRF, Session, Timestamp, AdminPanel (isAdminStatusOverrideEnabled),
      UsernameRules, GD (PHP ext, image processing in uploadAvatar),
      stream_context (headRequest SSRF guard)
Used by: Router::dispatchApi (profile, updateSettings)
         Router::dispatchAdmin (list, update, delete, listBanned, roomUnban, roomUnmute)

### AdminRoomManager
Uses: Connection, JsonResponse, CSRF, Session, Access, RoomDeletionService, Timestamp
Used by: Router::dispatchAdmin

---

## WebSocket Layer

### WebSocketServer
Uses: RatchetConnectionInterface, MessageComponentInterface, ConnectionManager, EventRouter,
      Session, OriginGuard, ReactPHPLoop
Used by: ws-server.php (IoServer::factory)

### WebSocketConnectionManager
Uses: RatchetConnectionInterface, Timestamp (encodeEvent -> normalizeOutgoingPayload)
Used by: EventRouter (injected via constructor)
         Server (add, remove)

### WebSocketEventRouter
Uses: ConnectionManager (injected), Connection (direct SQL),
      MessageController, WhisperController, RoomController, NumerController,
      SystemMessageService, Session (isUserBlocked, destroyAllForUser),
      Lang, Timestamp, ReactPHPLoop (for timers)
Used by: Server::onMessage (route())
         Server::onClose (handleRoomLeave())

---

## Validation Layer

### ValidationUsernameRules
Uses: (pure string validation)
Used by: RegisterHandler, UserManager::updateSettings

---

## HTTP Router

### HttpRouter
Uses: Session, CSRF, LoginHandler, RegisterHandler, GoogleOAuth, EmailVerification,
      RoomController, MessageController, NumerPage, AdminPanel, UserManager, RoomManager,
      WhisperController, Connection, Timestamp
Used by: public/index.php (Router::dispatch())

---

## Circular dependency check

No circular dependencies found.
The dependency graph is a DAG (directed acyclic graph) from Router/Server -> Domain -> Infrastructure.

Longest chain:
    Router -> UserManager -> AdminPanel -> Access -> Connection
    Server -> EventRouter -> RoomController -> RoomDeletionService -> Connection
    Server -> EventRouter -> SystemMessageService -> ConnectionManager + Connection

---

## Frontend dependency graph

(Full detail in docs/inventory/FRONTEND_INVENTORY.md)

Layer 0 (CDN): jQuery, dayjs, Bootstrap, autosize, Font Awesome
Layer 1 (utils): chat-utils.js, chat-display.js, chat-input.js, chat-time.js
Layer 2 (shell): chat-shell.js -> jQuery
Layer 3 (core): chat.js -> Layer 0 + Layer 1
Layer 4 (features): all -> chat.js globals (window-level)
    chat-sidebar.js -> chat-settings.js -> chat.js (3-level chain)

No circular dependencies.
Load order enforced by script tag order in public/index.php.
