# Chat Project Operating Document

Last audited: 2026-05-14  
Application root: `C:\inetpub\vhosts\chat\httpdocs`  
Production root: `/var/vhosts/chat` serving `/var/vhosts/chat/public` for `chat.adalex.org`  
Primary branch: `main`

This file is the persistent handoff document for Claude CLI/Codex. Read it before changing the project. Update it after each meaningful change or deploy so the next session can continue without repeating a full audit.

## 1. Product Concept

The project is a Russian-language realtime web chat with:

- public chat rooms;
- private temporary rooms called `numera`;
- whispers/private messages inside the chat context;
- global roles and room-local roles;
- moderation/admin tools;
- user profiles with avatar, colors, custom display status, social/bio fields;
- OAuth login via VK and Google;
- email verification work currently in progress;
- mobile-oriented chat shell and right-side online users rail;
- persistent MariaDB storage and Ratchet/ReactPHP WebSocket realtime layer.

The application is not a landing site. The first screen is the chat/login experience.

## 2. Current Repository State

As of this audit, `httpdocs` is a git repository on `main` tracking `origin/main`.

Latest commits:

- `94a2265 fix(auth): refresh csrf on restored auth pages`
- `0a4dcd3 feat(chat): attribute messages to sessions`
- `b4e48f0 fix(auth): allow sessions across IP changes`
- `8cabc95 feat(ui): add mobile users rail dock`
- `d332fe4 fix: wrap chat composer controls`
- `fbb4967 fix: match chat textarea initial height`

Working tree is dirty. Do not assume the dirty changes are deployed or production-ready.

Dirty tracked files:

- `composer.json`
- `composer.lock`
- `public/assets/js/chat-auth.js`
- `public/assets/js/chat.js`
- `public/index.php`
- `src/Admin/RoomManager.php`
- `src/Admin/UserManager.php`
- `src/Auth/GoogleOAuth.php`
- `src/Auth/LoginHandler.php`
- `src/Auth/RegisterHandler.php`
- `src/Auth/VKOAuth.php`
- `src/DB/migrations.sql`
- `src/Http/Router.php`

Untracked files:

- `public/test-mail.php`
- `src/Auth/EmailVerification.php`
- `src/Mail/Mailer.php`

Interpretation: the active local work appears to be a combined email verification/SMTP feature plus admin ban/mute/profile/system-settings expansions. It needs review and fixes before deployment.

## 3. Runtime Stack

Local development:

- Windows workspace.
- Docker stack in `docker-compose.yml`.
- Nginx exposed on `127.0.0.1:8080`.
- WebSocket/debug Nginx port `8081`.
- MariaDB container `chat_db`, host port `3307`, database `chat`, user `chat_user`.
- phpMyAdmin on `127.0.0.1:8082`.

Production target:

- Debian 12.
- Nginx.
- PHP-FPM.
- MariaDB.
- WebSocket process must be restarted after backend code deploys because it is a long-running PHP process.

Important version mismatch:

- Project instructions mention deployment target PHP 8.2.
- Current `composer.json` requires `"php": "^8.4"`.
- Before next production deploy, confirm production PHP version. If production is still PHP 8.2, either lower the Composer platform requirement or upgrade production PHP. This is a deploy blocker if Composer is run on production.

## 4. Configuration And Secrets

Runtime config is expected in `config/config.php`, excluded from git. Example config is `config/config.example.php`.

Known config constants:

- database: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`;
- app: `APP_NAME`, `APP_URL`, `APP_LOCALE`, `APP_SECRET`;
- WebSocket: `WS_PORT`, `WS_HOST`, `WS_BIND_HOST`;
- OAuth: `VK_CLIENT_ID`, `VK_CLIENT_SECRET`, `VK_REDIRECT_URI`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`;
- storage: `STORAGE_PATH`, `AVATAR_PATH`, `AVATAR_URL_PREFIX`;
- sessions: `COOKIE_DOMAIN`, `SESSION_LIFETIME`;
- encryption/rate limits: `OAUTH_ENCRYPT_KEY`, `MSG_RATE_LIMIT_SEC`, `WHISPER_RATE_LIMIT_MIN`, `INVITE_PENDING_MAX`, `NUMER_IDLE_TIMEOUT`.

Active local mail work adds required constants that are not in `config.example.php` yet:

- `SMTP_HOST`
- `SMTP_USER`
- `SMTP_PASS`
- `SMTP_PORT`
- `MAIL_FROM`

