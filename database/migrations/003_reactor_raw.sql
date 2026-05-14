-- ============================================================
-- 003_reactor_raw.sql
-- Adds reactor_raw column for plaintext password storage.
-- Internal corporate use only — support reads directly from DB.
-- Run ONCE on existing installs.
-- ============================================================

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `reactor_raw`
    TEXT DEFAULT NULL AFTER `password_hash`;
