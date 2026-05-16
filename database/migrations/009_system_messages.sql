-- 009_system_messages.sql
-- Adds typed metadata for system chat messages.

ALTER TABLE `messages`
  ADD COLUMN IF NOT EXISTS `system_importance`
    ENUM('normal','optional','important') NULL DEFAULT NULL AFTER `type`,
  ADD COLUMN IF NOT EXISTS `system_scope`
    VARCHAR(64) NULL DEFAULT NULL AFTER `system_importance`;
