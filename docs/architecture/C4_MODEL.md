# C4 Model
> Audit: 2026-05-25 | C4 Levels 1-4 | All facts verified against source code.
> [UNVERIFIED] = not confirmed directly in code.

---

## C4 Level 1 — Context

Browser (user)
    |
    | HTTPS/WSS
    v
[Chat System: chat.adalex.org]
    |           |
    v           v
PHP-FPM     WS Process (ReactPHP)
    |           |
    v           v
          MariaDB

External systems:
    Browser -> Nginx -> PHP-FPM (HTTP requests)
    Browser -> Nginx -> WS Process (WebSocket)
    PHP-FPM -> MariaDB (PDO/TCP)
    WS Process -> MariaDB (PDO/TCP)
    PHP-FPM -> Google OAuth API (HTTPS)
    PHP-FPM -> SMTP (email verification)

NO IPC between PHP-FPM and WS Process.

---

## C4 Level 2 — Containers

Container 1: Nginx
    Role: TLS termination, reverse proxy
    Proxies HTTP requests to PHP-FPM via FastCGI
    Proxies /wss to ReactPHP WS process

Container 2: PHP-FPM (HTTP API)
    Entry: public/index.php
    Router: src/Http/Router.php
    Handles: Auth, Room CRUD, Messages history, User settings, Admin panel
    Auth: DB-backed sessions (cookie 'chat_session')

Container 3: WS Process (ReactPHP / Ratchet)
    Entry: ws-server.php
    Handles: Real-time events, room presence, messaging, numera, invitations
    State: In-memory (ConnectionManager) + DB (for persistence)
    Auth: Same session cookie validated on WS connect

Container 4: MariaDB
    13 tables (see ER_MODEL.md)
    Both PHP-FPM and WS Process connect directly via PDO

---

## C4 Level 3 — Components (PHP-FPM)

### Auth components
    LoginHandler    -- POST /auth/login
    RegisterHandler -- POST /auth/register (+ Mailer, DefaultRoomMembership)
    GoogleOAuth     -- /auth/google, /auth/google/callback
    EmailVerification -- /auth/verify, /auth/resend-verification

### Chat components
    RoomController      -- list/create/join/manage rooms, numera list
    MessageController   -- message history, send, delete
    NumerController     -- invite/respond/leave/expireInvitations
    NumerPage           -- GET /numer/{id} HTML renderer + inline WS JS
    WhisperController   -- whisper archive (admin)
    SystemMessageService -- system_message events
    RoomDeletionService -- shared room deletion
    EmbedProcessor      -- URL embed detection

### Admin components
    AdminPanel  -- dashboard, createUser, settings, moderators
    UserManager -- user CRUD, settings, avatar, ban management
    RoomManager -- room admin CRUD, numer archive
    Access      -- RBAC helpers (resolveLevel, requireOwnerOnly, ...)

### Security components
    Session     -- token validate/create/destroy/isUserBlocked
    CSRF        -- token generate/verify
    HMAC        -- message content signing
    OriginGuard -- WS origin whitelist
    AccessContext -- NOT CONNECTED (0 call sites)

### Infrastructure components
    DBConnection  -- PDO singleton
    Lang           -- i18n error messages
    Timestamp      -- ISO UTC normalization
    UsernameRules  -- validation
    Mailer         -- email sending

---

## C4 Level 3 — Components (WS Process)

### Server.php
    onOpen:    OriginGuard + Session::validate + cm->add + send 'connected'
    onMessage: EventRouter::route
    onClose:   cm->remove + 12s ReactPHP timer -> EventRouter::handleRoomLeave
    onError:   conn->close

### ConnectionManager.php
    In-memory state: connections[], sessions[], userConnections[],
                     roomMembers[], userRooms[], lastMessageTime[], whisperTimes[]
    Methods: add, remove, getSession, joinRoom, leaveRoom, isInRoom,
             sendToUser, sendToRoom, sendToConnection, sendToAll,
             checkRateLimit, checkWhisperLimit, closeUser, clearRoom

