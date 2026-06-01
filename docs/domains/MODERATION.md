# Domain: Moderation
> Audit: 2026-05-25 | All facts verified against source code.
> [UNVERIFIED] = not confirmed directly in code.

---

## Source of Truth (active)

Global ban:  users.is_banned + users.banned_until + users.ban_reason
Room ban:    room_members.room_role = 'banned'
Room mute:   room_members.muted_until

---

## NOT source of truth (tables exist, code NOT connected)

moderation_events    -- Phase M DEFERRED. Table created in DB, never written by any code.
active_restrictions  -- Phase M DEFERRED. Table created in DB, never written by any code.

---

## Tables (active)

### users (ban fields)
    is_banned     TINYINT(1)
    banned_until  DATETIME NULL   -- NULL = permanent; non-NULL = timed ban
    ban_reason    TEXT NULL
    banned_at     DATETIME NULL
    banned_by     FK->users.id NULL

### room_members (ban/mute fields)
    room_role   ENUM(..., banned)   -- ban state embedded in role
    banned_at   DATETIME NULL
    banned_by   FK->users.id NULL
    ban_reason  TEXT NULL
    muted_until DATETIME NULL
    mute_reason TEXT NULL

---

## Tables (deferred, dead)

### moderation_events
    id              BIGINT PK
    act             ENUM(kick/ban_room/ban_global/mute/unban_room/unban_global/restriction_expired)
    origin          ENUM(realtime/migration/system)
    actor_id        FK->users.id SET NULL
    target_user_id  FK->users.id SET NULL
    room_id         FK->rooms.id SET NULL
    parent_event_id FK->moderation_events.id (for unban/expire)
    duration_seconds INT NULL
    reason          TEXT NULL
    metadata        JSON NULL
    created_at      DATETIME
    -- STATUS: CREATED IN DB, 0 WRITES IN ANY PHP FILE

### active_restrictions
    id                 INT PK
    target_user_id     FK->users.id CASCADE DELETE
    room_id            FK->rooms.id CASCADE DELETE
    scope_key          VARCHAR(32) STORED GENERATED ('global' or 'room:N')
    restriction_type   VARCHAR(50)
    expires_at         DATETIME NULL
    source_event_id    FK->moderation_events.id
    -- STATUS: CREATED IN DB, 0 WRITES IN ANY PHP FILE

---

## Services / Classes

ChatRoomController   -- manage(kick/ban/mute/set_role) -- actual DB writes to room_members
AdminUserManager     -- update (global ban), listBanned (auto-expire), roomUnban, roomUnmute
SecuritySession      -- isUserBlocked (auto-expire + check on every WS event)
WebSocketEventRouter -- onRoomAction (calls RoomController::manage), isUserBlocked check per event (line 43)

---

## Ban enforcement paths

### Global ban

Write path 1 -- HTTP admin:
    POST /api/admin/users/{id}
    -> UserManager::update
    -> UPDATE users SET is_banned=1, banned_at=NOW(), banned_by=?, ban_reason=?, banned_until=?

Write path 2 -- WS room_action:
    room_action{action:global_ban} WS
    -> EventRouter::onRoomAction
    -> RoomController::manage
    [Verified: manage() handles global_ban action [UNVERIFIED - action name in manage() not traced]]

Read path -- lazy check on every WS event:
    EventRouter::route (line 43)
    -> Session::isUserBlocked(userId)
       -> auto-expire timed bans first
       -> SELECT is_banned FROM users WHERE id=?
       -> if is_banned=1: Session::destroyAllForUser + cm->closeUser(force_logout)

TWO WRITE PATHS RISK: HTTP ban does not push to open WS. Detected lazily on next WS event.

### Room ban

Write:
    room_action{action:ban} WS
    -> RoomController::manage/ban
    -> UPDATE room_members SET room_role='banned', banned_at=NOW(), banned_by=?

Read/enforce:
    EventRouter::onJoinRoom checks room_members (room_role != 'banned')
    EventRouter::onRoomAction -> sendToUser(banned_from_room) + cm->leaveRoom

### Room mute

Write:
    room_action{action:mute} WS
    -> RoomController::manage/mute
    -> UPDATE room_members SET muted_until=DATE_ADD(NOW(), INTERVAL ? MINUTE), mute_reason=?

Read/enforce:
    NumerController::invite -- checks if sender is muted (SELECT room_members WHERE user_id=? AND muted_until > NOW())
    MessageController::send -- isMuted check [UNVERIFIED -- exact check path not confirmed in send()]

Auto-clear:
    UserManager::listBanned (GET /api/admin/bans):
    UPDATE room_members SET muted_until=NULL, mute_reason=NULL
    WHERE muted_until IS NOT NULL AND muted_until <= NOW()

---

## WS events for moderation actions

| Action | PHP event sent | JS handler | JS file |
|--------|---------------|-----------|---------|
| kick | kicked_from_room (sendToUser target) | onKickedFromRoom | chat-roomevents.js line 5 |
| ban | banned_from_room (sendToUser target) | onBannedFromRoom | chat-roomevents.js line 20 |
| mute | muted_in_room (sendToUser target) | onMutedInRoom | chat-roomevents.js line 36 |
| global ban (lazy) | force_logout (closeUser) | forcedLogout + redirect | chat.js line 213 |

VERIFIED: PHP sends kicked_from_room / banned_from_room / muted_in_room
NOT in codebase: user_kicked / user_banned / user_muted

---

## AccessContext.php (NOT CONNECTED)

SecurityAccessContext (186 lines):
- getModerationContext(userId, roomId) -> {level, source, role, room_id}
- canModerate(actorCtx, targetCtx) -- implements I-1, I-3, scope, level checks
- canModerateUser(actorId, targetId, roomId) -- with self-action guard
- maxDurationType(actorCtx, sanctionType) -- duration limits per role

STATUS: Written, documented, covers all MODERATION_POLICY.md invariants.
CALL SITES: 0 (verified by grep across entire codebase).
