# ER Model
> Audit: 2026-05-25 | All facts verified against migrations.sql and source code.
> [UNVERIFIED] = not confirmed directly in code.

---

## Tables (13 total)

### users
Primary key: id BIGINT UNSIGNED
Unique: username, email
Notable: reactor_raw (plaintext password, product decision), global_role ENUM

### sessions
Primary key: id
Foreign keys: user_id -> users.id CASCADE DELETE
Unique: token_hash
Notable: ip_ua_hash stored but NOT checked in validate() -- no IP/UA lock

### oauth_tokens
Primary key: (user_id, provider)
Foreign keys: user_id -> users.id CASCADE DELETE
Notable: access_token/refresh_token stored as BLOB (encrypted)

### avatar_uploads
Primary key: id
Foreign keys: user_id -> users.id (no cascade specified)
Purpose: audit log for avatar uploads

### email_verifications
Primary key: id
Foreign keys: user_id -> users.id CASCADE DELETE
Unique: token
Notable: expires_at, verified_at

### rooms
Primary key: id INT UNSIGNED
Foreign keys: owner_id -> users.id SET NULL
Notable:
    type ENUM(public/numer) -- two fundamentally different room types
    is_closed TINYINT -- soft-delete for rooms
    close_reason VARCHAR(50) -- last_left / idle / admin
    max_members INT NULL -- NULL=unlimited, 4 for numera
    room_category ENUM(...) -- values from DB schema, includes permanent
    is_default TINYINT -- for default rooms on registration

### room_members
Primary key: COMPOSITE (room_id, user_id)
Foreign keys:
    room_id -> rooms.id CASCADE DELETE
    user_id -> users.id CASCADE DELETE
    banned_by -> users.id (no cascade)
Notable:
    room_role ENUM(owner/local_admin/local_moderator/member/banned)
    muted_until DATETIME -- null = not muted
    Both rooms.owner_id and room_members.room_role='owner' must be in sync (no trigger)

### messages
Primary key: id BIGINT UNSIGNED
Foreign keys:
    room_id -> rooms.id  -- NO CASCADE DELETE (orphan risk on room deletion)
    user_id -> users.id
    whisper_to -> users.id NULL
    sender_session_id -> sessions.id NULL
    deleted_by -> users.id NULL
Notable:
    type ENUM(text/system/whisper)
    system_importance ENUM(normal/optional/important)
    system_scope VARCHAR(50) -- room_join, room_leave, room_kick, room_ban, moderation_call, ...
    embed_data JSON NULL
    is_deleted TINYINT -- soft-delete, content preserved
    content_hmac VARCHAR(64) -- HMAC signature

### invitations
Primary key: id INT UNSIGNED
Foreign keys:
    from_user_id -> users.id CASCADE DELETE
    to_user_id -> users.id CASCADE DELETE
    room_id -> rooms.id NULL -- NULL until accepted
Status ENUM: pending/accepted/declined/cancelled/expired
Notable:
    room_id=NULL until accept (set by NumerController::respond)
    status='cancelled' NEVER SET by any code
    No cleanup when numer closes (orphan rows with stale room_id)

### friendships
Primary key: id
Foreign keys: requester_id -> users.id, addressee_id -> users.id
Status ENUM: pending/accepted/declined/blocked
Notable:
    No UNIQUE constraint on (requester_id, addressee_id) reverse pair
    status='blocked' ENUM value, no code path sets it

### app_settings
Primary key: key VARCHAR
Purpose: KV store for runtime config (site_name, registration_enabled, max_message_length, ...)

### moderation_events  [DEAD TABLE -- Phase M DEFERRED]
Primary key: id BIGINT
Status: CREATED IN MIGRATIONS, NEVER WRITTEN BY ANY PHP CODE
act ENUM: kick/ban_room/ban_global/mute/unban_room/unban_global/restriction_expired
origin ENUM: realtime/migration/system
Foreign keys: actor_id, target_user_id, room_id (all SET NULL), parent_event_id (self-ref)

### active_restrictions  [DEAD TABLE -- Phase M DEFERRED]
Primary key: id
Status: CREATED IN MIGRATIONS, NEVER WRITTEN BY ANY PHP CODE
scope_key VARCHAR(32) STORED GENERATED ('global' or 'room:N')
Foreign keys: target_user_id CASCADE DELETE, room_id CASCADE DELETE, source_event_id

---

## Relationship diagram

users (id)
    |-- sessions (user_id CASCADE)
    |-- oauth_tokens (user_id CASCADE)
    |-- avatar_uploads (user_id)
    |-- email_verifications (user_id CASCADE)
    |-- rooms (owner_id SET NULL)
    |-- room_members (user_id CASCADE)
    |-- messages (user_id, whisper_to, deleted_by)
    |-- invitations (from_user_id CASCADE, to_user_id CASCADE)
    |-- friendships (requester_id, addressee_id)
    |-- moderation_events (actor_id SET NULL, target_user_id SET NULL) [DEAD]
    |-- active_restrictions (target_user_id CASCADE) [DEAD]

rooms (id)
    |-- room_members (room_id CASCADE)
    |-- messages (room_id, NO CASCADE)
    |-- invitations (room_id, no cascade -- NULL until accept)
    |-- active_restrictions (room_id CASCADE) [DEAD]
    |-- moderation_events (room_id SET NULL) [DEAD]

---

## Risks / anomalies in schema

| ID | Issue | Risk |
|----|-------|------|
| R-DB1 | messages.room_id has NO CASCADE DELETE | Orphan messages on room deletion |
| R-DB2 | rooms.owner_id and room_members.room_role=owner must be synced manually | Data inconsistency risk |
| R-DB3 | friendships has no UNIQUE on reverse pair | Duplicate friendship rows possible |
| R-DB4 | invitations.room_id is NULL until accept | Cannot find room from pending invite alone |
| R-DB5 | moderation_events / active_restrictions are dead tables | Misleading schema, Phase M DEFERRED |
| R-DB6 | invitations accepted rows not cleaned up when numer closes | Orphan rows with stale room_id |
| R-DB7 | reactor_raw stores plaintext password | Security concern (explicit product decision) |
