<?php
declare(strict_types=1);

/**
 * HTTP-смоук вкладки «Санкции» (S5) через nginx под владельцем платформы.
 * Проверяет реальные маршруты Router → SanctionPanel: stats, config, events,
 * shadow, stopwords (add/list/delete), ip-intel, переключение режима с подтверждением.
 * Запуск: docker exec chat_php php /var/www/chat/tests/e2e/sanctions_smoke.php
 */

require '/var/www/chat/config/config.php';
require '/var/www/chat/vendor/autoload.php';

use Chat\DB\Connection;

$db = Connection::getInstance();
$ownerId = (int) $db->fetchOne("SELECT id FROM users WHERE username = 'e2e_admin'")['id']; // platform_owner

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

$token = session($db, $ownerId);
$csrf  = bin2hex(random_bytes(32));

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

$results = [];

// stats
$stats = call('GET', '/api/admin/sanctions/stats', $token, $csrf);
$results['stats отдаёт autonomy'] = ($stats['success'] ?? false) && isset($stats['stats']['autonomy']['mode']);

// config (owner)
$cfg = call('GET', '/api/admin/sanctions/config', $token, $csrf);
$results['config доступен владельцу'] = ($cfg['success'] ?? false) && isset($cfg['config']);

// events
$events = call('GET', '/api/admin/sanctions/events', $token, $csrf);
$results['events отдаёт список'] = ($events['success'] ?? false) && array_key_exists('events', $events);

// shadow (owner)
$shadow = call('GET', '/api/admin/sanctions/shadow', $token, $csrf);
$results['shadow доступен владельцу'] = ($shadow['success'] ?? false) && array_key_exists('shadow', $shadow);

// stopword add → list → contains
$uniq = 'e2eword' . random_int(1000, 9999);
$add = call('POST', '/api/admin/sanctions/stopwords', $token, $csrf, ['pattern' => $uniq, 'duration' => '1h']);
$results['стоп-слово добавлено'] = ($add['success'] ?? false);
$list = call('GET', '/api/admin/sanctions/stopwords', $token, $csrf);
$found = null;
foreach ($list['stop_words'] ?? [] as $w) {
    if ($w['pattern'] === $uniq) { $found = (int) $w['id']; }
}
$results['стоп-слово в списке'] = $found !== null;

// stopword delete
if ($found !== null) {
    $del = call('DELETE', '/api/admin/sanctions/stopwords/' . $found, $token, $csrf);
    $results['стоп-слово удалено'] = ($del['success'] ?? false);
}

// ip-intel
$intel = call('GET', '/api/admin/sanctions/ip-intel', $token, $csrf);
$results['ip-intel отдаёт alerts'] = ($intel['success'] ?? false) && array_key_exists('alerts', $intel);

// live без confirm → 409, с confirm → success; вернуть в shadow
$live = call('POST', '/api/admin/sanctions/config', $token, $csrf, ['key' => 'mode', 'value' => 'live']);
$results['боевой без подтверждения отклонён'] = ($live['success'] ?? true) === false;
$liveOk = call('POST', '/api/admin/sanctions/config', $token, $csrf, ['key' => 'mode', 'value' => 'live', 'confirm' => 1]);
$results['боевой с подтверждением включён'] = ($liveOk['success'] ?? false)
    && ($liveOk['config']['mode'] ?? '') === 'live';
$back = call('POST', '/api/admin/sanctions/config', $token, $csrf, ['key' => 'mode', 'value' => 'shadow']);
$results['возврат в тень'] = ($back['success'] ?? false) && ($back['config']['mode'] ?? '') === 'shadow';

// чистка
$db->execute('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', $token)]);

$failed = 0;
foreach ($results as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$ok) $failed++;
}
exit($failed === 0 ? 0 : 1);
