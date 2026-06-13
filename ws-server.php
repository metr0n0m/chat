<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Chat\WebSocket\Server;
use Chat\Support\Lang;
use React\EventLoop\Loop;
use React\Socket\SocketServer;

Lang::init(APP_LOCALE);

if (!defined('WS_ALLOWED_ORIGINS') || empty((array) WS_ALLOWED_ORIGINS)) {
    echo '[WS] WARNING: WS_ALLOWED_ORIGINS not configured. All connections will be rejected.' . PHP_EOL;
}

$loop = Loop::get();

// Expire pending invitations every 10 seconds
$loop->addPeriodicTimer(10, function () {
    try {
        \Chat\Chat\NumerController::expireInvitations();
    } catch (\Throwable $e) {
        echo '[WS] Invite expiry error: ' . $e->getMessage() . PHP_EOL;
    }
});

// Convert lapsed active_restrictions to restriction_expired audit events (S1)
$loop->addPeriodicTimer(30, function () {
    try {
        \Chat\Moderation\SanctionService::expireLapsed();
    } catch (\Throwable $e) {
        echo '[WS] expireLapsed error: ' . $e->getMessage() . PHP_EOL;
    }
});

$bindHost = defined('WS_BIND_HOST') ? WS_BIND_HOST : WS_HOST;

$chatServer = new Server();

// S2: deliver events queued by HTTP code (admin panel) to live WS clients
$loop->addPeriodicTimer(1, function () use ($chatServer) {
    try {
        \Chat\WebSocket\OutboxDispatcher::dispatch($chatServer->getConnectionManager());
    } catch (\Throwable $e) {
        echo '[WS] Outbox dispatch error: ' . $e->getMessage() . PHP_EOL;
    }
});

// ВАЖНО: сервер собирается вручную, НЕ через IoServer::factory().
// factory() вызывает Factory::create(), который ПОДМЕНЯЕТ глобальный
// Loop::get() новым циклом — все таймеры, зарегистрированные выше,
// оказались бы в never-running цикле (так с апреля молча не работала
// периодическая чистка приглашений). Один явный $loop — везде.
$server = new IoServer(
    new HttpServer(new WsServer($chatServer)),
    new SocketServer($bindHost . ':' . WS_PORT, [], $loop),
    $loop
);

echo '[WS] Starting WebSocket server on ' . $bindHost . ':' . WS_PORT . PHP_EOL;

$server->run();
