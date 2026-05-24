# DIFF_PLAN.md — Поэтапный план реализации
Last updated: 2026-05-22 (rev2)

Принципы: малые шаги, каждый обратим, без изменений UI/UX и текущей логики,
только системные решения, diff-план для каждого изменения.

Статусы: [ ] — не начато, [~] — в работе, [x] — выполнено

---

## ФАЗА 0: Стабилизация (30 минут)

### Шаг 0.1: Коммит незакоммиченных изменений [x] CLOSED

**Что:** Привести рабочее дерево в чистое состояние.

**Файлы:**
- public/assets/js/chat.js
- src/Admin/UserManager.php
- src/Chat/RoomController.php
- src/Chat/SystemMessageService.php
- src/WebSocket/EventRouter.php

**Верификация перед коммитом:**
```
php -l src/Admin/UserManager.php
php -l src/Chat/RoomController.php
php -l src/Chat/SystemMessageService.php
php -l src/WebSocket/EventRouter.php
```

**Команды:**
```
git add public/assets/js/chat.js \
        src/Admin/UserManager.php \
        src/Chat/RoomController.php \
        src/Chat/SystemMessageService.php \
        src/WebSocket/EventRouter.php

git commit -m "fix(admin): unify bans API and improve kick/ban UX

- Split onBannedFromRoom() from onKickedFromRoom() handler
- Add loadRooms(skipAutoJoin) flag to prevent auto-switch after kick/ban
- Unify listBanned() response: room bans and mutes merged into 'bans' array
- Add target_username to kick/ban results via JOIN users
- Emit system_message on kick (room_kick) and ban (room_ban) scopes"

git push origin main
```

**Риски:** Нет. Изменения уже проверены синтаксически.
**Rollback:** `git reset --soft HEAD~1`

**После:** Обновить CLAUDE.md раздел 16 (checkpoint).

**СТАТУС:** CLOSED. Коммит: `fbfc0f7` (fix(admin): unify bans API), `e0877c7` (cleanup: remove dead JS helpers).

---

## ФАЗА 1: Безопасность (4–6 часов)

### Шаг 1.1: SSRF-защита в EmbedProcessor [ ] OPEN

**Что:** Добавить IP-фильтрацию в EmbedProcessor, аналогичную UserManager::headRequest().

**Файл:** `src/Chat/EmbedProcessor.php`

**Diff — headRequest() (строки 170–190):**
```diff
 private static function headRequest(string $url): ?array
 {
+    $parsed = parse_url($url);
+    if (!$parsed || !in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
+        return null;
+    }
+    $host = strtolower((string) ($parsed['host'] ?? ''));
+    if ($host === '') {
+        return null;
+    }
+    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : (gethostbyname($host) ?: '');
+    if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP,
+        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
+        return null;
+    }
     $ctx = stream_context_create(['http' => [
         'method'          => 'HEAD',
         'timeout'         => self::TIMEOUT,
```

**Diff — fetchHtml() (строки 192–206):**
```diff
 private static function fetchHtml(string $url): ?string
 {
+    $parsed = parse_url($url);
+    if (!$parsed || !in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
+        return null;
+    }
+    $host = strtolower((string) ($parsed['host'] ?? ''));
+    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : (gethostbyname($host) ?: '');
+    if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP,
+        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
+        return null;
+    }
     $ctx = stream_context_create(['http' => [
         'method'          => 'GET',
```

**Верификация:**
```
php -l src/Chat/EmbedProcessor.php
```

**Команды:**
```
git add src/Chat/EmbedProcessor.php
git commit -m "security(embed): add SSRF IP filter to EmbedProcessor

- Validate URL scheme: http/https only
- Block private/reserved IPv4 ranges via FILTER_FLAG_NO_PRIV_RANGE
- Consistent with existing UserManager::headRequest() protection"

git push origin main
```

**Риски:** Минимальные. Легитимные публичные URL не затронуты.
**Rollback:** `git revert HEAD`
**Deploy:** Требует рестарта WS-процесса (EmbedProcessor вызывается из MessageController, который вызывается из EventRouter).

---

