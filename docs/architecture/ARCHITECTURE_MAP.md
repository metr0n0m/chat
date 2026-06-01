# ARCHITECTURE MAP
> Version: 1.0 | Audit date: 2026-05-25 | All facts verified against source code.

---

## 1. System Overview

```
Browser (user)
    |
    | HTTPS (port 443)            WSS (port 8443, proxied as /wss)
    |                                   |
    v                                   v
Nginx (reverse proxy)
    |                                   |
    v                                   v
PHP-FPM                         WS Process (ReactPHP/Ratchet)
(public/index.php)              (ws-server.php)
    |                                   |
    v                                   v
MariaDB ───────────────────────────────
```

Both PHP-FPM and WS Process connect to MariaDB directly via PDO (no ORM, no shared cache).
They do NOT communicate with each other. There is NO IPC between the two processes.

---

## 2. Backend (PHP-FPM)

Entry point: public/index.php
Router: src/Http/Router.php

### Namespace structure

```
src/
├── Http/
│   ├── Router.php          — HTTP request dispatcher (dispatchAuth / dispatchApi / dispatchAdmin)
│   └── JsonResponse.php    — Uniform JSON response helper
│
├── Auth/
│   ├── LoginHandler.php    — POST /auth/login
│   ├── RegisterHandler.php — POST /auth/register
│   ├── GoogleOAuth.php     — /auth/google + /auth/google/callback
│   └── EmailVerification.php
│
├── Chat/
│   ├── RoomController.php      — Room list/create/join/manage/numera
│   ├── MessageController.php   — Message history + send + delete
│   ├── NumerController.php     — Numer invite/respond/leave/expireInvitations
│   ├── NumerPage.php           — GET /numer/{id} HTML renderer (inline JS)
│   ├── WhisperController.php   — Whisper archive (admin)
│   ├── SystemMessageService.php — system_message WS emit
│   ├── EmbedProcessor.php      — URL embed detection
│   ├── RoomDeletionService.php — Shared room deletion
│   └── DefaultRoomMembership.php
│
├── Admin/
│   ├── Access.php          — RBAC helpers
│   ├── AdminPanel.php      — Dashboard, createUser, settings
│   ├── UserManager.php     — User CRUD + settings + avatar + ban
│   └── RoomManager.php     — Room admin + numer archive
│
├── Security/
│   ├── Session.php         — DB-backed session validate/create/destroy/isUserBlocked
│   ├── CSRF.php            — Token generate/verify
│   ├── HMAC.php            — Message content signing
│   ├── OriginGuard.php     — WS origin whitelist
│   ├── ColorContrast.php   — WCAG contrast validator
│   └── AccessContext.php   — Per-request RBAC resolver [WRITTEN, NOT CONNECTED]
│
├── DB/
│   └── Connection.php      — PDO singleton
│
├── Mail/
│   └── Mailer.php
│
├── Support/
│   ├── Lang.php
│   └── Timestamp.php
│
└── Validation/
    └── UsernameRules.php
```

---

## 3. WebSocket Process (ReactPHP / Ratchet)

Entry point: ws-server.php

```
ws-server.php
  └── IoServer::factory(HttpServer(WsServer(Server())))
        |
        ├── Server.php (MessageComponentInterface)
        │     onOpen:    OriginGuard + Session::validate → cm->add + send 'connected'
        │     onMessage: router->route(conn, data)
        │     onClose:   cm->remove → 12s ReactPHP timer → router->handleRoomLeave
        │     onError:   conn->close
        │
        ├── ConnectionManager.php (in-memory state, lost on WS restart)
        │     connections[]      connId → ConnectionInterface
        │     sessions[]         connId → session array
        │     userConnections[]  userId → [connId => true]
        │     roomMembers[]      roomId → [userId => true]
        │     userRooms[]        userId → [roomId => true]
        │     lastMessageTime[]  userId → float (message rate limit)
        │     whisperTimes[]     userId → int[] (whisper rate limit)
        │
        └── EventRouter.php
              route() — match event → on*() handlers
              numerTimers[] — roomId → ReactPHP TimerInterface (lost on WS restart)

Server.php:
  pendingDisconnectTimers[] — userId → TimerInterface
  RECONNECT_GRACE_SECONDS = 12 (verified: Server.php line 15)

ws-server.php periodic timers:
  every 10s: NumerController::expireInvitations()
```

