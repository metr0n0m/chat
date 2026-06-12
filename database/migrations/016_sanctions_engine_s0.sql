-- Migration 016: sanctions engine S0 — schema groundwork
-- Depends on: 013_moderation_events.sql, 014_active_restrictions.sql, users, rooms
-- Design: docs/architecture/SANCTIONS_ENGINE.md v0.1 (owner decisions 2026-06-09)
-- Safe to run multiple times (IF NOT EXISTS guards; MODIFY is idempotent).

-- ────────────────────────────────────────────────────────────
-- 1. moderation_events: IP snapshots + open trigger dictionary
--    actor_ip / target_ip are snapshots at incident time (sessions
--    are destroyed on ban, so the IP must be captured in the log).
--    trigger_code is a free VARCHAR (open dictionary, no ENUM —
--    new detector types must not require a schema migration).
-- ────────────────────────────────────────────────────────────

ALTER TABLE `moderation_events`
  ADD COLUMN IF NOT EXISTS `actor_ip`     VARCHAR(45) NULL AFTER `actor_role`,
  ADD COLUMN IF NOT EXISTS `target_ip`    VARCHAR(45) NULL AFTER `target_user_id`,
  ADD COLUMN IF NOT EXISTS `trigger_code` VARCHAR(50) NULL AFTER `reason`;

CREATE INDEX IF NOT EXISTS `idx_trigger_time` ON `moderation_events` (`trigger_code`, `created_at`);
CREATE INDEX IF NOT EXISTS `idx_target_ip`    ON `moderation_events` (`target_ip`);

-- ────────────────────────────────────────────────────────────
-- 2. duration_type: extend to the approved autoban ladder
--    1h → 3h → 24h → 7d → 30d → permanent
--    (appending ENUM values only — existing data is untouched)
-- ────────────────────────────────────────────────────────────

ALTER TABLE `moderation_events`
  MODIFY COLUMN `duration_type` ENUM('1h', '3h', '24h', '7d', '30d', 'permanent') NULL;

-- ────────────────────────────────────────────────────────────
-- 3. stop_words: global (platform_owner) and per-room (room owner) lists
--    scope_key generated column mirrors active_restrictions (014)
--    to get a deterministic UNIQUE key without NULL != NULL issues.
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `stop_words` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `scope`      ENUM('global', 'room') NOT NULL,
  `room_id`    INT UNSIGNED NULL,               -- NULL for global scope
  `pattern`    VARCHAR(255) NOT NULL,           -- matched on send_message before insert
  `action`     VARCHAR(30)  NOT NULL DEFAULT 'ban_room',
  `duration`   ENUM('1h', '3h', '24h', '7d', '30d', 'permanent') NOT NULL DEFAULT '1h',
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  `scope_key`  VARCHAR(32) AS (
                 CASE
                   WHEN `room_id` IS NULL THEN 'global'
                   ELSE CONCAT('room:', `room_id`)
                 END
               ) STORED,

  UNIQUE KEY `uq_stop_word` (`pattern`, `scope_key`),
  INDEX `idx_sw_room` (`room_id`),

  CONSTRAINT `fk_sw_room`
    FOREIGN KEY (`room_id`)    REFERENCES `rooms`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sw_creator`
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 4. sanction_rules: engine configuration (thresholds, ladders,
--    weights, circuit-breaker, mode). Key → JSON value.
--    "New behaviour is added via config and detectors, not code."
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `sanction_rules` (
  `rule_key`   VARCHAR(50) NOT NULL PRIMARY KEY,
  `value_json` TEXT NOT NULL,
  `updated_by` INT UNSIGNED NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT `fk_sr_updater`
    FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_sr_json` CHECK (JSON_VALID(`value_json`))

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default configuration (SANCTIONS_ENGINE.md §15). Shadow mode by default:
-- detectors log would_sanction but never ban until the owner flips to live.
INSERT IGNORE INTO `sanction_rules` (`rule_key`, `value_json`) VALUES
  ('mode',              '"shadow"'),
  ('escalation',        '{"stopword":{"start":"1h","threshold":5,"ladder":["1h","24h","7d","30d","permanent"]},"bruteforce":{"start":"3h","window_min":15,"attempts":10,"ladder":["3h","24h","7d","30d","permanent"]},"flood":{"start":"3h","threshold":5,"ladder":["3h","24h","7d","30d","permanent"]}}'),
  ('danger_weights',    '{"auth_attack":10,"malicious_content":8,"flood":7,"stopword":4,"spam":3,"ratelimit":1}'),
  ('danger_thresholds', '{"1h":5,"3h":15,"24h":30,"7d":50,"30d":65,"permanent":80}'),
  ('circuit_breaker',   '{"max_system_sanctions_per_min":10}');

-- ────────────────────────────────────────────────────────────
-- 5. login_attempts: brute-force counters (per account AND per IP).
--    attempt_key: 'acc:<user_id>' or 'ip:<address>'.
--    PHP-FPM is stateless, so counters live in the DB.
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `attempt_key`     VARCHAR(64) NOT NULL PRIMARY KEY,
  `fail_count`      INT UNSIGNED NOT NULL DEFAULT 0,
  `window_start`    DATETIME NOT NULL,
  `last_attempt_at` DATETIME NOT NULL,

  INDEX `idx_la_window` (`window_start`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