### Шаг 1.2: Решение reactor_raw [ ]

**Что:** Определить и зафиксировать политику plaintext password storage.

**ТРЕБУЕТ РЕШЕНИЯ ВЛАДЕЛЬЦА ПРОЕКТА перед implementation.**

**Варианты:**

**Вариант A — Удаление (рекомендуется):**

Новый файл миграции `database/migrations/011_remove_reactor_raw.sql`:
```sql
-- Удалить колонку reactor_raw из таблицы users
-- Убедиться что нет зависимостей в коде перед применением
ALTER TABLE users DROP COLUMN IF EXISTS reactor_raw;
```

Diff `src/Auth/RegisterHandler.php`:
```diff
         $db->execute(
-            'INSERT INTO users (username, email, password_hash, reactor_raw) VALUES (?, ?, ?, ?)',
-            [$username, $email, $hash, $password]
+            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
+            [$username, $email, $hash]
         );
```

**Вариант B — Шифрование:**

Diff `src/Auth/RegisterHandler.php`:
```diff
+        $key = base64_decode(OAUTH_ENCRYPT_KEY);
+        $iv  = random_bytes(16);
+        $encryptedRaw = base64_encode(
+            $iv . openssl_encrypt($password, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv)
+        );
         $db->execute(
             'INSERT INTO users (username, email, password_hash, reactor_raw) VALUES (?, ?, ?, ?)',
-            [$username, $email, $hash, $password]
+            [$username, $email, $hash, $encryptedRaw]
         );
```

**Вариант C — Документирование как approved policy:**

Обновить CLAUDE.md раздел 11:
```diff
 **Policy decision (won't change):**

-- `reactor_raw` stores the raw submitted password in plaintext. This is an explicit product decision. Do not modify without owner approval.
+- `reactor_raw` stores the raw submitted password: [APPROVED_REASON]. Decision date: YYYY-MM-DD. Owner: [NAME]. GDPR basis: [BASIS]. Annual review: required.
```

**Действие:** Ждать решения, не реализовывать без одобрения.

---

### Шаг 1.3: Переименование index.php [~] SKIPPED

**Что:** Устранить путаницу между dev-файлом и production entry point.

**Файл:** `index.php` (корневой)

**Diff — добавить предупреждение в начало файла:**
```diff
 <?php
+/**
+ * LOCAL DOCKER DEVELOPMENT DIAGNOSTIC PAGE ONLY.
+ * NOT a production entry point. Do NOT deploy as web root.
+ * Production entry point: public/index.php
+ * Last updated: 2026-04-17.
+ */
 declare(strict_types=1);
```

**Дополнительно** — переименовать (опционально, если nginx не смотрит на корень):
```
git mv index.php docker-diagnostic.php
```

**Верификация:**
```
php -l index.php
```

**Команды (только комментарий, без переименования):**
```
git add index.php
git commit -m "docs(dev): add explicit dev-only warning to root index.php

- Clarify this is not the production entry point
- Production entry point is public/index.php"

git push origin main
```

**Риски:** Нет. Docker nginx смотрит на /var/www/chat/public, не на корень.
**Rollback:** `git revert HEAD`

---

## ФАЗА 2: Устранение дублей (4–6 часов)

### Шаг 2.1: RoomDeletionService [x] CLOSED

**Коммит:** `00b2a53` refactor(chat): extract room membership and deletion services

**Закрыто:**
- Создан `src/Chat/RoomDeletionService.php` — `deleteWithDependencies(Connection, int): void`
- Удалён `RoomController::deleteRoomWithDependencies()` (inline транзакция)
- Удалена inline транзакция в `RoomManager::delete()`
- Оба caller сохраняют свою стратегию ошибок (throw / JsonResponse::error)

**Примечание:** Фактически создан `RoomDeletionService`, не `RoomDeletionService` с `bool` return —
интерфейс скорректирован по решению пользователя (throw semantics, no existence check).

**Что:** Вынести дублирующийся транзакционный блок удаления комнаты в общий сервис.