Before committing email verification, update `config.example.php` with placeholder mail constants and make sure real SMTP secrets stay outside git.

## 5. Code Map

HTTP entrypoint:

- `public/index.php` bootstraps config, Composer autoload, language layer, router, current user and page shell.
- `src/Http/Router.php` dispatches auth, public API, user API, admin API and avatar serving.

Realtime entrypoint:

- `ws-server.php` starts Ratchet/ReactPHP WebSocket server and periodic invitation expiry.
- `src/WebSocket/Server.php` authenticates connections from `chat_session` cookie, registers connections and handles reconnect grace.
- `src/WebSocket/EventRouter.php` handles websocket events: join/leave room, send/delete message, whisper, numer invite/response/leave, counts, online list, room actions, staff calls.
- `src/WebSocket/ConnectionManager.php` keeps in-memory connection/session/room membership maps and sends JSON events.

Core chat:

- `src/Chat/RoomController.php`: public rooms, room creation, join, local role management, kick/ban/mute, numer listing, member listing.
- `src/Chat/MessageController.php`: message formatting, history, send, delete, access control.
- `src/Chat/WhisperController.php`: whisper send/archive/delete/clear. Also checks mute state.
- `src/Chat/NumerController.php`: invite/respond/leave/cleanup for temporary numer rooms.
- `src/Chat/NumerPage.php`: standalone numer page.
- `src/Chat/EmbedProcessor.php`: rich embeds for message links.

Admin:

- `src/Admin/AdminPanel.php`: dashboard, global moderators, room creators, custom-status override setting, user creation, system settings.
- `src/Admin/UserManager.php`: user list/update/delete/profile/settings/avatar upload, ban/mute listing and unban/unmute.
- `src/Admin/RoomManager.php`: admin room/numer/archive/message operations.

Auth/security/support:

- `src/Auth/LoginHandler.php`: username/email login, session creation.
- `src/Auth/RegisterHandler.php`: registration; currently modified for email verification.
- `src/Auth/VKOAuth.php`, `src/Auth/GoogleOAuth.php`: OAuth flows and OAuth token storage.
- `src/Auth/EmailVerification.php`: untracked active work for verification/resend.
- `src/Mail/Mailer.php`: untracked active SMTP mailer.
- `src/Security/Session.php`: DB-backed session tokens; current validation is by token hash + expiry, not fixed IP/UA.
- `src/Security/CSRF.php`: CSRF token helper.
- `src/Security/HMAC.php`: HMAC helper; current messages often store nullable/empty `content_hmac`.
- `src/Security/ColorContrast.php`: color validation.
- `src/Support/Timestamp.php`: output boundary for UTC ISO-8601 timestamps.
- `src/Support/Lang.php` and `config/lang/ru.php`: server-side language layer.

Frontend assets:

- `public/assets/js/chat.js`: main chat UI behavior. Large file; prefer small scoped edits or extraction, not broad rewrites.
- `public/assets/js/chat-auth.js`: login/register/auth form behavior.
- `public/assets/js/chat-display.js`: display labels/status helpers.
- `public/assets/js/chat-input.js`: input/composer helpers.
- `public/assets/js/chat-shell.js`: responsive shell/mobile drawer/rail logic.
- `public/assets/js/chat-time.js`: date/time formatting boundary.
- `public/assets/js/chat-utils.js`: shared frontend utilities.
- `public/assets/css/chat.css`: chat shell/layout/mobile CSS.

## 6. Database Model

Main migration file: `src/DB/migrations.sql`.

Core tables:

- `users`: accounts, colors, role, avatar, status, OAuth id, room-create permission, ban flag, profile fields, email verification fields in current dirty migration.
- `sessions`: DB-backed login tokens.
- `rooms`: public and numer rooms, owner, category, closed state.
- `room_members`: membership and local roles, room bans, mutes in current dirty migration.
- `messages`: room messages, whispers, embeds, deletion, sender session attribution, color snapshots.
- `invitations`: numer invites.
- `friendships`: friend graph.
- `oauth_tokens`: encrypted provider tokens.
- `app_settings`: runtime system/admin settings.
- `avatar_uploads`: uploaded avatar audit.
- `email_verifications`: active local dirty migration for email confirmation.

Important schema risk in current dirty tree:

