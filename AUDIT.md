# AUDIT.md — Системный аудит проекта Chat
Last updated: 2026-05-22 (rev2)
Previous audit: 2026-05-14 (audit/RISK_AUDIT.md, audit/PROJECT_MAP.md, audit/MESSAGE_FLOW.md)

---

## 1. Инвентаризация файлов

### Backend PHP (30 файлов, ~6 371 строка)

| Файл | Строк | Назначение |
|------|-------|-----------|
| src/Admin/Access.php | 163 | Права доступа, resolveLevel |
| src/Admin/AdminPanel.php | ~350 | Dashboard, создание пользователей, настройки |
| src/Admin/RoomManager.php | ~500 | Список/удаление/rename комнат, нумера, архив |
| src/Admin/UserManager.php | 637 | Пользователи, настройки, аватары, баны/мьюты |
| src/Auth/EmailVerification.php | ~130 | Верификация email, resend |
| src/Auth/GoogleOAuth.php | ~200 | Google OAuth flow |
| src/Auth/LoginHandler.php | ~70 | Email/username вход |
| src/Auth/RegisterHandler.php | 109 | Регистрация + email verification |
| src/Auth/VKOAuth.php | ~190 | VK OAuth (disabled at router level) |
| src/Chat/EmbedProcessor.php | 208 | Embed-превью ссылок |
| src/Chat/MessageController.php | ~320 | История, отправка, удаление сообщений |
| src/Chat/NumerController.php | ~280 | Invite/respond/leave/cleanup нумеров |
| src/Chat/NumerPage.php | ~600 | Standalone страница нумера |
| src/Chat/RoomController.php | 400 | Список/создание/join/manage комнат |
| src/Chat/SystemMessageService.php | 174 | Системные сообщения, visibility chain |
| src/Chat/WhisperController.php | ~550 | Whisper send/archive/delete/clear |
| src/DB/Connection.php | ~90 | PDO-обёртка singleton |
| src/Http/Router.php | ~200 | HTTP-маршрутизация |
| src/Mail/Mailer.php | ~45 | PHPMailer SMTP wrapper |
| src/Security/CSRF.php | ~45 | CSRF токен double-submit |
| src/Security/ColorContrast.php | ~70 | Проверка контрастности цветов |
| src/Security/HMAC.php | ~15 | HMAC подпись сообщений |
| src/Security/OriginGuard.php | ~55 | WS origin whitelist |
| src/Security/Session.php | ~110 | DB-backed сессии |
| src/Support/Lang.php | ~45 | Локализация |
| src/Support/Timestamp.php | ~100 | UTC ISO-8601 нормализация |
| src/Validation/UsernameRules.php | ~25 | Правила username |
| src/WebSocket/ConnectionManager.php | 207 | In-memory presence, рассылка |
| src/WebSocket/EventRouter.php | ~650 | WS-маршрутизация событий |
| src/WebSocket/Server.php | ~180 | WS-сервер, сессия, reconnect grace |

### Frontend JS (7 файлов, ~2 378 строк)

| Файл | Строк | Назначение |
|------|-------|-----------|
| public/assets/js/chat.js | 2024 | Главный: WS, комнаты, сообщения, UI |
| public/assets/js/chat-shell.js | 171 | Responsive shell, mobile rail |
| public/assets/js/chat-auth.js | 62 | Login/register AJAX, email verification UI |
| public/assets/js/chat-utils.js | 67 | Shared утилиты, systemAlert |
| public/assets/js/chat-display.js | 22 | User/role/status display helpers |
| public/assets/js/chat-input.js | 25 | Composer/input helpers |
| public/assets/js/chat-time.js | 7 | Frontend date/time formatting |

### Entry points

| Файл | Роль |
|------|------|
| public/index.php | Главный HTTP entry point (615 строк HTML+PHP) |
| ws-server.php | WebSocket process entry point (~31 строка) |
| index.php | Dev Docker diagnostic (47 строк, НЕ production) |

### Database

| Файл | Содержимое |
|------|-----------|
| src/DB/migrations.sql | Полная схема: 11 таблиц + ALTER + seed |
| database/migrations/001-004 | Базовые миграции |
| database/migrations/006-010 | Функциональные миграции |

---

## 2. Сравнение с аудитом 2026-04-18

### Что исправлено

| Проблема (аудит 2026-04-18) | Статус 2026-05-17 |
|---|---|
| #4 joinRoom() не определена | ЗАКРЫТА — вызовов joinRoom() в chat.js нет, проблемы не было |
| #5 room_count_changed без клиента | ЗАКРЫТА — onRoomCountChanged() есть в chat.js стр. 925 |
| #7 монолитный JS в index.php | ЧАСТИЧНО — JS вынесен в 7 отдельных модулей |
| UserManager без SSRF защиты | ЧАСТИЧНО — IPv4 private range filter добавлен |
| Системные сообщения дублировались | ЗАКРЫТА — SystemMessageService создан |

