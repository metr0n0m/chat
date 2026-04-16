SET NAMES utf8mb4;
SET foreign_key_checks = 0;

CREATE TABLE IF NOT EXISTS `users` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username`        VARCHAR(50) NOT NULL,
  `email`           VARCHAR(255) UNIQUE,
  `password_hash`   VARCHAR(255),
  `avatar_url`      VARCHAR(500),
  `signature`       VARCHAR(300),
  `nick_color`      CHAR(7) DEFAULT '#ffffff',
  `text_color`      CHAR(7) DEFAULT '#dee2e6',
  `global_role`     ENUM('platform_owner','admin','moderator','user') NOT NULL DEFAULT 'user',
  `oauth_provider`  VARCHAR(20),
  `oauth_id`        VARCHAR(100),
  `can_create_room` TINYINT(1) DEFAULT 0,
  `is_banned`       TINYINT(1) DEFAULT 0,
  `last_seen_at`    DATETIME,
  `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_username` (`username`),
  INDEX `idx_global_role` (`global_role`),
  INDEX `idx_oauth` (`oauth_provider`, `oauth_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sessions` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NOT NULL,
  `token_hash`  CHAR(64) NOT NULL,
  `ip_ua_hash`  CHAR(64) NOT NULL,
  `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
  `expires_at`  DATETIME NOT NULL,
  UNIQUE KEY `unique_token` (`token_hash`),
  INDEX `idx_token` (`token_hash`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rooms` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`         VARCHAR(100) NOT NULL,
  `description`  VARCHAR(300),
  `type`         ENUM('public','numer') NOT NULL DEFAULT 'public',
  `owner_id`     INT UNSIGNED,
  `max_members`  TINYINT UNSIGNED DEFAULT NULL,
  `is_closed`    TINYINT(1) DEFAULT 0,
  `closed_at`    DATETIME,
  `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_type_closed` (`type`, `is_closed`),
  FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `room_members` (
  `room_id`    INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `room_role`  ENUM('owner','local_admin','local_moderator','member','banned') DEFAULT 'member',
  `joined_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
  `banned_at`  DATETIME,
  `banned_by`  INT UNSIGNED,
  PRIMARY KEY (`room_id`, `user_id`),
  INDEX `idx_room_role` (`room_id`, `room_role`),
  FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messages` (
  `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `room_id`       INT UNSIGNED NOT NULL,
  `user_id`       INT UNSIGNED NOT NULL,
  `content`       TEXT NOT NULL,
  `content_hmac`  CHAR(64) NOT NULL,
  `type`          ENUM('text','system','whisper') DEFAULT 'text',
  `whisper_to`    INT UNSIGNED DEFAULT NULL,
  `embed_data`    JSON,
  `is_deleted`    TINYINT(1) DEFAULT 0,
  `deleted_by`    INT UNSIGNED,
  `created_at`    DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  INDEX `idx_room_time` (`room_id`, `created_at`),
  INDEX `idx_whisper` (`whisper_to`),
  FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invitations` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `room_id`      INT UNSIGNED,
  `from_user_id` INT UNSIGNED NOT NULL,
  `to_user_id`   INT UNSIGNED NOT NULL,
  `status`       ENUM('pending','accepted','declined','cancelled','expired') DEFAULT 'pending',
  `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP,
  `responded_at` DATETIME,
  `expires_at`   DATETIME NOT NULL,
  INDEX `idx_to_pending` (`to_user_id`, `status`),
  FOREIGN KEY (`from_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`to_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `friendships` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `requester_id` INT UNSIGNED NOT NULL,
  `addressee_id` INT UNSIGNED NOT NULL,
  `status`       ENUM('pending','accepted','declined','blocked') DEFAULT 'pending',
  `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME,
  UNIQUE KEY `unique_pair` (`requester_id`, `addressee_id`),
  INDEX `idx_addressee` (`addressee_id`, `status`),
  FOREIGN KEY (`requester_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`addressee_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `oauth_tokens` (
  `id`                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`                 INT UNSIGNED NOT NULL,
  `provider`                VARCHAR(20) NOT NULL,
  `access_token_encrypted`  TEXT NOT NULL,
  `refresh_token_encrypted` TEXT,
  `expires_at`              DATETIME,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `avatar_uploads` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NOT NULL,
  `file_path`   VARCHAR(500) NOT NULL,
  `file_size`   INT UNSIGNED,
  `uploaded_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;

-- Seed: default public rooms
INSERT IGNORE INTO `rooms` (`id`, `name`, `description`, `type`) VALUES
(1, 'Общий', 'Главный публичный чат', 'public'),
(2, 'Флудилка', 'Разговоры обо всём', 'public');