- `UserManager.php` references `users.banned_at`, `users.banned_by`, `users.banned_until`, `users.ban_reason`.
- `UserManager.php` also references `room_members.ban_reason`.
- Current `migrations.sql` does not add those columns.
- Result: admin global ban/list banned paths can fail with SQL errors after deploy unless migration is completed.

Before deploying the current dirty tree, add idempotent `ALTER TABLE` statements for all referenced ban metadata columns or remove the code paths.

## 7. Roles And Permissions

Global roles, highest first:

- `platform_owner`
- `admin`
- `moderator`
- `user`

Key rules:

- `platform_owner` is above admins and can manage admin roles/status override.
- `admin` can access admin panel but cannot manage users with equal/higher role.
- `moderator` can moderate public chat but must not moderate private numera.
- Users can receive `can_create_room`.

Room roles:

- `owner`
- `local_admin`
- `local_moderator`
- `member`
- `banned`

Public rooms are visible to authenticated users unless they are banned from that room. Numer rooms require membership.

## 8. Implemented Features

Implemented and previously verified in prior sessions:

- Git repository initialized and pushed to GitHub `origin/main`.
- Local Docker MariaDB schema initialized.
- Platform owner test user existed in Docker as `admin/admin` during earlier sessions.
- Login accepts username or email.
- DB-backed sessions allow multiple sessions and no longer bind validation to fixed IP/UA.
- Public rooms are visible to all authenticated non-banned users.
- WebSocket join is idempotent for same room.
- Main feed renders inline username/time/message rows.
- Profile settings include username/password/avatar/color/custom status and newer profile/social fields in dirty tree.
- Custom status is separate from global role.
- Owner/admin/admin-override logic for custom statuses exists.
- Right online panel has user identity/actions.
- Room management modal supports rename/delete/local roles/kick/ban/mute.
- Permanent seeded rooms cannot be deleted.
- Room delete removes dependent messages/members/invitations transactionally.
- Message history timestamps normalize to ISO-8601 UTC via `Timestamp`.
- Live WebSocket payloads normalize timestamp fields at the output boundary.
- Message sender session attribution exists via `messages.sender_session_id`.
- Numer owner transfer on leave exists.
- Numer invite accepted flow refreshes room lists and opens target numer when known.
- Self-whisper and self-numer creation are allowed.
- Staff call `@!` emits special warning payloads.
- Mobile users rail/dock shell was implemented and deployed up to commit `8cabc95`.

## 9. Active Local Work Not Yet Safe To Deploy

Email verification/SMTP:

- `src/Auth/RegisterHandler.php` now creates unverified users, stores raw password in `users.reactor_raw`, creates verification token and sends mail.
- `src/Auth/LoginHandler.php` blocks login if `email_verified = 0`.
- `src/Auth/EmailVerification.php` verifies token, marks user verified, creates session.
- `src/Auth/EmailVerification.php` supports resend with 60-second cooldown.
- `src/Mail/Mailer.php` uses PHPMailer/SMTP.
- `composer.json` now includes `phpmailer/phpmailer`.
- `public/assets/js/chat-auth.js` and `public/index.php` were changed to support pending verification UI.

Risks before deploy:

- Do not store plaintext passwords in `users.reactor_raw` unless there is a clear temporary migration reason. This is a severe security issue.
- `public/test-mail.php` is a public diagnostic endpoint with SMTP debug output and hard-coded recipient addresses. It must be deleted or blocked before production.
- Mail constants are not documented in `config.example.php`.
- Confirm registration behavior for OAuth users versus email/password users.
- Confirm whether existing users should be backfilled to `email_verified = 1`.

Ban/mute/system settings:

- Room mute exists in `RoomController`, `MessageController`, `WhisperController` and frontend action menus.
- Admin ban/mute list/unban/unmute paths exist in dirty tree.
- But DB migration is incomplete for ban metadata columns, as noted above.

## 10. Known Technical Debt And Open Gaps

High priority:

- Complete and test DB migrations for global ban metadata and room ban reason.
- Remove or secure `public/test-mail.php` before any deploy.
- Remove plaintext password persistence in `reactor_raw`, or document and delete it after one-time migration if absolutely required.
- Reconcile PHP version requirement (`^8.4`) with production target (document says PHP 8.2).
- Add mail constants to `config.example.php`.
- Run syntax checks after dirty changes: `php -l` on changed PHP files.
- Run a live smoke test for email verification, resend, login block/unblock and OAuth login.
- Restart WebSocket process after backend deploys.

