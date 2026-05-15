-- ============================================================
-- 008_rooms_close_reason.sql
-- Adds close_reason to track why a numer was closed.
-- Safe to rerun: IF NOT EXISTS.
-- ============================================================

ALTER TABLE `rooms`
  ADD COLUMN IF NOT EXISTS `close_reason` ENUM('last_left','idle','admin') NULL AFTER `closed_at`;