**Новый файл:** `src/Chat/RoomDeletionService.php`
```php
<?php
declare(strict_types=1);

namespace Chat\Chat;

use Chat\DB\Connection;

class RoomDeletionService
{
    /**
     * Удалить комнату со всеми зависимостями в транзакции.
     * Вызывается из RoomController и RoomManager.
     *
     * @return bool false если комната не найдена
     * @throws \Throwable при ошибке транзакции
     */
    public static function deleteWithDependencies(Connection $db, int $roomId): bool
    {
        if (!$db->fetchOne('SELECT id FROM rooms WHERE id = ?', [$roomId])) {
            return false;
        }
        $db->beginTransaction();
        try {
            $db->execute('DELETE FROM messages     WHERE room_id = ?', [$roomId]);
            $db->execute('DELETE FROM room_members WHERE room_id = ?', [$roomId]);
            $db->execute('DELETE FROM invitations  WHERE room_id = ?', [$roomId]);
            $db->execute('DELETE FROM rooms        WHERE id      = ?', [$roomId]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return true;
    }
}
```

**Diff `src/Chat/RoomController.php`:**
```diff
+use Chat\Chat\RoomDeletionService;

 private static function deleteRoomWithDependencies(Connection $db, int $roomId): void
 {
-    $db->beginTransaction();
-    try {
-        $db->execute('DELETE FROM messages WHERE room_id = ?', [$roomId]);
-        $db->execute('DELETE FROM room_members WHERE room_id = ?', [$roomId]);
-        $db->execute('DELETE FROM invitations WHERE room_id = ?', [$roomId]);
-        $db->execute('DELETE FROM rooms WHERE id = ?', [$roomId]);
-        $db->commit();
-    } catch (\Throwable $e) {
-        $db->rollBack();
-        throw $e;
-    }
+    RoomDeletionService::deleteWithDependencies($db, $roomId);
 }
```

**Diff `src/Admin/RoomManager.php`:**
```diff
+use Chat\Chat\RoomDeletionService;

         $db->beginTransaction();
         try {
-            $db->execute('DELETE FROM messages WHERE room_id = ?', [$roomId]);
-            $db->execute('DELETE FROM room_members WHERE room_id = ?', [$roomId]);
-            $db->execute('DELETE FROM invitations WHERE room_id = ?', [$roomId]);
-            $db->execute('DELETE FROM rooms WHERE id = ?', [$roomId]);
-            $db->commit();
-        } catch (\Throwable) {
-            $db->rollBack();
-            self::jsonError('Не удалось удалить комнату.');
+            RoomDeletionService::deleteWithDependencies($db, $roomId);
         } catch (\Throwable) {
             self::jsonError('Не удалось удалить комнату.');
         }
```

**Верификация:**
```
php -l src/Chat/RoomDeletionService.php
php -l src/Chat/RoomController.php
php -l src/Admin/RoomManager.php
```

**Команды:**
```
git add src/Chat/RoomDeletionService.php \
        src/Chat/RoomController.php \
        src/Admin/RoomManager.php

git commit -m "refactor(chat): extract room deletion to RoomDeletionService

- Create RoomDeletionService::deleteWithDependencies()
- Replace duplicate transaction block in RoomController
- Replace duplicate transaction block in RoomManager
- Both paths now share single implementation"

git push origin main
```

**Риски:** Минимальные. Логика транзакции не меняется, только место её определения.
**Rollback:** `git revert HEAD`
**Deploy:** Требует рестарта WS-процесса (RoomController вызывается из EventRouter).

---

### Шаг 2.2: DefaultRoomService [x] CLOSED

**Коммит:** `00b2a53` refactor(chat): extract room membership and deletion services

**Закрыто:**
- Создан `src/Chat/DefaultRoomMembership.php` — `joinDefaultRooms(Connection, int): void`
- Удалён `RegisterHandler::joinDefaultRooms()` (private копия)
- Удалён `GoogleOAuth::joinDefaultRooms()` (private копия)
- Унифицирован SQL и `(int)` cast

**Примечание:** Класс назван `DefaultRoomMembership` (не `DefaultRoomService`) по решению пользователя
(разделение ответственностей: только назначение дефолтных комнат). VKOAuth.php не содержал дублей
на момент выполнения (VK OAuth отключён на уровне роутера, файл не изменялся).

