<?php
declare(strict_types=1);

/**
 * HTTP-смоук комнатных стоп-слов (S5 UI) через nginx.
 * Владелец комнаты добавляет/смотрит/удаляет стоп-слово своей комнаты.
 * Запуск: docker exec chat_php php /var/www/chat/tests/e2e/room_stopwords_smoke.php
 */

require '/var/www/chat/config/config.php';
require '/var/www/chat/vendor/autoload.php';

use Chat\DB\Connection;

$db = Connection::getInstance();

// владелец и его комната
$owner = $db->fetchOne("SELECT id FROM users WHERE username = 'e2e_target'");
$ownerId = (int) $owner['id'];
$db->execute("INSERT INTO rooms (name, type, room_category, owner_id) VALUES ('SW-смоук', 'public', 'user', ?)", [$ownerId]);
$roomId = (int) $db->lastInsertId();
$db->execute("INSERT INTO room_members (room_id, user_id, room_role) VALUES (?, ?, 'owner')", [$roomId, $ownerId]);

// посторонний участник (не владелец) — для проверки запрета
$db->execute("INSERT INTO users (username, email, password_hash, global_role, email_verified) VALUES ('e2e_outsider', 'o@x.t', 'x', 'user', 1) ON DUPLICATE KEY UPDATE id = id");
$outsiderId = (int) $db->fetchOne("SELECT id FROM users WHERE username = 'e2e_outsider'")['id'];
$db->execute("INSERT IGNORE INTO room_members (room_id, user_id, room_role) VALUES (?, ?, 'member')", [$roomId, $outsiderId]);

function session(Connection $db, int $userId): string
{
    $token = bin2hex(random_bytes(32));
    $db->execute(
        "INSERT INTO sessions (user_id, token_hash, ip_ua_hash, expires_at)
         VALUES (?, ?, REPEAT('s', 64), DATE_ADD(NOW(), INTERVAL 1 DAY))",
        [$userId, hash('sha256', $token)]
    );
    return $token;
}

function call(string $method, string $path, string $token, string $csrf, ?array $body = null): array
{
    $headers = [
        'Cookie: chat_session=' . $token . '; csrf_token=' . $csrf,
        'X-CSRF-Token: ' . $csrf,
        'Host: localhost:8080',
    ];
    $opts = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true]];
    if ($body !== null) {
        $opts['http']['header'] .= "\r\nContent-Type: application/x-www-form-urlencoded";
        $opts['http']['content'] = http_build_query($body);
    }
    $raw = @file_get_contents('http://nginx' . $path, false, stream_context_create($opts));
    return json_decode((string) $raw, true) ?? ['_raw' => $raw];
}

$ownerTok = session($db, $ownerId);
$outTok   = session($db, $outsiderId);
$csrf     = bin2hex(random_bytes(32));
$results  = [];

$word = 'roomsw' . random_int(1000, 9999);
$add = call('POST', "/api/rooms/$roomId/stopwords", $ownerTok, $csrf, ['pattern' => $word, 'duration' => '24h']);
$results['владелец добавил стоп-слово'] = ($add['success'] ?? false);

$list = call('GET', "/api/rooms/$roomId/stopwords", $ownerTok, $csrf);
$id = null;
foreach ($list['stop_words'] ?? [] as $w) { if ($w['pattern'] === $word) $id = (int) $w['id']; }
$results['слово в списке комнаты'] = $id !== null;

$denied = call('POST', "/api/rooms/$roomId/stopwords", $outTok, $csrf, ['pattern' => 'hack', 'duration' => '1h']);
$results['постороннему запрещено'] = ($denied['success'] ?? true) === false;

if ($id !== null) {
    $del = call('DELETE', "/api/rooms/$roomId/stopwords/$id", $ownerTok, $csrf);
    $results['владелец удалил слово'] = ($del['success'] ?? false);
}

// чистка
$db->execute('DELETE FROM sessions WHERE token_hash IN (?, ?)', [hash('sha256', $ownerTok), hash('sha256', $outTok)]);
$db->execute('DELETE FROM rooms WHERE id = ?', [$roomId]);

$failed = 0;
foreach ($results as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$ok) $failed++;
}
exit($failed === 0 ? 0 : 1);