---

## 4. Frontend

Shell: public/index.php (renders HTML, injects CURRENT_USER JSON + CSRF_TOKEN)

### JS load order (verified against index.php script tags)

```
Layer 0 — CDN:
  jQuery 3.x, dayjs + plugins, Bootstrap 5.x bundle, autosize, Font Awesome

Layer 1 — Pure utilities (no local deps):
  chat-utils.js     (88 ln)  — esc, displayName, showToast, roleLabel, color helpers
  chat-display.js   (22 ln)  — avatarMarkup, visibleRoleLabel, visibleRoleClass
  chat-input.js     (25 ln)  — normalizeWhisperContent, wrapSelection
  chat-time.js       (7 ln)  — formatChatTime, formatChatDateTime

Layer 2 — Shell:
  chat-shell.js    (161 ln)  — window.ChatShell.renderMobileUsersRail

Layer 3 — Core (exposes window globals):
  chat.js          (741 ln)  — WS, STATE, rooms, online users, FROZEN moderation actions

Layer 4 — Feature modules (depend on chat.js globals):
  chat-numer.js        (73 ln)  — invite/numer UI handlers
  chat-roomevents.js   (55 ln)  — kicked/banned/muted/room_deleted handlers
  chat-messages.js    (114 ln)  — message render + WS handlers
  chat-input-send.js  (110 ln)  — send/delete message, whisper
  chat-friends.js      (24 ln)  — friends list
  chat-settings.js     (97 ln)  — settings modal
  chat-sidebar.js      (88 ln)  — sidebar init
  chat-admin.js       (767 ln)  — full admin panel UI
  chat-auth.js         (62 ln)  — login/register (standalone page)

Numer popup (separate browser window):
  /numer/{id} → NumerPage::render() — PHP-rendered HTML with inline JS
  Has its own WS connection and event switch. No dependency on chat.js.
```

All Layer 4 modules use window-level globals from chat.js.
No import/export module system. Load order enforced by script tag order.

---

## 5. MariaDB (13 tables)

| Table | Purpose | Status |
|---|---|---|
| users | User accounts, global roles, ban state | Active |
| sessions | DB-backed auth tokens | Active |
| oauth_tokens | Google OAuth encrypted tokens | Active |
| rooms | Public rooms + numera | Active |
| room_members | Membership + room roles + ban/mute state | Active |
| messages | Chat + system + whisper messages | Active |
| invitations | Numer invite lifecycle | Active |
| friendships | Friend requests/status | Active |
| app_settings | KV runtime config | Active |
| avatar_uploads | Upload audit log | Active |
| email_verifications | Email verification tokens | Active |
| moderation_events | Moderation audit log | CREATED, NOT USED |
| active_restrictions | Active ban/mute index | CREATED, NOT USED |

---

## 6. Inter-system connections

| From | To | Protocol | Notes |
|---|---|---|---|
| Browser | Nginx | HTTPS/WSS | TLS at Nginx |
| Nginx | PHP-FPM | FastCGI | HTTP requests |
| Nginx | WS Process | TCP proxy | /wss path |
| PHP-FPM | MariaDB | PDO/TCP | Direct SQL |
| WS Process | MariaDB | PDO/TCP | Direct SQL |
| PHP-FPM | SMTP | TCP | Email verification |
| PHP-FPM | Google API | HTTPS | OAuth |
| PHP-FPM <-> WS | NONE | NO IPC | Global ban from HTTP not pushed to WS immediately. Detected lazily on next WS event via Session::isUserBlocked(). |