### Что было ошибочно помечено как проблема в аудите 2026-04-18

| Ошибочная проблема | Факт |
|---|---|
| friend_online/offline dead code | НЕ dead: client вызывает loadFriends() (HTTP refresh), это намеренное поведение |
| room_count_changed без обработчика | НЕ проблема: onRoomCountChanged() существует в chat.js стр. 925 |
| joinRoom() вызывается без определения | НЕ проблема: вызовов joinRoom() в chat.js нет — только joinPublicRoom() |

### Закрыто после аудита 2026-05-17

| Проблема | Статус | Коммит |
|---|---|---|
| Дубль deleteRoomWithDependencies | ✅ CLOSED | `00b2a53` |
| Дубль joinDefaultRooms (2 копии) | ✅ CLOSED | `00b2a53` |
| JsonResponse inline — Auth, Chat, AdminPanel | ✅ CLOSED | `d3ebda5`, `b1e1cfe`, `c037c5b` |
| JsonResponse inline — RoomManager | ✅ CLOSED | `26ceb9d` |
| JsonResponse inline — UserManager | ✅ CLOSED | `c002872` |
| JsonResponse inline — Router.php | ✅ CLOSED | `c5a05c9`, `fa3105e` |
| SHOW COLUMNS runtime — MessageController | ✅ CLOSED | `145edf6` |
| SHOW COLUMNS runtime — UserManager | ✅ CLOSED | `145edf6` |
| SHOW COLUMNS runtime — RoomController mute guard | ✅ CLOSED | `4be390b` |
| EmbedProcessor без SSRF защиты | ✅ CLOSED | `1d1eb91` |
| GET /api/rooms fan-out при WS событиях | ✅ CLOSED | `017482e`, `ae4c82b`, `18c3018` |
| Room role не обновляется в online-списке без refresh | ✅ CLOSED | `14a993b` |
| Нет system message при изменении room_role | ✅ CLOSED | `14a993b` |
| updateOnlineUser() разрозненный inline-код в chat.js | ✅ CLOSED | `afdab97` |
| Correlated COUNT(*) в RoomController::list() и numera() | ✅ CLOSED | `fba6ac5` |

### Что остаётся нерешённым

| Проблема | Приоритет | Статус |
|---|---|---|
| reactor_raw plaintext password | КРИТИЧНО | Ждёт решения владельца |
| Дубль resolvePermission/resolveLevel | НИЗКИЙ | OPEN |
| SHOW COLUMNS — roomCategoryOptions() | НИЗКИЙ | ⏸ DEFERRED BY DESIGN |
| Нет пагинации /api/rooms | СРЕДНИЙ | OPEN — LIMIT/OFFSET не добавлен (Phase 3.1-B) |
| Global role не обновляется в online-списке без refresh | НИЗКИЙ | OPEN — requires HTTP→WS bridge decision |
| index.php dev warning | НИЗКИЙ | SKIPPED — gitignored, три слоя защиты уже есть |

---

## 3. Проблемы безопасности

### SSRF-1: EmbedProcessor без IP-фильтрации — ✅ CLOSED `1d1eb91`

~~Уязвимость: отсутствие IP-фильтрации в headRequest() и fetchHtml()~~

Закрыто: добавлена scheme whitelist, DNS resolve + `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`, `follow_location: false`. Проверено локально: `127.0.0.1`, `localhost`, `169.254.169.254` — BLOCK; `github.com`, `youtube.com` — PASS.

### SSRF-2: UserManager частичная защита [СРЕДНИЙ]

Файл: `src/Admin/UserManager.php`, headRequest() стр. 476–514.

Покрыто: IPv4 RFC1918 (10.x, 172.16.x, 192.168.x) + reserved via FILTER_FLAG_NO_PRIV_RANGE.
Не покрыто: IPv6 loopback (::1), IPv6 ULA (fc00::/7), DNS rebinding, 0.0.0.0.

### reactor_raw: Plaintext password [КРИТИЧНО — бизнес-решение]

Файл: `src/Auth/RegisterHandler.php`, строка 43:
```
$db->execute(
    'INSERT INTO users (username, email, password_hash, reactor_raw) VALUES (?, ?, ?, ?)',
    [$username, $email, $hash, $password]  // $password — открытый текст
);
```

