-- Migration 014: active_restrictions — operational hot-path table
-- Depends on: 013_moderation_events.sql, users, rooms tables
-- Safe to run multiple times (CREATE TABLE IF NOT EXISTS)
--
-- Single source of truth for currently active restrictions.
-- Kept small: only live entries. Deleted on lift or expire.
-- History is append-only in moderation_events.
--
-- Uniqueness: one active restriction per (type, target_user_id, scope).
-- scope_key is a STORED GENERATED column:
--   room_id IS NULL  → 'global'
--   room_id = N      → 'room:N'
-- This avoids NULL != NULL issue in composite UNIQUE keys (SQL standard behaviour).
-- Verified on MariaDB 10.11.
--
-- FK on room_id enforces referential integrity for room-scoped restrictions.
-- FK on event_id links to the originating moderation_events row (ON DELETE RESTRICT
-- prevents deleting the event while restriction is still active).

CREATE TABLE IF NOT EXISTS `active_restrictions` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `type`           ENUM('ban_room', 'ban_global', 'mute') NOT NULL,
  `target_user_id` INT UNSIGNED NOT NULL,
  `room_id`        INT UNSIGNED NULL,          -- NULL for global scope
  `expires_at`     DATETIME NULL,              -- NULL = permanent
  `event_id`       BIGINT UNSIGNED NOT NULL,   -- originating moderation_events.id

  -- Generated column for deterministic uniqueness key (avoids NULL != NULL)
  `scope_key`      VARCHAR(32) AS (
                     CASE
                       WHEN `room_id` IS NULL THEN 'global'
                       ELSE CONCAT('room:', `room_id`)
                     END
                   ) STORED,

  UNIQUE KEY `uq_restriction` (`type`, `target_user_id`, `scope_key`),

  INDEX `idx_ar_target` (`target_user_id`),
  INDEX `idx_ar_expiry` (`expires_at`),
  INDEX `idx_ar_event`  (`event_id`),

  CONSTRAINT `fk_ar_target`
    FOREIGN KEY (`target_user_id`) REFERENCES `users`(`id`)             ON DELETE CASCADE,
  CONSTRAINT `fk_ar_room`
    FOREIGN KEY (`room_id`)        REFERENCES `rooms`(`id`)             ON DELETE CASCADE,
  CONSTRAINT `fk_ar_event`
    FOREIGN KEY (`event_id`)       REFERENCES `moderation_events`(`id`) ON DELETE RESTRICT

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

