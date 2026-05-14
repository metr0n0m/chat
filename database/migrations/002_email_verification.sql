-- ============================================================
-- 002_email_verification.sql
-- Adds email verification support for existing installs.
-- Run ONCE after 001_fix_foreign_keys.sql.
-- ============================================================

-- Add email_verified flag to users.
-- Default 0. After running, mark all existing users as verified:
--   UPDATE users SET email_verified = 1;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `email_verified`
    TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_banned`;

-- Verification token store.

CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  `used_at`    DATETIME DEFAULT NULL,
  UNIQUE KEY `unique_token` (`token_hash`),
  INDEX `idx_user` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
