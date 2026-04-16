<?php
// Database
define('DB_HOST', 'db');
define('DB_PORT', 3306);
define('DB_NAME', 'chat');
define('DB_USER', 'chat_user');
define('DB_PASS', 'chat_pass');

// Application
define('APP_NAME', 'Chat');
define('APP_URL', 'http://localhost:8080');
// Generate: php -r "echo bin2hex(random_bytes(32));"
define('APP_SECRET', 'REPLACE_WITH_64_HEX_CHARS_GENERATED_ABOVE');

// WebSocket
define('WS_PORT', 8080);
define('WS_HOST', '0.0.0.0');

// VK OAuth 2.0 — vk.com/editapp
define('VK_CLIENT_ID', '');
define('VK_CLIENT_SECRET', '');
define('VK_REDIRECT_URI', APP_URL . '/auth/vk/callback');

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

// Rate limiting (in-memory for HTTP, ReactPHP for WS)
define('MSG_RATE_LIMIT_SEC', 1);
define('WHISPER_RATE_LIMIT_MIN', 5);
define('INVITE_PENDING_MAX', 3);
