-- Migration 013: moderation_events — append-only audit log
-- Depends on: users, rooms tables
-- Safe to run multiple times (CREATE TABLE IF NOT EXISTS)

CREATE TABLE IF NOT EXISTS `moderation_events` (
  `id`                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `created_at`         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

  -- What happened
  `act`                ENUM(
                         'kick',
                         'ban_room', 'ban_global', 'mute',
                         'unban_room', 'unban_global', 'unmute',
                         'restriction_expired'
                       ) NOT NULL,

  -- Source of this event
  `origin`             ENUM('realtime', 'migration', 'system') NOT NULL DEFAULT 'realtime',

  -- Link to the ban event this action references (for unban/expire)
  `parent_event_id`    BIGINT UNSIGNED NULL,

  -- Who performed the action
  `actor_id`           INT UNSIGNED NULL,          -- NULL for system/migration
  `actor_role`         VARCHAR(30) NOT NULL,        -- snapshot at time of action; 'system' for automated

  -- Who was affected
  `target_user_id`     INT UNSIGNED NULL,           -- NULL if user deleted

  -- Scope
  `scope`              ENUM('global', 'room') NOT NULL,
  `room_id`            INT UNSIGNED NULL,           -- NULL for global scope

  -- Restriction details (populated for ban/mute apply events)
  `duration_type`      ENUM('1h', '24h', '7d', 'permanent') NULL,
  `expires_at`         DATETIME NULL,               -- NULL = permanent

  -- Role snapshot before ban (for audit and Phase S4 migration restore)
  `previous_room_role` ENUM('owner', 'local_admin', 'local_moderator', 'member') NULL,

  -- Reason
  `reason`             VARCHAR(500) NULL,

  INDEX `idx_target`        (`target_user_id`),
  INDEX `idx_actor`         (`actor_id`),
  INDEX `idx_scope`         (`scope`, `room_id`),
  INDEX `idx_act_time`      (`act`, `created_at`),
  INDEX `idx_parent`        (`parent_event_id`),

  CONSTRAINT `fk_me_actor`
    FOREIGN KEY (`actor_id`)       REFERENCES `users`(`id`)              ON DELETE SET NULL,
  CONSTRAINT `fk_me_target`
    FOREIGN KEY (`target_user_id`) REFERENCES `users`(`id`)              ON DELETE SET NULL,
  CONSTRAINT `fk_me_room`
    FOREIGN KEY (`room_id`)        REFERENCES `rooms`(`id`)              ON DELETE SET NULL,
  CONSTRAINT `fk_me_parent`
    FOREIGN KEY (`parent_event_id`) REFERENCES `moderation_events`(`id`) ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
