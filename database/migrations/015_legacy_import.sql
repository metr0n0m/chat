-- Migration 015: legacy_import — one-time backfill of existing restrictions
-- Depends on: 013_moderation_events.sql, 014_active_restrictions.sql
-- Idempotent: safe to run multiple times. Uses NOT EXISTS guards.
-- Does NOT modify legacy columns (users.is_banned, room_members.room_role, muted_until).
-- Does NOT update application behaviour.
--
-- For each legacy restriction:
--   1. INSERT moderation_events (act=real type, origin='migration')
--   2. INSERT active_restrictions with event_id from step 1
--
-- Idempotency guard: skips rows already present in active_restrictions
-- via UNIQUE KEY (type, target_user_id, scope_key).
-- ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at) keeps data fresh on re-run
-- without creating new moderation_events rows (INSERT IGNORE for that).
--
-- duration_type mapping:
--   banned_until / muted_until IS NULL  → 'permanent'
--   <= 1h                               → '1h'
--   <= 24h                              → '24h'
--   <= 7d                               → '7d'
--   > 7d                                → 'permanent' (longer than policy max = treat as permanent)

START TRANSACTION;

-- ────────────────────────────────────────────────────────────
-- 1. Global bans: users.is_banned = 1
-- ────────────────────────────────────────────────────────────

INSERT INTO moderation_events (
    act, origin, actor_id, actor_role,
    target_user_id, scope, room_id,
    duration_type, expires_at,
    previous_room_role, reason
)
SELECT
    'ban_global',
    'migration',
    NULL,
    'system',
    u.id,
    'global',
    NULL,
    CASE
        WHEN u.banned_until IS NULL THEN 'permanent'
        WHEN u.banned_until <= DATE_ADD(u.banned_at, INTERVAL 1 HOUR)  THEN '1h'
        WHEN u.banned_until <= DATE_ADD(u.banned_at, INTERVAL 24 HOUR) THEN '24h'
        WHEN u.banned_until <= DATE_ADD(u.banned_at, INTERVAL 7 DAY)   THEN '7d'
        ELSE 'permanent'
    END,
    u.banned_until,
    NULL,
    'Imported from legacy schema'
FROM users u
WHERE u.is_banned = 1
  AND NOT EXISTS (
      SELECT 1 FROM active_restrictions ar
      WHERE ar.type = 'ban_global'
        AND ar.target_user_id = u.id
  )
  AND NOT EXISTS (
      SELECT 1 FROM moderation_events me
      WHERE me.act = 'ban_global'
        AND me.origin = 'migration'
        AND me.target_user_id = u.id
  );

INSERT INTO active_restrictions (type, target_user_id, room_id, expires_at, event_id)
SELECT
    'ban_global',
    me.target_user_id,
    NULL,
    me.expires_at,
    me.id
FROM moderation_events me
WHERE me.act = 'ban_global'
  AND me.origin = 'migration'
  AND me.reason = 'Imported from legacy schema'
  AND NOT EXISTS (
      SELECT 1 FROM active_restrictions ar
      WHERE ar.type = 'ban_global'
        AND ar.target_user_id = me.target_user_id
  )
ORDER BY me.id DESC;

-- ────────────────────────────────────────────────────────────
-- 2. Room bans: room_members.room_role = 'banned'
-- ────────────────────────────────────────────────────────────

INSERT INTO moderation_events (
    act, origin, actor_id, actor_role,
    target_user_id, scope, room_id,
    duration_type, expires_at,
    previous_room_role, reason
)
SELECT
    'ban_room',
    'migration',
    NULL,
    'system',
    rm.user_id,
    'room',
    rm.room_id,
    'permanent',   -- legacy room bans have no banned_until in current schema
    NULL,
    NULL,          -- previous_room_role unknown for legacy entries
    'Imported from legacy schema'
FROM room_members rm
WHERE rm.room_role = 'banned'
  AND NOT EXISTS (
      SELECT 1 FROM active_restrictions ar
      WHERE ar.type = 'ban_room'
        AND ar.target_user_id = rm.user_id
        AND ar.room_id = rm.room_id
  )
  AND NOT EXISTS (
      SELECT 1 FROM moderation_events me
      WHERE me.act = 'ban_room'
        AND me.origin = 'migration'
        AND me.target_user_id = rm.user_id
        AND me.room_id = rm.room_id
  );

INSERT INTO active_restrictions (type, target_user_id, room_id, expires_at, event_id)
SELECT
    'ban_room',
    me.target_user_id,
    me.room_id,
    me.expires_at,
    me.id
FROM moderation_events me
WHERE me.act = 'ban_room'
  AND me.origin = 'migration'
  AND me.reason = 'Imported from legacy schema'
  AND NOT EXISTS (
      SELECT 1 FROM active_restrictions ar
      WHERE ar.type = 'ban_room'
        AND ar.target_user_id = me.target_user_id
        AND ar.room_id = me.room_id
  )
ORDER BY me.id DESC;

-- ────────────────────────────────────────────────────────────
-- 3. Mutes: room_members.muted_until IS NOT NULL AND > NOW()
-- ────────────────────────────────────────────────────────────

INSERT INTO moderation_events (
    act, origin, actor_id, actor_role,
    target_user_id, scope, room_id,
    duration_type, expires_at,
    previous_room_role, reason
)
SELECT
    'mute',
    'migration',
    NULL,
    'system',
    rm.user_id,
    'room',
    rm.room_id,
    CASE
        WHEN rm.muted_until <= DATE_ADD(NOW(), INTERVAL 1 HOUR)  THEN '1h'
        WHEN rm.muted_until <= DATE_ADD(NOW(), INTERVAL 24 HOUR) THEN '24h'
        WHEN rm.muted_until <= DATE_ADD(NOW(), INTERVAL 7 DAY)   THEN '7d'
        ELSE '7d'
    END,
    rm.muted_until,
    NULL,
    'Imported from legacy schema'
FROM room_members rm
WHERE rm.muted_until IS NOT NULL
  AND rm.muted_until > NOW()
  AND NOT EXISTS (
      SELECT 1 FROM active_restrictions ar
      WHERE ar.type = 'mute'
        AND ar.target_user_id = rm.user_id
        AND ar.room_id = rm.room_id
  )
  AND NOT EXISTS (
      SELECT 1 FROM moderation_events me
      WHERE me.act = 'mute'
        AND me.origin = 'migration'
        AND me.target_user_id = rm.user_id
        AND me.room_id = rm.room_id
  );

INSERT INTO active_restrictions (type, target_user_id, room_id, expires_at, event_id)
SELECT
    'mute',
    me.target_user_id,
    me.room_id,
    me.expires_at,
    me.id
FROM moderation_events me
WHERE me.act = 'mute'
  AND me.origin = 'migration'
  AND me.reason = 'Imported from legacy schema'
  AND NOT EXISTS (
      SELECT 1 FROM active_restrictions ar
      WHERE ar.type = 'mute'
        AND ar.target_user_id = me.target_user_id
        AND ar.room_id = me.room_id
  )
ORDER BY me.id DESC;

COMMIT;