**Что:** Вынести тройной дубль joinDefaultRooms в общий сервис.

**Новый файл:** `src/Chat/DefaultRoomService.php`
```php
<?php
declare(strict_types=1);

namespace Chat\Chat;

use Chat\DB\Connection;

class DefaultRoomService
{
    /**
     * Добавить пользователя в дефолтные публичные комнаты.
     * Вызывается после регистрации и OAuth login.
     */
    public static function joinDefaultRooms(Connection $db, int $userId): void
    {
        $rooms = $db->fetchAll(
            "SELECT id FROM rooms WHERE type = 'public' AND is_closed = 0 ORDER BY id LIMIT 5"
        );
        foreach ($rooms as $room) {
            $db->execute(
                'INSERT IGNORE INTO room_members (room_id, user_id, room_role) VALUES (?, ?, ?)',
                [(int) $room['id'], $userId, 'member']
            );
        }
    }
}
```

**Diff `src/Auth/RegisterHandler.php`:**
```diff
+use Chat\Chat\DefaultRoomService;

-    private static function joinDefaultRooms(Connection $db, int $userId): void
-    {
-        $rooms = $db->fetchAll(
-            "SELECT id FROM rooms WHERE type = 'public' AND is_closed = 0 ORDER BY id LIMIT 5"
-        );
-        foreach ($rooms as $room) {
-            $db->execute(
-                'INSERT IGNORE INTO room_members (room_id, user_id, room_role) VALUES (?, ?, ?)',
-                [(int) $room['id'], $userId, 'member']
-            );
-        }
-    }
```

Вызов в RegisterHandler::handle() оставить как есть:
```php
self::joinDefaultRooms($db, $userId);  // эту строку заменить на:
DefaultRoomService::joinDefaultRooms($db, $userId);
```

**Аналогично для GoogleOAuth.php и VKOAuth.php** — удалить приватные joinDefaultRooms(),
заменить вызовы на `DefaultRoomService::joinDefaultRooms($db, $userId)`.

**Верификация:**
```
php -l src/Chat/DefaultRoomService.php
php -l src/Auth/RegisterHandler.php
php -l src/Auth/GoogleOAuth.php
php -l src/Auth/VKOAuth.php
```

**Команды:**
```
git add src/Chat/DefaultRoomService.php \
        src/Auth/RegisterHandler.php \
        src/Auth/GoogleOAuth.php \
        src/Auth/VKOAuth.php

git commit -m "refactor(auth): extract joinDefaultRooms to DefaultRoomService

- Create DefaultRoomService::joinDefaultRooms()
- Remove duplicate private methods from RegisterHandler, GoogleOAuth, VKOAuth
- Centralize onboarding room policy in one place"

git push origin main
```

**Риски:** Минимальные. SQL идентичен, только место определения меняется.
**Rollback:** `git revert HEAD`

---

## ФАЗА 3: Производительность (1–2 часа)

### Шаг 3.1: Пагинация /api/rooms [~] PARTIAL

**Что:** Добавить LIMIT/OFFSET в /api/rooms для масштабирования.

**Файл:** `src/Chat/RoomController.php`

**Diff:**
```diff
-    public static function list(int $userId, string $globalRole): void
+    public static function list(int $userId, string $globalRole, int $limit = 100, int $offset = 0): void
     {
         $db = Connection::getInstance();
+        $limit  = max(1, min($limit, 100));
+        $offset = max(0, $offset);
         $rooms = $db->fetchAll(
             "SELECT r.id, r.name, r.description, r.type, r.is_closed,
                     (SELECT COUNT(*) FROM room_members rm WHERE rm.room_id = r.id AND rm.room_role != 'banned') AS member_count,
                     rm2.room_role AS my_role
              FROM rooms r
              LEFT JOIN room_members rm2 ON rm2.room_id = r.id AND rm2.user_id = ?
              WHERE r.type = 'public'
                AND r.is_closed = 0
                AND (rm2.room_role IS NULL OR rm2.room_role != 'banned')
-             ORDER BY r.id",
+             ORDER BY r.id
+             LIMIT ? OFFSET ?",
-            [$userId]
+            [$userId, $limit, $offset]
         );
+        header('Content-Type: application/json; charset=UTF-8');
+        echo json_encode(['success' => true, 'rooms' => $rooms, 'total' => count($rooms)], JSON_UNESCAPED_UNICODE);
+        exit;
```

