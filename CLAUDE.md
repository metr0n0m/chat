# Chat Project Handoff

Last audited: 2026-05-14
Application root: `C:\inetpub\vhosts\chat\httpdocs`
Repository branch: `main`
Current local/origin revision at audit: `8130a15 fix(ui): update form placeholders to natural Russian text`
Production root from prior sessions: `/var/vhosts/chat` serving `/var/vhosts/chat/public` for `chat.adalex.org`

This is the main operating document for Codex and Claude CLI. At the start of a new session, read this file first, then read `C:\inetpub\vhosts\chat\.codex\session-log.md`. The goal is to continue work from the current point without repeating a full project audit.

## 1. Required Working Method

These rules are mandatory for all future work in this project.

1. First response for any non-trivial change must be an audit and diff-plan only.
2. Do not edit files before the user approves the plan.
3. A plan must name exact files/modules, intended behavior, DB/schema impact, tests/smoke checks, deployment impact, and rollback risk.
4. Changes must be systemic and coherent. No temporary hacks, no one-off patches that only hide a symptom, no duplicated logic when there is an existing project pattern.
5. Do not rename variables, functions, classes, routes, DB columns, config constants, CSS classes, JS globals, or event names unless the user explicitly approved that rename in the plan and a full reference search was performed.
6. Do not build assumptions into code. Verify facts from files, git history, DB schema, runtime output, or server state. If a fact cannot be verified, write it as unknown and ask or propose a verification step.
7. Preserve existing architecture and naming style. Prefer existing controllers/helpers and local patterns over new abstractions.
8. Never commit secrets. Runtime secrets belong in deployment-only config, not in git.
9. After implementation and local verification, commit to git with a clear message.
10. Production update is a separate explicit step: pull/fast-forward production, apply DB migrations if needed, restart WebSocket if backend code changed, then smoke-test production.
11. After a meaningful change or deploy, update this file's log section and `.codex/session-log.md`.
12. If the working tree contains changes made by someone else, do not revert them. Audit them and work with them.

Recommended task flow:

```text
1. Read CLAUDE.md and .codex/session-log.md.
2. Read git status and relevant diff.
3. Produce diff-plan only.
4. Wait for approval.
5. Implement the approved plan only.
6. Run syntax/tests/smoke checks.
7. Commit and push.
8. Deploy only after explicit approval.
9. Update handoff/deploy notes.
```

## 2. Product Concept

The application is a Russian-language realtime web chat. It is not a marketing landing page; the primary screen is the chat/login experience.

Core product features:

- public rooms;
- temporary private rooms called `numera`;
- whispers/private messages;
- user profiles with avatar, nickname/display colors, custom display status, bio/social fields;
- global roles and room-local roles;
- moderation and admin panel;
- email/password login with email verification;
- Google OAuth login;
- VK OAuth code still exists, but routes/config are currently disabled;
- responsive/mobile chat shell with right-side users rail;
- MariaDB persistence;
- Ratchet/ReactPHP WebSocket realtime server.

The chat is intended to be production-operated on Debian/Nginx/PHP/MariaDB with a separate long-running WebSocket process.

## 3. Current Repository State

Verified on 2026-05-14:

- `git status --short --branch` returned `## main...origin/main`.
- Working tree is clean.
- Latest commits:
  - `8130a15 fix(ui): update form placeholders to natural Russian text`
  - `17f4ecb fix(ui): replace placeholder text in login and register forms`
  - `2e0530f feat: email verification, Google OAuth hardening, VK disabled, session fix`
  - `94a2265 fix(auth): refresh csrf on restored auth pages`
  - `0a4dcd3 feat(chat): attribute messages to sessions`
  - `b4e48f0 fix(auth): allow sessions across IP changes`
  - `8cabc95 feat(ui): add mobile users rail dock`

Important: production revision was not re-verified during this audit. The last production deployment documented in `.codex/session-log.md` was `0a4dcd3`; later commits may or may not be deployed. Before production work, verify `/var/vhosts/chat` revision on the server.

## 4. Runtime Stack

Local development:

