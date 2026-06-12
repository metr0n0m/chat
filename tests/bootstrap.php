<?php
declare(strict_types=1);

/**
 * Тестовая загрузка: вместо config/config.php определяются тестовые константы,
 * затем база chat_test пересоздаётся с нуля из актуальной схемы.
 * Тесты выполняются ВНУТРИ контейнера chat_php (хост БД — 'db'):
 *   docker exec -w /var/www/chat chat_php vendor/bin/phpunit
 */

require __DIR__ . '/../vendor/autoload.php';

// ── Тестовая конфигурация (вместо config/config.php) ───────────────────────
define('DB_HOST', getenv('TEST_DB_HOST') ?: 'db');
define('DB_PORT', (int) (getenv('TEST_DB_PORT') ?: 3306));
define('DB_NAME', 'chat_test');
define('DB_USER', getenv('TEST_DB_USER') ?: 'root');
define('DB_PASS', getenv('TEST_DB_PASS') ?: 'root');

define('APP_NAME', 'chat-test');
define('APP_URL', 'http://localhost');
define('APP_LOCALE', 'ru');
define('APP_SECRET', 'test-secret-not-used-in-production');

define('MSG_RATE_LIMIT_SEC', 1);
define('WHISPER_RATE_LIMIT_MIN', 1);
define('INVITE_PENDING_MAX', 5);
define('NUMER_IDLE_TIMEOUT', 1800);

\Chat\Support\Lang::init(APP_LOCALE);

// ── Пересоздание тестовой схемы ─────────────────────────────────────────────
$admin = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT),
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$admin->exec('DROP DATABASE IF EXISTS `' . DB_NAME . '`');
$admin->exec('CREATE DATABASE `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$admin = null;

$schemaPdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS   => true,
    ]
);

$schemaFiles = [
    __DIR__ . '/../src/DB/migrations.sql',
    __DIR__ . '/../database/migrations/013_moderation_events.sql',
    __DIR__ . '/../database/migrations/014_active_restrictions.sql',
    __DIR__ . '/../database/migrations/016_sanctions_engine_s0.sql',
];

foreach ($schemaFiles as $file) {
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException('Не удалось прочитать схему: ' . $file);
    }
    $stmt = $schemaPdo->query($sql);
    // Выбрать все результаты, чтобы ошибка в любом из выражений файла всплыла здесь
    do {
        $stmt->fetchAll();
    } while ($stmt->nextRowset());
    $stmt->closeCursor();
}
$schemaPdo = null;