### EventRouter.php
    route()         -- match event -> on*() handler
    onJoinRoom      -- DB check, cm->joinRoom, broadcast
    onLeaveRoom     -- cm->leaveRoom, broadcast
    onSendMessage   -- rate limit + MessageController + broadcast
    onDeleteMessage -- MessageController + broadcast
    onSendWhisper   -- rate limit + WhisperController + dual send
    onInviteUser    -- NumerController + 30s ReactPHP timer
    onInviteRespond -- NumerController + dual notify
    onLeaveNumer    -- NumerController::leave + owner transfer broadcast + countdown
    onRoomAction    -- RoomController::manage + broadcast per result type
    onGetRoomCounts -- returns room counts to connection
    onGetOnlineUsers -- returns online user list to connection
    handleRoomLeave -- disconnect cleanup (public vs numer branch)
    executePresenceCleanup -- remove user from all rooms
    executeForceLogout -- destroyAllForUser + closeUser
    numerTimers[]   -- roomId -> ReactPHP TimerInterface (30min numer countdown)
    scheduleInviteExpiry -- 30s ReactPHP timer per invite

---

## C4 Level 4 — Code (Frontend)

### chat.js (741 lines) [GOD OBJECT]
STATE: ws, currentRoomId, currentPublicRoomId, currentRoomRole, rooms[], numera[],
       onlineUsers[], currentOnlineUsers[], ignoredUserIds[], CURRENT_USER, CSRF_TOKEN,
       isScrolledToBottom
WS: wsSend(event, data), handleWS(data) [master switch, 20+ cases]
Rooms: loadRooms(), joinPublicRoom(), openNumerWindow(), loadHistory(), updateRoomBadge()
Online: renderOnlineList(), addToOnlineList(), removeFromOnlineList(), updateOnlineUser()
FROZEN: buildOnlineUser, openUserInfo, canModerateCurrentRoom, canAssignLocalModerator,
        canAssignLocalAdmin, executeRoomAction, executeGlobalBan, toggleIgnoreUser
Helpers: scrollToBottom(), effectiveColor()

### chat-numer.js (73 lines)
Invite/numer UI handlers: upsertNumerInSidebar, onNumerJoined, onInviteSent,
onInviteAccepted, onInviteDeclined, onInviteExpired, onInviteReceived

### chat-roomevents.js (55 lines)
Moderation WS handlers: onKickedFromRoom, onBannedFromRoom, onMutedInRoom, onRoomDeleted

### chat-messages.js (114 lines)
Message render + WS: buildMessage, buildWhisperMessage, appendMessage, onNewMessage,
onMessageDeleted, onSystemMessage, onWhisperMessage, shouldRenderMessage, canDeleteMessage

### chat-input-send.js (110 lines)
Input: initInput, sendMessage, activateWhisperMode, clearWhisperMode, appendInputToken

### NumerPage.php inline JS (~200 lines)
Self-contained WS client for numer popup. Own connection, own event loop.
Handles: room_joined, new_message, numer_participant_joined, numer_participant_left,
numer_owner_changed, numer_countdown, numer_countdown_cancelled, online_users,
numer_destroyed, numer_left
Sends: join_room, leave_numer, send_message, get_online_users, invite_user

---

## Inter-component dependency graph (key relationships)

EventRouter -uses-> ConnectionManager (injected)
EventRouter -uses-> Connection (direct SQL)
EventRouter -calls-> MessageController, WhisperController, RoomController, NumerController
EventRouter -calls-> SystemMessageService
EventRouter -calls-> Session::isUserBlocked (per event)

Router -dispatches-to-> all HTTP controllers
Router -uses-> Session::current (auth)

UserManager -uses-> AdminPanel (canEditCustomStatus)
RoomController -uses-> RoomDeletionService
RoomManager -uses-> RoomDeletionService

chat.js -uses-> ALL Layer 4 JS modules (via window globals they read)
chat-sidebar.js -uses-> chat-settings.js (openSettingsModal)
NumerPage inline JS -uses-> effectiveColor, displayName (copied inline, not imported)