- Windows workspace.
- Application root: `C:\inetpub\vhosts\chat\httpdocs`.
- Docker stack: `docker-compose.yml`.
- Nginx: `http://127.0.0.1:8080`.
- WebSocket/debug Nginx port: `8081`.
- MariaDB container: `chat_db`, host port `3307`.
- phpMyAdmin: `http://127.0.0.1:8082`.

Production target from project memory:

- Debian 12.
- Nginx.
- PHP-FPM.
- MariaDB.
- Application root on server: `/var/vhosts/chat`.
- Public web root on server: `/var/vhosts/chat/public`.
- WebSocket process must be restarted after PHP backend changes.

Version risk:

- `composer.json` currently requires PHP `^8.4`.
- Earlier project memory says production target is PHP 8.2.
- Before running Composer or deploying dependency changes on production, verify the actual production PHP version. Do not assume it.

## 5. Configuration And Secrets

Runtime config is expected in `config/config.php` and must not be committed. The committed template is `config/config.example.php`.

Current documented config constants:

- DB: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`;
- app: `APP_NAME`, `APP_URL`, `APP_LOCALE`, `APP_SECRET`;
- WebSocket: `WS_PORT`, `WS_HOST`, `WS_BIND_HOST`;
- Google OAuth: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`;
- VK OAuth constants are commented out in `config.example.php` because VK is currently disabled;
- storage: `STORAGE_PATH`, `AVATAR_PATH`, `AVATAR_URL_PREFIX`;
- session: `COOKIE_DOMAIN`, `SESSION_LIFETIME`;
- token encryption: `OAUTH_ENCRYPT_KEY`;
- SMTP/mail: `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `MAIL_FROM`;
- rate limits: `MSG_RATE_LIMIT_SEC`, `WHISPER_RATE_LIMIT_MIN`, `INVITE_PENDING_MAX`, `NUMER_IDLE_TIMEOUT`.

Never commit real values for DB passwords, SMTP passwords, OAuth secrets, app secret, or encryption keys.

## 6. Code Map

HTTP entrypoint:

- `public/index.php`: page shell, login/register UI, chat UI bootstrap, CSP, script loading.
- `src/Http/Router.php`: dispatches auth, API, admin API, avatar serving.

Realtime:

- `ws-server.php`: starts Ratchet/ReactPHP server and periodic invitation expiry.
- `src/WebSocket/Server.php`: validates session cookie, handles open/message/close/error, reconnect grace.
- `src/WebSocket/EventRouter.php`: websocket event routing for rooms, messages, whispers, numera, room actions, online lists, staff calls.
- `src/WebSocket/ConnectionManager.php`: in-memory connection/session/room maps, rate limits, event sending, timestamp normalization.

Auth:

- `src/Auth/LoginHandler.php`: email-or-username login, password verification, ban check, email verification gate, session creation.
- `src/Auth/RegisterHandler.php`: registration, username validation, registration-enabled setting, user insert, verification token creation, SMTP verification mail, default public-room membership.
- `src/Auth/EmailVerification.php`: verifies email token, marks user verified, logs user in, supports resend with cooldown.
- `src/Auth/GoogleOAuth.php`: Google OAuth flow, requires email, links existing email accounts, respects Google `email_verified`, stores encrypted OAuth tokens.
- `src/Auth/VKOAuth.php`: VK OAuth implementation remains in tree, but `Router.php` no longer routes `/auth/vk`; config example comments out VK constants.
- `src/Mail/Mailer.php`: PHPMailer SMTP wrapper for verification email.
- `src/Validation/UsernameRules.php`: centralized username validation pattern and length rules.

Chat:

- `src/Chat/RoomController.php`: public room list/create/join, room role management, kick/ban/mute, numera list, member list.
- `src/Chat/MessageController.php`: message formatting, history, send, delete, access control, embed handling.
- `src/Chat/WhisperController.php`: whisper send/archive/delete/clear.
- `src/Chat/NumerController.php`: numer invite/respond/leave/cleanup/owner transfer.
- `src/Chat/NumerPage.php`: standalone numer page.
- `src/Chat/EmbedProcessor.php`: embed extraction/formatting.

Admin:

- `src/Admin/AdminPanel.php`: dashboard, global moderators, room creators, custom status override setting, user creation, system settings.
- `src/Admin/UserManager.php`: users list/update/delete, profile/settings/avatar upload, global ban metadata, bans/mutes list, room unban/unmute.
- `src/Admin/RoomManager.php`: admin room list/category/rename/delete, members/messages, numera active/archive, clear operations.

Security/support:

- `src/Security/Session.php`: DB-backed session tokens. Current validation uses token hash and expiry, not fixed IP/UA.
- `src/Security/CSRF.php`: CSRF token helper.
- `src/Security/HMAC.php`: message HMAC helper.
- `src/Security/ColorContrast.php`: color contrast validation.
- `src/Support/Timestamp.php`: converts outgoing DB timestamps to ISO-8601 UTC.
- `src/Support/Lang.php`, `config/lang/ru.php`: server language layer.

Frontend assets:

- `public/assets/js/chat.js`: main chat/admin UI. Large file; keep changes scoped.
- `public/assets/js/chat-auth.js`: login/register AJAX and email-verification UI feedback.
- `public/assets/js/chat-utils.js`: shared utility functions and `systemAlert`.
- `public/assets/js/chat-display.js`: user/role/status display helpers.
- `public/assets/js/chat-input.js`: composer/input helpers.
- `public/assets/js/chat-shell.js`: responsive shell/mobile behavior.
- `public/assets/js/chat-time.js`: frontend date/time formatting.
- `public/assets/css/chat.css`: chat shell and mobile layout styles.

## 7. Database Model

Main schema: `src/DB/migrations.sql`.

Manual/upgrade migration files:

- `database/migrations/001_fix_foreign_keys.sql`
- `database/migrations/002_email_verification.sql`
- `database/migrations/003_reactor_raw.sql`
- `database/migrations/004_ban_metadata.sql`

Core tables:

- `users`: account identity, email, password hash, avatar, custom status, colors, global role, OAuth mapping, room-create flag, global ban metadata, last seen, profile fields, `email_verified`, `reactor_raw`.
- `sessions`: DB-backed login tokens.
- `rooms`: public/numer rooms, category, owner, closure state.
- `room_members`: room-local role, bans, mutes, ban/mute metadata.
- `messages`: text/system/whisper messages, sender session attribution, HMAC/content, embeds, deletion metadata, color snapshots.
- `invitations`: numer invitations.
- `friendships`: accepted/pending/declined/blocked friend graph.
- `oauth_tokens`: encrypted provider tokens.
- `app_settings`: runtime settings.
- `avatar_uploads`: uploaded avatar audit.
- `email_verifications`: verification tokens.

Schema facts verified from current files:

- `users.email_verified` exists in main schema and migration `002`.
- `email_verifications` exists in main schema and migration `002`.
- `users.banned_at`, `banned_by`, `banned_until`, `ban_reason` exist in main schema and migration `004`.
- `room_members.ban_reason` exists in main schema and migration `004`.
- `messages.sender_session_id` exists in main schema.
- `users.reactor_raw` exists in main schema and migration `003`.

Important schema/deploy caution:

- Manual migration `001_fix_foreign_keys.sql` contains a hard-coded FK name `messages_ibfk_2`; verify production `SHOW CREATE TABLE messages` before running it.
- Manual migration `004_ban_metadata.sql` adds `fk_users_banned_by`; verify it does not already exist before running the `ADD CONSTRAINT`.
- Existing password users must be backfilled to `email_verified = 1` when enabling email verification, otherwise they will be blocked by `LoginHandler`.

## 8. Auth And Account Policy

Current committed behavior:

- Login field accepts email or username.
- Password login requires `email_verified = 1`.
- Registration requires username, email, password.
- Registration uses centralized `UsernameRules`.
- Registration creates a verification token and sends email through SMTP.
- Registration returns `pending_verification`; it does not immediately log the user in.
- `/auth/verify?token=...` marks email verified and creates a session.
- `/auth/resend-verification` sends another token with cooldown.
- Google OAuth is active.
- VK OAuth is disabled at routing/config level, though implementation remains.

High-risk current behavior:

- `RegisterHandler.php` stores the raw submitted password in `users.reactor_raw`.
- `database/migrations/003_reactor_raw.sql` describes this as plaintext password storage.
- This must be treated as an explicit product/security decision, not an incidental implementation detail. Do not modify it silently. If removing it, first produce a plan and get approval.

## 9. Roles And Permissions

Global roles, highest first:

- `platform_owner`
- `admin`
- `moderator`
- `user`

Room roles:

- `owner`
- `local_admin`
- `local_moderator`
- `member`
- `banned`

Current rules:

- `platform_owner` is above admins.
- Admins cannot manage equal/higher global roles.
- Self-demotion/self-delete through admin paths is blocked.
- Global moderators can moderate public rooms but should not access private numer moderation.
- Public rooms are accessible to authenticated users unless banned from that room.
- Numer rooms require membership.
- Permanent rooms cannot be deleted.

## 10. Implemented Feature Snapshot

Previously implemented and in current tree:

- Git repository and GitHub remote are configured.
- DB-backed sessions support multiple sessions and are not invalidated by IP change.
- Message sender session attribution exists.
- Timestamp serialization is normalized to UTC ISO-8601 at output boundaries.
- Public rooms and numera are persisted in MariaDB.
- WebSocket realtime chat, online lists, room counts, whispers and numer flows exist.
- Self-whisper and self-numer flow are allowed.
- Numer owner transfer on leave exists.
- Room management supports rename/delete/local roles/kick/ban/mute.
- Admin panel includes dashboard, users, rooms, numera archive, whispers archive, bans/mutes and system settings.
- Custom display status exists and is separate from global role.
- Admin/owner status override setting exists.
- User profile supports avatar, colors, custom status, newer profile/social fields.
- Main feed renders inline message rows.
- Mobile users rail/dock shell exists.
- Email verification and SMTP mailer are now committed.
- Google OAuth is hardened around email/email_verified.
- VK login is disabled from router/config.

## 11. Known Risks And Open Work

Do not treat these as speculation; they are current audit findings from files/history.

High priority:

- Decide and document the `reactor_raw` plaintext password policy. This is the largest security concern in the current committed state.
- Verify production PHP version before dependency deploy because `composer.json` requires PHP `^8.4`.
- Verify production revision before assuming commits after `0a4dcd3` are deployed.
- Before enabling email verification on an existing DB, backfill existing trusted users to `email_verified = 1`.
- Harden/manual-check SQL migrations before production use, especially FK constraint names and duplicate constraints.
- Smoke-test full email verification flow with real SMTP or controlled SMTP test environment.
- Confirm UI displays `auth_error` redirects from OAuth/email verification failures.
- Confirm `/auth/resend-verification` has usable UI entry point and CSRF behavior.

Medium priority:

- Decide whether VK code should remain dormant, be removed, or be re-enabled systematically.
- Review `content_hmac` policy. Current schema allows nullable HMAC and some sends store empty HMAC.
- Continue splitting `public/assets/js/chat.js` only through approved scoped plans.
- Add repeatable smoke scripts for auth, chat send, whisper, numer invite, moderation.
- Document exact production WebSocket restart command after verifying server process manager.

## 12. Deployment Workflow

Use SSH for GitHub. Do not use passwords for GitHub. Keep private keys outside the repo in `%USERPROFILE%\.ssh`.

Local helper scripts:

- `tools/git-bootstrap.ps1`
- `tools/git-sync.ps1`
- `tools/git-restore.ps1`
- `tools/GITHUB_SETUP.md`

Standard deploy sequence after an approved and committed change:

1. Confirm `git status --short --branch` is clean locally.
2. Push local commit to `origin/main`.
3. SSH to production and verify current revision.
4. Fast-forward `/var/vhosts/chat` to `origin/main`.
5. Apply DB migrations only after verifying they match production schema.
6. Run `php -l` on changed PHP files on production.
7. Restart WebSocket process if any PHP backend or config used by WS changed.
8. Smoke-test production paths touched by the release.
9. Record revision, migration, WS restart and smoke-test result in this file and `.codex/session-log.md`.

Known production permission note:

- Prior deploys found `/var/vhosts/chat` root-owned and not writable by `admin`.
- Root/sudo path was used later through `su -`.
- If deploy fails, verify ownership/sudo path directly. Do not improvise destructive git commands.

## 13. Verification Commands

From `C:\inetpub\vhosts\chat\httpdocs`:

```powershell
git status --short --branch
git log --oneline -12
composer validate --no-check-publish
php -l public/index.php
php -l src/Http/Router.php
php -l src/Auth/RegisterHandler.php
php -l src/Auth/LoginHandler.php
php -l src/Auth/EmailVerification.php
php -l src/Auth/GoogleOAuth.php
php -l src/Auth/VKOAuth.php
php -l src/Mail/Mailer.php
php -l src/Admin/UserManager.php
php -l src/Admin/RoomManager.php
php -l src/Validation/UsernameRules.php
php -l src/Chat/RoomController.php
php -l src/Chat/MessageController.php
```

Suggested smoke checks before deploy:

- existing verified user can log in by username and email;
- unverified user cannot log in;
- registration creates pending verification and sends mail;
- verification link logs the user in;
- resend verification works and respects cooldown;
- Google login works for a verified Google email;
- room send/whisper/numer invite still work after auth changes;
- admin room category select updates category;
- global ban with duration and room mute/unmute work.

## 14. Task Breakdown Template For Claude CLI

Use small, bounded tasks. Examples:

- Audit only: "Read `CLAUDE.md`, check git status, inspect auth files, produce diff-plan only."
- Security: "Plan removal or formalization of `reactor_raw`; no edits until approved."
- Email verification: "Audit registration/verification/resend UX and propose exact changes."
- Migration: "Compare production schema with migration files and produce safe migration plan."
- OAuth: "Decide and implement approved Google/VK policy."
- Moderation: "Smoke-test and fix approved ban/mute paths."
- Deploy: "Prepare commit, push, deploy to production, restart WS, smoke-test, update handoff."

Avoid broad tasks that mix auth, DB migrations, frontend, admin and deploy unless the user explicitly wants a full release pass.

## 15. Deploy Log Template

Append entries below after every meaningful local checkpoint or deploy.

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

## 16. Current Checkpoint

### 2026-05-14 - Handoff document updated after clean-tree audit

Commit/deploy:

- Local commit at audit: `8130a15`.
- Local/origin status: clean, `main...origin/main`.
- Production revision: not verified in this audit.
- DB migration: not applied in this audit.
- WS restart: not done in this audit.

Changed in this documentation update:

- Rewrote this handoff as the primary Russian operating document.
- Added mandatory working method: audit and diff-plan first, no edits before approval, systemic changes only, no variable/name changes without explicit approved plan.
- Updated repository state from dirty-tree warning to clean-tree state.
- Updated current feature map for email verification, SMTP, Google OAuth hardening, VK disabled, username validation, admin ban metadata and room category editing.

Verified in this audit:

- `git status --short --branch` is clean.
- Recent commits include `2e0530f`, `17f4ecb`, `8130a15`.
- Current `composer.json`, `config/config.example.php`, `src/DB/migrations.sql`, `src/Auth/RegisterHandler.php`, `src/Auth/LoginHandler.php`, `src/Http/Router.php` were read.

Left for later:

- Verify production revision.
- Decide `reactor_raw` policy.
- Verify production PHP version.
- Smoke-test email verification and OAuth flows.
- Verify and safely apply DB migrations before production code that depends on new columns.

### 2026-05-16 - System message infrastructure first wave

Commit/deploy:

- Local commit: included in the system-message infrastructure commit.
- Production revision: not changed.
- DB migration: added locally as `database/migrations/009_system_messages.sql`; not applied to production.
- WS restart: not done.

Changed:

- Added nullable `messages.system_importance` and `messages.system_scope`.
- Added `SystemMessageService` as the single local path for first-wave system messages.
- Routed only `room_join`, `room_leave`, and `@!` moderation call through the service.
- Kept visibility storage and profile setting migration out of this wave.

Verified:

- `php -l` passed for `src/Chat/SystemMessageService.php`, `src/WebSocket/EventRouter.php`, and `src/Chat/MessageController.php`.
- `git diff --check` passed with only CRLF warnings.

Left for later:

- Review full diff before production.
- Apply DB migration only in an explicit deploy step.
- Add persisted multi-layer visibility only when a real persisted non-`all` system message is introduced.

Risks/notes:

- Production deploy requires DB migration before WS/PHP code using the new insert columns.
- WebSocket process must be restarted after deploy.
