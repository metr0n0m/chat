<?php
declare(strict_types=1);

/**
 * Локальная диагностическая страница Docker-окружения.
 * Last updated: 2026-04-17.
 */
$host = 'db';
$db   = 'chat';
$user = 'chat_user';
$pass = 'chat_pass';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $dbStatus = '<span style="color: #198754">MariaDB подключена</span>';
} catch (PDOException $e) {
    $dbStatus = '<span style="color: #dc3545">БД недоступна: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</span>';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Chat Local Dev</title>
    <style>
        body { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; margin: 0; padding: 32px; background: #0f172a; color: #e2e8f0; }
        h1 { margin: 0 0 16px; color: #38bdf8; }
        .card { background: #111827; border: 1px solid #1f2937; border-radius: 10px; padding: 16px; margin-bottom: 14px; }
        .label { color: #93c5fd; }
        a { color: #f59e0b; }
    </style>
</head>
<body>
    <h1>Chat Local Docker Environment</h1>
    <div class="card">
        <p><span class="label">PHP:</span> <?= htmlspecialchars((string) phpversion(), ENT_QUOTES, 'UTF-8') ?></p>
        <p><span class="label">Web server:</span> <?= htmlspecialchars((string) ($_SERVER['SERVER_SOFTWARE'] ?? 'nginx'), ENT_QUOTES, 'UTF-8') ?></p>
        <p><span class="label">Database:</span> <?= $dbStatus ?></p>
        <p><span class="label">Server time:</span> <?= htmlspecialchars(date('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <div class="card">
        <p><span class="label">phpMyAdmin:</span> <a href="http://localhost:8081">http://localhost:8081</a></p>
    </div>
</body>
</html>
