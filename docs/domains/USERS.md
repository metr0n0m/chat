# Domain: Users
Audit: 2026-05-25 | Facts verified against source code.
[UNVERIFIED] = not confirmed directly in code.

## Source of Truth
users table in MariaDB.

## Table: users
id               BIGINT UNSIGNED AUTO_INCREMENT PK
username         VARCHAR(50) UNIQUE
email            VARCHAR(255) UNIQUE
password_hash    VARCHAR(255)  -- bcrypt
reactor_raw      VARCHAR(255)  -- PLAINTEXT [explicit product decision, do not change without owner approval]
global_role      ENUM(user/moderator/admin/platform_owner)
is_banned        TINYINT(1)
banned_until     DATETIME NULL
ban_reason       TEXT NULL
banned_at        DATETIME NULL
banned_by        FK->users.id NULL
nick_color       VARCHAR(7)
text_color       VARCHAR(7)
custom_status    VARCHAR(80) NULL
can_create_room  TINYINT(1) DEFAULT 0
bio              TEXT NULL
social_telegram  VARCHAR(255) NULL
social_whatsapp  VARCHAR(255) NULL
social_vk        VARCHAR(255) NULL
hide_last_seen   TINYINT(1) DEFAULT 0
show_system_messages TINYINT(1) DEFAULT 1
last_seen_at     DATETIME NULL
created_at       DATETIME DEFAULT NOW()

## Related tables (FK from users.id)
- sessions (user_id CASCADE DELETE)
- oauth_tokens (user_id CASCADE DELETE)
- avatar_uploads (user_id)
- email_verifications (user_id CASCADE DELETE)
- room_members (user_id CASCADE DELETE)
- messages (user_id, whisper_to, deleted_by)
- invitations (from_user_id CASCADE, to_user_id CASCADE)
- friendships (requester_id, addressee_id)

## Services / Classes
SecuritySession     -- validate, create, destroy, isUserBlocked, current, setCookie, clearCookie
AuthLoginHandler    -- reads users by username, writes sessions
AuthRegisterHandler -- writes users/sessions/email_verifications; calls DefaultRoomMembership, Mailer
AuthGoogleOAuth     -- reads/writes users, oauth_tokens, sessions
AuthEmailVerification -- reads/writes email_verifications, users
AdminUserManager    -- profile, update, delete, updateSettings, listBanned, roomUnban, roomUnmute, uploadAvatar
AdminAdminPanel     -- createUser, globalModerators, roomCreators

## Consumers (read)
Session::validate          -- every HTTP request + every WS connect -- sessions JOIN users full snapshot
Session::isUserBlocked     -- every WS event (EventRouter::route line 43, verified) -- users.is_banned, banned_until
UserManager::profile       -- GET /api/users/{id} -- users + friendships (friend_count subquery)
EventRouter::getOnlineList -- join_room WS -- users JOIN room_members
EventRouter::onGetOnlineUsers -- get_online_users WS -- users WHERE id IN (online ids) AND is_banned=0
UserManager::list          -- GET /api/admin/users -- users paginated searchable

## Writers (write)
RegisterHandler         -- POST /auth/register      -- users INSERT
UserManager::update     -- POST /api/admin/users/{id} -- users UPDATE (global_role, is_banned, can_create_room, custom_status, ban metadata)
UserManager::updateSettings -- POST /api/settings    -- users UPDATE (username, colors, bio, social, password_hash, avatar_url)
UserManager::listBanned -- GET /api/admin/bans       -- users UPDATE auto-expire timed bans (is_banned=0 WHERE banned_until <= NOW())
Session::isUserBlocked  -- every WS event            -- users UPDATE auto-expire timed bans
Session::validate       -- every HTTP request        -- users UPDATE last_seen_at = NOW()
UserManager::delete     -- DELETE /api/admin/users/{id} -- users DELETE (CASCADE)

## Invariants
I-U1: username UNIQUE (DB constraint)
I-U2: email UNIQUE (DB constraint)
I-U3: global_role ENUM only 4 values: user/moderator/admin/platform_owner
I-U4: is_banned checked lazily on every WS event; HTTP session checked every request; no immediate push on ban
I-U5: reactor_raw is plaintext -- explicit product decision, do not change without owner approval
I-U6: WS session snapshot carries stale username/color/role until user reconnects
I-U7: last_seen_at updated on every HTTP request via Session::validate; NOT on WS-only activity

## Auto-expiry of timed bans

Path 1 -- Session::isUserBlocked() -- runs on every WS event:
  UPDATE users SET is_banned=0, banned_at=NULL, banned_until=NULL, ban_reason=NULL, banned_by=NULL
  WHERE id=? AND is_banned=1 AND banned_until IS NOT NULL AND banned_until <= NOW()

Path 2 -- UserManager::listBanned() -- runs on GET /api/admin/bans:
  UPDATE users SET is_banned=0, banned_at=NULL, banned_by=NULL, banned_until=NULL, ban_reason=NULL
  WHERE is_banned=1 AND banned_until IS NOT NULL AND banned_until <= NOW()

Permanent bans (banned_until = NULL) are NEVER auto-cleared.

## Avatar upload (UserManager::uploadAvatar)
- File upload: GD resize/crop to 200x200 JPEG, saved to AVATAR_PATH/{userId}.jpg
- URL avatar: HEAD request with SSRF guard (blocks loopback + RFC-1918 + link-local)
- Both paths write users.avatar_url
- Upload audit: avatar_uploads table receives INSERT on every file upload

## Global role levels (RBAC)
platform_owner  = 6
admin           = 5
moderator       = 4
user            = base
Used in: AdminAccess::resolveLevel, ChatRoomController::resolvePermission, SecurityAccessContext::getModerationContext
