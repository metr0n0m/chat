-- ============================================================
-- 010_show_system_messages.sql
-- Adds user preference for system message visibility.
-- DEFAULT 1 = show (no backfill needed for existing users).
-- ============================================================

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `show_system_messages` TINYINT(1) NOT NULL DEFAULT 1;