Статус: Зафиксировано в CLAUDE.md как "explicit product decision".
Требует решения владельца. Варианты описаны в DIFF_PLAN.md, шаг 1.2.

---

## 4. Архитектурные дубли

### Дубль A: deleteRoomWithDependencies — ✅ CLOSED `00b2a53`

~~Одинаковый транзакционный блок DELETE в двух местах~~

Создан `src/Chat/RoomDeletionService::deleteWithDependencies()`.
`RoomController` и `RoomManager` используют сервис. Inline транзакции удалены.

### Дубль Б: joinDefaultRooms — ✅ CLOSED `00b2a53`

~~Одинаковая SQL-логика в трёх местах~~

Создан `src/Chat/DefaultRoomMembership::joinDefaultRooms()`.
`RegisterHandler` и `GoogleOAuth` используют сервис. Private копии удалены.
(VKOAuth.php отключён на уровне роутера, дублей не содержал.)

### Дубль В: resolvePermission / resolveLevel

Параллельные реализации одной иерархии прав:

- `src/Admin/Access.php::resolveLevel()` — строки 141–161, возвращает int
- `src/Chat/RoomController.php::resolvePermission()` — строки 300–325, возвращает ?array

Уровни совпадают: owner=3, local_admin=2, local_moderator=1, member=0 (global: platform_owner=6, admin=5, moderator=4).
Синхронизируются вручную.

### Дубль Г: GLOBAL_ROLE_LEVELS

- `src/Admin/UserManager.php` — const GLOBAL_ROLE_LEVELS = [...] (стр. 14)
- `src/Admin/Access.php` — hardcoded числа в resolveLevel()
- `src/Chat/RoomController.php` — hardcoded числа в resolvePermission()

---

## 5. WS события — верифицированная карта (2026-05-17)

| Событие | Сервер | Клиент | Статус |
|---------|--------|--------|--------|
| connected | Server.php onOpen | chat.js | OK |
| room_joined | EventRouter | chat.js | OK |
| user_joined | EventRouter | chat.js | OK |
| user_left | EventRouter + Server onClose | chat.js | OK |
| new_message | EventRouter | chat.js | OK |
| system_message | SystemMessageService | chat.js | OK |
| message_deleted | EventRouter | chat.js | OK |
| whisper_sent | EventRouter | chat.js | OK |
| whisper_received | EventRouter | chat.js | OK |
| invite_sent/received/accepted/declined/expired | EventRouter | chat.js | OK |
| numer_joined | EventRouter | chat.js | OK |
| numer_participant_joined/left | EventRouter | chat.js | OK |
| numer_closed | EventRouter | chat.js | OK |
| kicked_from_room | EventRouter | chat.js | OK |
| banned_from_room | EventRouter | chat.js (uncommitted) | OK pending commit |
| muted_in_room | EventRouter | chat.js | OK |
| room_deleted | EventRouter | chat.js | OK |
| room_updated | EventRouter | chat.js | OK |
| room_count_changed | EventRouter::broadcastRoomCount | chat.js::onRoomCountChanged | OK |
| online_users | EventRouter | chat.js | OK |
| pong | EventRouter | chat.js | OK |
| error | EventRouter/Server | chat.js | OK |
| friend_online | НЕ отправляется | chat.js -> loadFriends() | Намеренный HTTP-refresh |
| friend_offline | НЕ отправляется | chat.js -> loadFriends() | Намеренный HTTP-refresh |

Вывод: Dead WS события отсутствуют. friend_online/offline — намеренный паттерн HTTP-refresh.

---

## 6. Незакоммиченные изменения (рабочее дерево на 2026-05-17)

5 файлов modified. Тема: kick/ban flow improvements + унификация admin bans API.

Все изменения связаны, должны быть в одном коммите. Подробные diff в DIFF_PLAN.md шаг 0.1.

Краткое содержание:
- chat.js: onBannedFromRoom() выделен из onKickedFromRoom(), loadRooms(skipAutoJoin)
- UserManager.php: listBanned() → единый массив bans вместо room+mutes отдельно
- RoomController.php: kick/ban JOIN users, возвращают target_username
- SystemMessageService.php: добавлены scopes room_kick, room_ban
- EventRouter.php: emitRoomLifecycle() при kick и ban

---

## 7. Технический долг

