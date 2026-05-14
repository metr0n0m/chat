-- ============================================================
-- 001_fix_foreign_keys.sql
-- Manual upgrade for existing installs — FK fixes only.
-- Run ONCE against an already-bootstrapped database.
-- Fresh installs: skip — migrations.sql already contains
-- the correct named constraints in CREATE TABLE.
-- ============================================================

-- ------------------------------------------------------------
-- STEP 1: Fix messages.user_id FK
--
-- The original schema created an unnamed FK (auto-named by
-- the engine, typically messages_ibfk_2). It had no ON DELETE
-- action (defaults to RESTRICT), which blocks user deletion.
--
-- Find the current name first:
--   SHOW CREATE TABLE messages;
-- Look for the CONSTRAINT on user_id referencing users(id).
-- Replace messages_ibfk_N below with the actual name found.
-- ------------------------------------------------------------

ALTER TABLE `messages` DROP FOREIGN KEY `messages_ibfk_2`;

ALTER TABLE `messages`
  ADD CONSTRAINT `fk_msg_user` FOREIGN KEY (`user_id`)
  REFERENCES `users`(`id`) ON DELETE CASCADE;

-- ------------------------------------------------------------
-- STEP 2: Add missing FK on messages.whisper_to
-- (no FK existed — dangling ref after user deletion)
-- ------------------------------------------------------------

ALTER TABLE `messages`
  ADD CONSTRAINT `fk_msg_whisper` FOREIGN KEY (`whisper_to`)
  REFERENCES `users`(`id`) ON DELETE SET NULL;

-- ------------------------------------------------------------
-- STEP 3: Add missing FK on messages.deleted_by
-- (no FK existed — dangling ref after user deletion)
-- ------------------------------------------------------------

ALTER TABLE `messages`
  ADD CONSTRAINT `fk_msg_deleted_by` FOREIGN KEY (`deleted_by`)
  REFERENCES `users`(`id`) ON DELETE SET NULL;

-- ------------------------------------------------------------
-- STEP 4: Add missing FK on room_members.banned_by
-- (no FK existed — dangling ref after user deletion)
-- ------------------------------------------------------------

ALTER TABLE `room_members`
  ADD CONSTRAINT `fk_rm_banned_by` FOREIGN KEY (`banned_by`)
  REFERENCES `users`(`id`) ON DELETE SET NULL;
