-- ============================================================
-- 004_ban_metadata.sql
-- Adds ban metadata columns used by UserManager.
-- Run ONCE on existing installs after 003_reactor_raw.sql.
-- ============================================================

-- users: global ban metadata
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `banned_at`    DATETIME     DEFAULT NULL AFTER `is_banned`,
  ADD COLUMN IF NOT EXISTS `banned_by`    INT UNSIGNED DEFAULT NULL AFTER `banned_at`,
  ADD COLUMN IF NOT EXISTS `banned_until` DATETIME     DEFAULT NULL AFTER `banned_by`,
  ADD COLUMN IF NOT EXISTS `ban_reason`   VARCHAR(255) DEFAULT NULL AFTER `banned_until`;

-- FK: banned_by references the admin who issued the ban.
-- Run only if constraint does not already exist.
-- Check first: SELECT * FROM information_schema.TABLE_CONSTRAINTS
--   WHERE TABLE_NAME='users' AND CONSTRAINT_NAME='fk_users_banned_by';
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_banned_by`
    FOREIGN KEY (`banned_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- room_members: ban reason (banned_at and banned_by already exist)
ALTER TABLE `room_members`
  ADD COLUMN IF NOT EXISTS `ban_reason` VARCHAR(255) DEFAULT NULL AFTER `banned_by`;