**Файл:** `src/Http/Router.php` — передавать параметры в list():
```diff
-        if ($this->method === 'GET'  && $this->path === '/api/rooms')  RoomController::list((int) $this->user['id'], $this->user['global_role']);
+        if ($this->method === 'GET'  && $this->path === '/api/rooms')  RoomController::list(
+            (int) $this->user['id'],
+            $this->user['global_role'],
+            (int) ($_GET['limit']  ?? 100),
+            (int) ($_GET['offset'] ?? 0)
+        );
```

**Frontend изменений не требует** — текущий `$.get('/api/rooms', ...)` продолжает работать,
получая до 100 комнат. Параметры limit/offset доступны для будущего infinite scroll.

**Верификация:**
```
php -l src/Chat/RoomController.php
php -l src/Http/Router.php
```

**Команды:**
```
git add src/Chat/RoomController.php src/Http/Router.php

git commit -m "feat(api): add pagination parameters to /api/rooms

- Add limit (1-100, default 100) and offset parameters
- Backward compatible: existing clients get same result
- Enables future infinite scroll or load-more for room lists"

git push origin main
```

**Риски:** Минимальные. Default limit=100 сохраняет текущее поведение.
**Rollback:** `git revert HEAD`

---

## ФАЗА M: Завершение системы модерации [⏸] DEFERRED

**Статус:** ⏸ DEFERRED — незавершённая инфраструктура модерации, уже присутствует на production, но не является ближайшей активной задачей.

**Причина DEFERRED:**
- Текущий масштаб: 6 пользователей, 2 комнаты.
- `moderation_events` и `active_restrictions` пустые — ни одной записи.
- Старая модель kick/ban/mute через `RoomController` сейчас достаточна.
- Частичная активация (например, только AccessContext без write в moderation_events) опаснее, чем оставить как есть: таблицы останутся рассинхронизированными с реальными событиями.

**Триггер возврата:**
- Рост аудитории и появление активных модераторов.
- Необходимость audit log модерационных действий.
- Жалобы на превышение полномочий, требующие разбора истории.
- Явное решение владельца активировать Фазу M.

**Контекст:**

Целенаправленная серия коммитов 2026-05-20, остановившаяся на полпути:

```
docs/MODERATION_POLICY.md         — source of truth (2026-05-19, 6925a44)
  ↓
database/migrations/013           — moderation_events audit log (1a2b6d3)
database/migrations/014           — active_restrictions hot-path table (26fea9a)
database/migrations/015           — legacy_import backfill (8fbc018)
  ↓
src/Security/AccessContext.php    — resolver уровней прав, DB-first (e273342, d9c457e)
src/WebSocket/EventRouter.php     — executeForceLogout / executePresenceCleanup (844539b)
  ↓
⛔ остановилось здесь — переключение на другие задачи
```

Все таблицы на production (пустые). Код не подключён ни к одной из них.
Коммиты явно помечены: "unused yet — wiring is next step".

**Принцип при активации:** Фазу M нельзя выполнять частично. Частичное подключение создаст рассинхронизацию между таблицами и реальными событиями модерации.

---

### Шаг M1: Подключение AccessContext [⏸] DEFERRED

**Что:** Заменить `RoomController::resolvePermission()` и `Access::resolveLevel()` на `AccessContext::getModerationContext()` во всём manage/kick/ban/mute flow.

**Ключевые отличия после перехода:**
- DB-fresh роль вместо session snapshot
- Scope guard: local_mod/local_admin не могут действовать вне своей комнаты
- I-3: explicit guard — platform_owner не может быть модерирован
- Шкала расширена до 7 (root_owner зарезервирован)

**Файлы:** `src/Chat/RoomController.php`, `src/WebSocket/EventRouter.php`