Medium priority:

- `public/assets/js/chat.js` is very large; keep future changes small or continue extraction.
- `content_hmac` protection is weaker now because current migration allows null and message send stores empty string. Decide whether HMAC verification should remain a security property or be removed cleanly.
- Admin and room moderation logic is spread across frontend action menus, `RoomController`, `UserManager`, `RoomManager` and `EventRouter`; future changes need end-to-end tests.
- Existing app settings defaults live in code, not all are seeded in migrations.
- Maintenance mode/settings are in admin backend but need full request-level behavior audit.

Lower priority:

- Add automated tests or at least repeatable smoke scripts for auth, message send, whisper, numer invite, moderation.
- Document production service commands for WebSocket restart once confirmed.
- Review CSP after inline style allowances for message colors.

## 11. Deployment Workflow

Use SSH for GitHub. Do not commit secrets.

Local helper scripts:

- `tools/git-bootstrap.ps1`: initialize/connect remote.
- `tools/git-sync.ps1`: routine status/add/commit/push.
- `tools/git-restore.ps1`: restore/clone from GitHub.
- `tools/GITHUB_SETUP.md`: GitHub setup instructions.

Known production deploy path from prior sessions:

1. Commit and push local changes to `origin/main`.
2. On server, fast-forward `/var/vhosts/chat` to `origin/main`.
3. Apply DB migrations manually/through approved process before code depending on new columns runs.
4. Run `php -l` for changed PHP files on production.
5. Restart WebSocket process so long-running PHP loads new code.
6. Run live smoke tests.

Previous deploy blocker:

- `/var/vhosts/chat` was root-owned; `admin` could not write `.git/FETCH_HEAD`.
- Root/sudo path was used later via `su -`.
- If deploy fails again, check filesystem ownership and sudo/root path rather than trying to work around it in git.

## 12. Suggested Task Breakdown For Claude CLI

When assigning Claude small tasks, use slices like these:

- "Audit and fix email verification schema/config only."
- "Remove plaintext password storage and update RegisterHandler tests/smoke notes."
- "Delete or lock down public/test-mail.php and document SMTP test process."
- "Complete ban metadata migrations and verify UserManager SQL."
- "Run php -l on all changed PHP files and report failures."
- "Smoke test registration -> email token -> verified login."
- "Smoke test room mute: mute, blocked send, unmute, successful send."
- "Prepare deploy commit and update this CLAUDE.md deploy log."

Avoid giving one task that changes auth, mail, admin bans, JS and deployment at once.

## 13. Verification Commands

Useful local commands from `httpdocs`:

```powershell
git status --short --branch
git log --oneline -12
php -l public/index.php
php -l src/Http/Router.php
php -l src/Auth/RegisterHandler.php
php -l src/Auth/LoginHandler.php
php -l src/Auth/EmailVerification.php
php -l src/Mail/Mailer.php
php -l src/Admin/UserManager.php
php -l src/Admin/RoomManager.php
php -l src/Chat/RoomController.php
php -l src/Chat/MessageController.php
```

Docker URLs:

- app: `http://127.0.0.1:8080`
- phpMyAdmin: `http://127.0.0.1:8082`

## 14. Deploy Log Template

Append entries here after each deploy or meaningful local checkpoint.

Format:

```text
### YYYY-MM-DD HH:mm TZ - short title
Commit/deploy:
- Local commit:
- Production revision:
- DB migration:
- WS restart:

Changed:
- ...

Verified:
- ...

Left for later:
- ...

Risks/notes:
- ...
```

## 15. Current Checkpoint

### 2026-05-14 - Full audit and Claude handoff document

Commit/deploy:

- Local commit: none in this step.
- Production revision: last known deployed from session log was `0a4dcd3` for sender session attribution, with later local head at `94a2265`; confirm production before assuming.
- DB migration: not applied in this step.
- WS restart: not done in this step.

Changed:

- Created this operating document.

Verified:

- Read `.codex/session-log.md`.
- Inspected repository structure, git status, recent commits, Composer dependencies, migration file, router, selected controllers, WebSocket server/connection manager and active email/mail files.

Left for later:

- Fix active dirty tree issues before deploy.
- Run syntax checks and live smoke tests after fixes.

Risks/notes:

- Dirty tree contains production-risky mail test endpoint and incomplete ban metadata migration.
- Do not deploy current dirty tree as-is.