| Проблема | Файл | Приоритет | Статус |
|----------|------|-----------|--------|
| In-memory presence, нет multi-instance WS | ConnectionManager.php | Низкий | Acceptable для одного инстанса |
| Нет пагинации /api/rooms | RoomController.php | Средний | OPEN |
| SHOW COLUMNS runtime — UserManager, MessageController, RoomController mute | — | — | ✅ CLOSED `145edf6`, `4be390b` |
| SHOW COLUMNS runtime — RoomManager::roomCategoryOptions() | RoomManager.php | Низкий | ⏸ DEFERRED BY DESIGN |
| Монолитный chat.js (~2000 строк) | chat.js | Низкий | OPEN — продолжать разбивку |
| Friendships flow частичный | Router.php, chat.js | Низкий | HTTP-refresh acceptable |
| Нет unit-тестов | — | Средний | OPEN |
| composer.json PHP ^8.4 vs prod 8.2? | composer.json | Средний | Верифицировать на production |
| WS supervisord вместо systemd | supervisord.docker.conf | Низкий | Production: добавить systemd unit |
| Глобальный бан не отключает WS | Server.php | Средний | Временный fix: route guard в EventRouter |
| Сессионные данные не обновляются без reconnect | Server.php | Средний | Известное ограничение |
| Global role не обновляется в online-списке без refresh | chat.js + WS | Низкий | OPEN — см. §9 |
| Room role realtime update + system messages | — | — | ✅ CLOSED `14a993b` |
| GET /api/rooms fan-out при WS событиях | chat.js | — | ✅ CLOSED `017482e`, `ae4c82b`, `18c3018` |
| SSRF EmbedProcessor | EmbedProcessor.php | — | ✅ CLOSED `1d1eb91` |
| updateOnlineUser() inline-дубли в chat.js | chat.js | — | ✅ CLOSED `afdab97` |
| Correlated COUNT(*) в list() и numera() | RoomController.php | — | ✅ CLOSED `fba6ac5` |

---

## 9. Global role realtime update — анализ ограничений

### Контекст

`room_role` realtime update закрыт в `14a993b`: изменение происходит через WS `room_action` → `EventRouter::onRoomAction()` → `sendToRoom('room_updated', ...)` → JS `updateOnlineUser()`.

`global_role` realtime update для всех онлайн-клиентов **невозможен существующими механизмами**.

### Причины (доказаны по коду)

1. **HTTP/WS split**: `global_role` меняется через `POST /api/admin/users/{id}` → `UserManager::update()` в PHP-FPM процессе.
2. **ConnectionManager недоступен из HTTP**: `ConnectionManager` живёт исключительно в ReactPHP (WS) процессе. Ни один HTTP-файл (`Admin/`, `Http/`, `Security/`) не импортирует и не использует `ConnectionManager` напрямую. Нет IPC: ни APCu, ни shmop, ни FIFO, ни socket.
3. **`force_logout` — не bridge**: существующий механизм глобального бана работает как **lazy DB-poll** — `EventRouter::route()` вызывает `Session::isUserBlocked()` при каждом WS-сообщении от target. Это не realtime push, а обнаружение при следующем событии от target. Закомментировано самим кодом: *"Temporary synchronization point... Full immediate moderation cleanup would require explicit IPC"* (EventRouter.php L38–40).
4. **`sendToAll` не вызывается из HTTP**: единственный `sendToAll` в проекте — `broadcastRoomCount()` внутри EventRouter.

### Что возможно без новой инфраструктуры

| Решение | Охват | Требует |
|---|---|---|
| JS `updateOnlineUser` в `resp.success` callback | Только actor | 1 строка JS |
| Lazy update через DB-poll в `route()` | Target при следующем WS-сообщении | Новая DB-проверка в EventRouter |
| Broadcast всем в комнате target | Все в комнате target | Новый WS admin action endpoint |
| Broadcast всем онлайн | Все клиенты | Требует HTTP→WS IPC |

### Статус

**OPEN — requires explicit HTTP→WS bridge / IPC / WS admin action decision.**

Реализация Phase 1 (только actor) возможна немедленно: `updateOnlineUser(Number(id), {global_role: next})` в JS callback после `resp.success`. Остальные обновятся при следующем `join_room` (актуальный `getOnlineList()` из DB).

---

## 8. Статус миграций

| Файл | Локально | Production |
|------|----------|------------|
| 001_fix_foreign_keys.sql | Applied | Not verified |
| 002_email_verification.sql | Applied | Not verified |
| 003_reactor_raw.sql | Applied | Not verified |
| 004_ban_metadata.sql | Applied | Not verified |
| 006_username_max_25.sql | Applied | Not verified |
| 007_messages_deleted_at.sql | Applied | Not verified |
| 008_rooms_close_reason.sql | Applied | Not verified |
| 009_system_messages.sql | Applied | Not verified |
| 010_show_system_messages.sql | Applied | Not verified |

Примечание: 005_*.sql отсутствует — пропуск номера, нормально.