**Зависит от:** M4 (reason validation), M5 (self-action guard)

---

### Шаг M2: moderation_events write path [⏸] DEFERRED

**Что:** При каждом kick/ban/mute/unban/unmute писать запись в `moderation_events`.

**Поля:** `act`, `origin='realtime'`, `actor_id`, `actor_role`, `target_user_id`, `room_id`, `reason`, `expires_at`, `ban_issued_by_role`.

**Файлы:** `src/Chat/RoomController.php`, `src/Admin/UserManager.php`

**Зависит от:** M1 (нужен actor context для `actor_role` snapshot)

---

### Шаг M3: active_restrictions read path [⏸] DEFERRED

**Что:** При join_room, send_message и session validate читать из `active_restrictions` вместо (или в дополнение к) прямых проверок `room_members.room_role='banned'` и `muted_until`.

**Файлы:** `src/WebSocket/EventRouter.php`, `src/Chat/MessageController.php`

**Зависит от:** M2 (таблица должна быть заполнена)

---

### Шаг M4: reason validation [⏸] DEFERRED

**Что:** Реализовать инвариант I-5: причина обязательна для ban и mute.

Текущее состояние: `ban` и `mute` в `RoomController` принимают пустую причину без ошибки.

**Backend:** добавить проверку `reason !== ''` перед записью.
**Frontend:** `chat.js` — UI модал ban/mute должен требовать reason (поле обязательное).

**Файлы:** `src/Chat/RoomController.php`, `public/assets/js/chat.js`

---

### Шаг M5: self-action guards [⏸] DEFERRED

**Что:** Реализовать инвариант I-1: нельзя применить действие к себе.

Текущее состояние: нет проверки `actorId !== targetId` в kick/ban/mute.

**Файлы:** `src/Chat/RoomController.php`

---

### Шаг M6: policy smoke tests [⏸] DEFERRED

**Что:** Верифицировать матрицы из MODERATION_POLICY.md §3 вручную:

| Тест | Актор | Цель | Ожидание |
|---|---|---|---|
| I-1 | owner | owner (self) | reject |
| I-3 | admin | platform_owner | reject |
| kick matrix | local_mod | member | allow |
| kick matrix | local_mod | local_admin | reject |
| kick matrix | global_mod | owner | allow |
| ban matrix | local_mod | member | allow (24h max) |
| mute I-5 | any | any | reject если reason пустой |
| scope I-7 | local_admin чужой комнаты | member | reject |

---

## ФАЗА 4: Унификация permission-логики (4–6 часов)

**ПРИМЕЧАНИЕ:** Приоритет понижен. Выполнять только после принятия решения по Фазе M.
Если Фаза M будет реализована — Шаг 4.1 становится избыточным:
`resolvePermission` будет заменён на `AccessContext` целиком, не на `Access::resolveLevel`.

### Шаг 4.1: Унификация resolvePermission/resolveLevel [ ] OPEN — BLOCKED by Phase M

**Что:** Устранить дублирование иерархии прав между Access::resolveLevel() и RoomController::resolvePermission().

**Подход:** Добавить публичный метод в Access.php, который возвращает int,
и заменить внутреннюю реализацию resolvePermission() на вызов Access::resolveLevel().

**Diff `src/Admin/Access.php`:**
```diff
-    private static function resolveLevel(int $roomId, int $userId, array $actor): int
+    public static function resolveLevel(int $roomId, int $userId, array $actor): int
```

**Diff `src/Chat/RoomController.php`:**
```diff
+use Chat\Admin\Access;

     private static function resolvePermission(int $roomId, int $userId, array $actor): ?array
     {
-        if (($actor['global_role'] ?? 'user') === 'platform_owner') {
-            return ['level' => 6];
-        }
-        if (($actor['global_role'] ?? 'user') === 'admin') {
-            return ['level' => 5];
-        }
-        if (($actor['global_role'] ?? 'user') === 'moderator') {
-            return ['level' => 4];
-        }
-        $db   = Connection::getInstance();
-        $role = $db->fetchOne(
-            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
-            [$roomId, $userId]
-        )['room_role'] ?? null;
-        return match ($role) {
-            'owner'           => ['level' => 3, 'role' => $role],
-            'local_admin'     => ['level' => 2, 'role' => $role],
-            'local_moderator' => ['level' => 1, 'role' => $role],
-            'member'          => ['level' => 0, 'role' => $role],
-            default           => null,
-        };
+        $level = Access::resolveLevel($roomId, $userId, $actor);
+        if ($level < 0) {
+            return null;
+        }
+        return ['level' => $level];
     }
```

