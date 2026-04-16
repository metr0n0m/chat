<?php
// Тест подключения к БД
$host = 'db';
$db   = 'chat';
$user = 'chat_user';
$pass = 'chat_pass';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $dbStatus = '<span style="color:green">✓ MariaDB подключена</span>';
} catch (PDOException $e) {
    $dbStatus = '<span style="color:red">✗ БД не подключена: ' . $e->getMessage() . '</span>';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>chat.adalex.org — Local Dev</title>
    <style>
        body { font-family: monospace; padding: 40px; background: #1e1e1e; color: #d4d4d4; }
        h1 { color: #4ec9b0; }
        .block { background: #252526; padding: 20px; border-radius: 6px; margin: 10px 0; }
        .label { color: #9cdcfe; }
    </style>
</head>
<body>
    <h1>chat.adalex.org — Local Docker Environment</h1>
    <div class="block">
        <p><span class="label">PHP версия:</span> <?= phpversion() ?></p>
        <p><span class="label">Сервер:</span> <?= $_SERVER['SERVER_SOFTWARE'] ?? 'nginx' ?></p>
        <p><span class="label">База данных:</span> <?= $dbStatus ?></p>
        <p><span class="label">Время:</span> <?= date('Y-m-d H:i:s') ?></p>
    </div>
    <div class="block">
        <p><span class="label">phpMyAdmin:</span> <a href="http://localhost:8081" style="color:#ce9178">http://localhost:8081</a></p>
    </div>
</body>
</html>
