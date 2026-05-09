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

Lang::init(APP_LOCALE);

$loop = Loop::get();

// Expire pending invitations every 10 seconds
$loop->addPeriodicTimer(10, function () {
    try {
        \Chat\Chat\NumerController::expireInvitations();
    } catch (\Throwable) {}
});

$bindHost = defined('WS_BIND_HOST') ? WS_BIND_HOST : WS_HOST;

$server = IoServer::factory(
    new HttpServer(new WsServer(new Server())),
    WS_PORT,
    $bindHost,
    $loop
);

echo '[WS] Starting WebSocket server on ' . $bindHost . ':' . WS_PORT . PHP_EOL;

$server->run();
