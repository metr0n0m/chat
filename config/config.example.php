<?php
// Database
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'chat');
define('DB_USER', 'chat_user');
define('DB_PASS', 'REPLACE_WITH_DB_PASSWORD');

// Application
define('APP_NAME', 'Chat');
define('APP_URL', 'https://yourdomain.com');
define('APP_LOCALE', 'ru');
// Generate: php -r "echo bin2hex(random_bytes(32));"
define('APP_SECRET', 'REPLACE_WITH_64_HEX_CHARS_GENERATED_ABOVE');

// WebSocket
// WS_HOST is the public/client host. WS_BIND_HOST is the local address used by ws-server.php.
define('WS_PORT', 8080);
define('WS_HOST', 'yourdomain.com');
define('WS_BIND_HOST', '127.0.0.1');

// VK OAuth — disabled
// define('VK_CLIENT_ID', '');
// define('VK_CLIENT_SECRET', '');
// define('VK_REDIRECT_URI', APP_URL . '/auth/vk/callback');

// Google OAuth 2.0 — console.cloud.google.com
define('GOOGLE_CLIENT_ID', '');
define('GOOGLE_CLIENT_SECRET', '');
define('GOOGLE_REDIRECT_URI', APP_URL . '/auth/google/callback');

// Storage
define('STORAGE_PATH', '/var/www/chat/storage');
define('AVATAR_PATH', STORAGE_PATH . '/avatars');
define('AVATAR_URL_PREFIX', '/storage/avatars');

// Session
define('COOKIE_DOMAIN', '');
define('SESSION_LIFETIME', 30 * 24 * 60 * 60);

// AES key for OAuth token encryption (32 bytes = 256 bits)
// Generate: php -r "echo base64_encode(random_bytes(32));"
define('OAUTH_ENCRYPT_KEY', 'REPLACE_WITH_BASE64_OF_32_RANDOM_BYTES');

// Mail / SMTP
define('SMTP_HOST', 'smtp.example.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'noreply@example.com');
define('SMTP_PASS', 'REPLACE_WITH_SMTP_PASSWORD');
define('MAIL_FROM', 'noreply@example.com');

// Rate limiting (in-memory for HTTP, ReactPHP for WS)
define('MSG_RATE_LIMIT_SEC', 1);
define('WHISPER_RATE_LIMIT_MIN', 5);
define('INVITE_PENDING_MAX', 3);
define('NUMER_IDLE_TIMEOUT', 1800); // seconds before auto-close when 1 participant remains
