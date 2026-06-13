<?php
declare(strict_types=1);
require '/var/www/chat/config/config.php';
require '/var/www/chat/vendor/autoload.php';

$db = Chat\DB\Connection::getInstance();
$hash = password_hash('E2eTest!123', PASSWORD_ARGON2ID);
foreach ([['e2e_admin', 'platform_owner'], ['e2e_target', 'user']] as [$name, $role]) {
    $db->execute(
        'INSERT INTO users (username, email, password_hash, global_role, email_verified)
         VALUES (?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash),
             global_role = VALUES(global_role), email_verified = 1, is_banned = 0',
        [$name, $name . '@local.test', $hash, $role]
    );
}
$db->execute(
    "INSERT IGNORE INTO rooms (id, name, description, type, room_category) VALUES
     (1, 'Общий', 'Главный публичный чат', 'public', 'permanent')"
);

$admin  = (int) $db->fetchOne('SELECT id FROM users WHERE username = ?', ['e2e_admin'])['id'];
$target = (int) $db->fetchOne('SELECT id FROM users WHERE username = ?', ['e2e_target'])['id'];
foreach ([$admin, $target] as $uid) {
    $db->execute('INSERT IGNORE INTO room_members (room_id, user_id, room_role) VALUES (1, ?, ?)', [$uid, 'member']);
}
$db->execute('UPDATE room_members SET muted_until = NULL, mute_reason = NULL WHERE room_id = 1 AND user_id = ?', [$target]);
$db->execute(
    'INSERT INTO messages (room_id, user_id, content, content_hmac, type)
     VALUES (1, ?, ?, NULL, ?)',
    [$target, 'Тестовое сообщение от e2e_target для проверки кляпа', 'text']
);
echo 'admin_id=' . $admin . ' target_id=' . $target . PHP_EOL;
