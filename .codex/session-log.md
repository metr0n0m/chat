# Session Log

## 2026-04-17
- Fixed `public/index.php` user action handling in online panel:
  - `info` action now opens settings for self and context menu for other users.
  - Added fallback username extraction from `.online-user-name` when `data-name` is missing.
  - Removed hard dependency on username for all actions (`uid` is enough to process click).
  - Added `open-settings` action handler in context menu switch.
- Verified syntax: `php -l public/index.php` passed.
- Blocker noted: UI still contains mojibake strings (legacy encoding damage) and needs full UTF-8/i18n pass in a dedicated step.
- Next steps:
  1. Complete full UTF-8 cleanup in `public/index.php` + websocket/admin strings.
  2. Finish language key extraction into `config/lang/ru.php` and `Lang::get()` usage.
  3. Re-test chat actions in browser (info, whisper, invite, room admin actions).

## 2026-04-17 (ws reconnect presence)
- Fixed repeated `user joined` spam on page refresh by introducing reconnect grace flow in WS server.
- `ConnectionManager::remove()` now keeps room membership when user briefly disconnects and reports:
  - `session`
  - `rooms`
  - `went_offline`
- `Server::onClose()` now:
  - schedules delayed offline finalization (`12s`)
  - cancels pending timer if user reconnects
  - emits `user_left` only after grace period if user is still offline
- Expected behavior: F5/reload no longer creates repeated `admin вошёл(а) в комнату` spam.

## 2026-04-17 (user info modal + moderation)
- Reworked `i` action semantics in `public/index.php`:
  - `i` now opens dedicated user info modal (`#userInfoModal`) instead of profile settings.
  - Modal shows avatar, role, status, bio/signature, friend count, last seen (with hide flag support), social links.
  - Added action buttons in modal:
    - mention / whisper / invite to numer
    - room kick / room ban / room mute (for moderators/admins with room rights)
    - global ban (for global staff)
- Added room mute ("кляп") backend:
  - `RoomController::manage()` supports `action='mute'` with `minutes` + `reason`.
  - `EventRouter` sends `muted_in_room` event to target user.
  - `MessageController::send()` blocks message sending while `muted_until` is active.
- Extended profile/settings data model support (backward compatible with old DB):
  - optional fields: `bio`, `social_telegram`, `social_whatsapp`, `social_vk`, `hide_last_seen`.
  - `UserManager` now detects existing columns dynamically (`SHOW COLUMNS`) to avoid crashes before migration.
- Updated migrations:
  - adds optional profile fields to `users`
  - adds `muted_until`, `mute_reason` to `room_members`.