**Риски:** СРЕДНИЕ. resolvePermission() используется во всём RoomController::manage().
Требует полного smoke-test kick/ban/mute/rename/delete после изменения.

**Команды:**
```
git add src/Admin/Access.php src/Chat/RoomController.php

git commit -m "refactor(permissions): unify resolvePermission via Access::resolveLevel

- Make Access::resolveLevel() public
- Replace duplicate logic in RoomController::resolvePermission()
- Both paths now share single permission hierarchy implementation"

git push origin main
```

**Rollback:** `git revert HEAD`
**Deploy:** Требует рестарта WS-процесса. Smoke-test обязателен.

---

## ФАЗА 5: Schema guard (2–4 часа, долгосрочно)

### Шаг 5.1: Замена SHOW COLUMNS на schema version [~] PARTIALLY CLOSED

**Закрыто (runtime schema cleanup):**
- `145edf6` — удалены guards из `MessageController` и `UserManager`
- `4be390b` — удалён `hasRoomMembersColumn()` guard из `RoomController` (`muted_until`, `mute_reason`)

**Остаётся открытым (DEFERRED BY DESIGN):**
- `RoomManager::roomCategoryOptions()` — `SHOW COLUMNS FROM rooms LIKE 'room_category'`
  Причина: ENUM introspection для возврата `room_category_options` во frontend.
  Изменение требует явного решения владельца (hardcode vs introspection).
  Статус: **DEFERRED BY DESIGN**

**Что:** Убрать runtime `SHOW COLUMNS` проверки, заменить на конфигурационную проверку версии схемы.

**Подход:** Добавить таблицу `schema_version` или использовать `app_settings`.

**Вариант A — app_settings:**
```sql
INSERT INTO app_settings (name, value) VALUES ('schema_version', '10');
```

```php
// Новый метод в Connection.php
public function getSchemaVersion(): int {
    $row = $this->fetchOne("SELECT value FROM app_settings WHERE name = 'schema_version'");
    return (int) ($row['value'] ?? 0);
}
```

Заменить все `SHOW COLUMNS FROM users` на:
```php
if (Connection::getInstance()->getSchemaVersion() >= 5) {
    // bio, social_* доступны
}
```

**Риски:** СРЕДНИЕ. Требует точного маппинга "какая версия — какие колонки".
Реализовывать только после верификации что все production миграции применены.

**Действие:** Отложено до стабилизации схемы на production.

---

## СВОДНАЯ ТАБЛИЦА

