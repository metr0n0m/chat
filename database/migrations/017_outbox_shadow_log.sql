-- Migration 017: S2 outbox bridge + S3 shadow detector log
-- Depends on: 016_sanctions_engine_s0.sql
-- Design: docs/architecture/SANCTIONS_ENGINE.md §11 (мост HTTP→WS), §9.7 (shadow-режим)
-- Safe to run multiple times (CREATE TABLE IF NOT EXISTS).

-- ────────────────────────────────────────────────────────────
-- 1. ws_outbox: мост PHP-FPM → WS-процесс.
--    HTTP-код пишет событие, WS-процесс поллит (~1с), доставляет
--    и удаляет строку. Очередь, не журнал — история в moderation_events.
--    audience:
--      user       → доставка всем соединениям пользователя target_id
--      room       → всем участникам комнаты target_id
--      room_staff → только стаффу комнаты target_id (фильтр по ролям)
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `ws_outbox` (
  `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `created_at`      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `audience`        ENUM('user', 'room', 'room_staff') NOT NULL,
  `target_id`       INT UNSIGNED NOT NULL,        -- user_id или room_id
  `exclude_user_id` INT UNSIGNED NULL,            -- не доставлять этому пользователю
  `event_type`      VARCHAR(50) NOT NULL,         -- имя WS-события (для спец-обработки)
  `payload_json`    TEXT NOT NULL,                -- полное тело события

  INDEX `idx_outbox_order` (`id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 2. moderation_shadow_log: журнал срабатываний детекторов в
--    теневом режиме (would_sanction — что БЫ сделала система).
--    Диагностика/калибровка; без FK — записи не должны зависеть
--    от жизненного цикла users/rooms, цель может быть только IP.
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `moderation_shadow_log` (
  `id`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `created_at`     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `trigger_code`   VARCHAR(50) NOT NULL,
  `target_user_id` INT UNSIGNED NULL,
  `target_ip`      VARCHAR(45) NULL,
  `room_id`        INT UNSIGNED NULL,
  `would_sanction` VARCHAR(20) NOT NULL,          -- ban_room / ban_global / none
  `would_duration` ENUM('1h', '3h', '24h', '7d', '30d', 'permanent') NULL,
  `details`        VARCHAR(500) NULL,

  INDEX `idx_shadow_trigger_time` (`trigger_code`, `created_at`),
  INDEX `idx_shadow_target`       (`target_user_id`),
  INDEX `idx_shadow_ip`           (`target_ip`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
