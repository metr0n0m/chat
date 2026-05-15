-- ============================================================
-- 007_messages_deleted_at.sql
-- Adds deleted_at timestamp for messages soft-delete tracking.
-- Safe to rerun on MariaDB versions that support IF NOT EXISTS.
-- ============================================================

ALTER TABLE `messages`
  ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME NULL AFTER `deleted_by`;