| Шаг | Файлы | Время | Риск | Статус |
|-----|-------|-------|------|--------|
| 0.1 Commit pending | 5 файлов | 15 мин | Нет | [x] CLOSED `fbfc0f7`, `e0877c7` |
| 1.1 EmbedProcessor SSRF | 1 файл | 30 мин | Низкий | [x] CLOSED `1d1eb91` |
| 1.2 reactor_raw | 1-2 файла | 1 час | Средний | Ждёт решения владельца |
| 1.3 index.php warning | 1 файл | 10 мин | Нет | [~] SKIPPED — gitignored, три слоя защиты |
| 2.1 RoomDeletionService | 2 файла | 1 час | Низкий | [x] CLOSED `00b2a53` |
| 2.2 DefaultRoomMembership | 3 файла | 1 час | Низкий | [x] CLOSED `00b2a53` |
| 2.x JsonResponse phase 1–3 | 6 файлов | — | Низкий | [x] CLOSED `d3ebda5`, `b1e1cfe`, `c037c5b` |
| 2.x Schema cleanup phase 1–2 | 3 файла | — | Низкий | [x] CLOSED `145edf6`, `4be390b` |
| 2.x JsonResponse phase4 | src/Admin/RoomManager.php | — | Низкий | [x] CLOSED `26ceb9d` |
| 2.x JsonResponse phase5 | src/Admin/UserManager.php | — | Низкий | [x] CLOSED `c002872` |
| 2.x JsonResponse phase6 | src/Http/Router.php | — | Средний | [x] CLOSED `c5a05c9`, `fa3105e` |
| 2.x WS reload cleanup | chat.js | — | Низкий | [x] CLOSED `017482e`, `ae4c82b`, `18c3018` |
| 2.x Room role realtime update | 3 файла | — | Низкий | [x] CLOSED `14a993b` — проверено вручную |
| 3.1-A SQL optimization (GROUP BY) | 1 файл | — | Нет | [x] CLOSED `fba6ac5` |
| 3.1-B Pagination LIMIT/OFFSET | 2 файла | 1 час | Низкий | [ ] OPEN |
| **M1** AccessContext подключение | RoomController, EventRouter | — | Высокий | ⏸ DEFERRED |
| **M2** moderation_events write | RoomController, UserManager | — | Высокий | ⏸ DEFERRED — зависит от M1 |
| **M3** active_restrictions read | EventRouter, MessageController | — | Высокий | ⏸ DEFERRED — зависит от M2 |
| **M4** reason validation | RoomController, chat.js | — | Средний | ⏸ DEFERRED |
| **M5** self-action guards | RoomController | — | Средний | ⏸ DEFERRED |
| **M6** policy smoke tests | — | — | — | ⏸ DEFERRED — финальная верификация |
| 4.1 Permission unification | 2 файла | 2 часа | Средний | ⏸ DEFERRED pending Phase M decision |
| 4.x Global role realtime update | chat.js + WS/IPC | — | Средний | [ ] OPEN — HTTP/WS split; updateOnlineUser() helper существует (`afdab97`); архитектурное решение ещё не принято |
| 5.1 Schema guard (roomCategoryOptions) | 1 файл | — | Низкий | ⏸ DEFERRED BY DESIGN |

**Прогресс (☑/☐):**
- ☑ JSON cleanup (phase 1–6)
- ☑ Runtime schema cleanup (phase 1–2)
- ☑ Service extraction (RoomDeletion, DefaultRoomMembership)
- ☑ WS reload cleanup (phase 1, 2a, 2b)
- ☑ SSRF protection
- ☑ Room role realtime update + system messages
- ☑ SQL optimization — correlated COUNT → GROUP BY (`fba6ac5`)
- ☑ updateOnlineUser() helper extracted (`afdab97`)
- ☐ Global role realtime update
- ☐ Pagination LIMIT/OFFSET /api/rooms (Phase 3.1-B)
- ⏸ Фаза M: завершение системы модерации (M1–M6) — DEFERRED, триггер: рост аудитории / модераторы
- ⏸ Permission unification — DEFERRED pending Phase M decision
- ☐ index.php cleanup — SKIPPED
- ☐ reactor_raw (ждёт решения владельца)

**Итого закрыто:**
- Фаза 0 полностью
- Фаза 1: шаг 1.1 (SSRF) закрыт
- Фаза 2 полностью (сервисы, JsonResponse, schema, WS, room role)
- Фаза 3: шаг 3.1-A (SQL optimization) закрыт
- Фаза 5 частично

**Итого открыто:** Шаг 1.3 (SKIPPED), Фаза 3.1-B (LIMIT/OFFSET), Фаза M (moderation), Фаза 4 (global role + permission unification — blocked)

---

## DEPLOY CHECKLIST (для каждого шага)

Перед каждым git push на production:
1. git status — рабочее дерево чистое
2. php -l на все изменённые PHP файлы
3. Проверить diff (git diff HEAD~1)
4. Если изменения в src/WebSocket/ или src/Chat/ — запланировать рестарт WS
5. Если изменения в DB — применить миграцию на production перед кодом
6. После deploy — smoke test (login, send message, admin panel)
7. Обновить CLAUDE.md раздел 16 и .codex/session-log.md
