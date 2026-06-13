<?php
declare(strict_types=1);

/**
 * Сквозная проверка жалоб владельца (2026-06-12):
 *  A. Выдача кляпа: модератор должен получить staff-событие room_updated
 *     (двигает кнопку «Кляп» → «Снять кляп»), цель — muted_in_room.
 *     Профиль /api/users/{id}?room_id=N должен отдать состояние кляпа стаффу.
 *  B. Снятие через админку (HTTP): цель должна получить unmuted_in_room
 *     через мост S2 без обновления страницы; стафф — room_updated.
 * Запуск: docker exec chat_php php /var/www/chat/tests/e2e/mute_check.php
 */

require '/var/www/chat/config/config.php';
require '/var/www/chat/vendor/autoload.php';
require '/var/www/chat/tests/Support/WsTestClient.php';

use Chat\DB\Connection;
use Tests\Support\WsTestClient;

$db = Connection::getInstance();
$adminId  = (int) $db->fetchOne("SELECT id FROM users WHERE username = 'e2e_admin'")['id'];
$targetId = (int) $db->fetchOne("SELECT id FROM users WHERE username = 'e2e_target'")['id'];
$roomId   = 1;

// чистый старт
$db->execute('UPDATE room_members SET muted_until = NULL, mute_reason = NULL WHERE room_id = ? AND user_id = ?', [$roomId, $targetId]);
$db->execute('DELETE FROM active_restrictions WHERE target_user_id = ?', [$targetId]);

function mintSession(Connection $db, int $userId): string
{
    $token = bin2hex(random_bytes(32));
    $db->execute(
        "INSERT INTO sessions (user_id, token_hash, ip_ua_hash, expires_at)
         VALUES (?, ?, REPEAT('e', 64), DATE_ADD(NOW(), INTERVAL 1 DAY))",
        [$userId, hash('sha256', $token)]
    );
    return $token;
}

$adminToken  = mintSession($db, $adminId);
$targetToken = mintSession($db, $targetId);
$origin = 'http://127.0.0.1:8080';

$admin  = new WsTestClient('127.0.0.1', (int) WS_PORT, $adminToken, $origin);
$target = new WsTestClient('127.0.0.1', (int) WS_PORT, $targetToken, $origin);

$admin->send(['event' => 'join_room', 'room_id' => $roomId]);
$target->send(['event' => 'join_room', 'room_id' => $roomId]);
$adminBoot  = $admin->readEvents(2.0);
$targetBoot = $target->readEvents(2.0);

$pick = static fn(array $events, string $name): array =>
    array_values(array_filter($events, static fn($e) => ($e['event'] ?? '') === $name));

$results = [];
$results['оба подключены и в комнате'] =
    $pick($adminBoot, 'room_joined') !== [] && $pick($targetBoot, 'room_joined') !== [];

// ── A. Выдача кляпа через WS ────────────────────────────────────────────────
$admin->send(['event' => 'room_action', 'room_id' => $roomId, 'action' => 'mute',
              'target_user_id' => $targetId, 'minutes' => 5, 'reason' => 'e2e проверка']);

$adminEvents  = $admin->readEvents(2.5);
$targetEvents = $target->readEvents(0.5);

$staffMuted = array_values(array_filter(
    $pick($adminEvents, 'room_updated'),
    static fn($e) => ($e['data']['muted'] ?? false) === true
));
$results['A1: стафф получил room_updated muted=true (кнопка→«Снять кляп»)'] =
    $staffMuted !== [] && !empty($staffMuted[0]['data']['muted_until'])
    && (int) $staffMuted[0]['data']['target_user_id'] === $targetId;

$results['A2: цель получила личное muted_in_room'] =
    $pick($targetEvents, 'muted_in_room') !== [];

// A3: профиль отдаёт состояние кляпа стаффу (источник правды для модалки)
$profileCtx = stream_context_create(['http' => [
    'header' => 'Cookie: chat_session=' . $adminToken . "\r\nHost: localhost:8080",
]]);
$profile = json_decode((string) @file_get_contents(
    'http://nginx/api/users/' . $targetId . '?room_id=' . $roomId, false, $profileCtx
), true);
$results['A3: профиль (стафф) содержит room_moderation.muted_until'] =
    !empty($profile['room_moderation']['muted_until']);

// A4: обычному участнику состояние кляпа не отдаётся
$targetCtx = stream_context_create(['http' => [
    'header' => 'Cookie: chat_session=' . $targetToken . "\r\nHost: localhost:8080",
]]);
$profileAsUser = json_decode((string) @file_get_contents(
    'http://nginx/api/users/' . $targetId . '?room_id=' . $roomId, false, $targetCtx
), true);
$results['A4: обычному участнику room_moderation НЕ отдаётся'] =
    is_array($profileAsUser) && !isset($profileAsUser['room_moderation']);

// ── B. Снятие через админку (HTTP) → мост S2 ───────────────────────────────
$csrf = bin2hex(random_bytes(32));
$unmuteCtx = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => 'Cookie: chat_session=' . $adminToken . '; csrf_token=' . $csrf . "\r\n"
              . 'X-CSRF-Token: ' . $csrf . "\r\n"
              . 'Host: localhost:8080' . "\r\n"
              . 'Content-Length: 0',
    'ignore_errors' => true,
]]);
$unmuteResp = (string) @file_get_contents(
    'http://nginx/api/admin/rooms/' . $roomId . '/unmute/' . $targetId, false, $unmuteCtx
);
$results['B1: HTTP unmute из админки принят'] =
    (json_decode($unmuteResp, true)['success'] ?? false) === true;

// мост поллится раз в секунду — ждём до 3 секунд
$targetAfter = $target->readEvents(3.0);
$adminAfter  = $admin->readEvents(0.5);

$results['B2: цель получила unmuted_in_room БЕЗ рефреша (мост S2)'] =
    $pick($targetAfter, 'unmuted_in_room') !== [];

$staffUnmuted = array_values(array_filter(
    $pick($adminAfter, 'room_updated'),
    static fn($e) => ($e['data']['unmuted'] ?? false) === true
));
$results['B3: стафф получил room_updated unmuted=true (кнопка→«Кляп»)'] = $staffUnmuted !== [];

// B4: журнал и горячая таблица согласованы
$lastTwo = $db->fetchAll(
    'SELECT act FROM moderation_events WHERE target_user_id = ? ORDER BY id DESC LIMIT 2', [$targetId]
);
$results['B4: журнал содержит mute+unmute, активных ограничений нет'] =
    array_column($lastTwo, 'act') === ['unmute', 'mute']
    && (int) $db->fetchOne('SELECT COUNT(*) AS c FROM active_restrictions WHERE target_user_id = ?', [$targetId])['c'] === 0;

$admin->close();
$target->close();
$db->execute('DELETE FROM sessions WHERE token_hash IN (?, ?)', [hash('sha256', $adminToken), hash('sha256', $targetToken)]);

$failed = 0;
foreach ($results as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$ok) $failed++;
}
exit($failed === 0 ? 0 : 1);
