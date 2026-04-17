<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Chat\Security\{Session, CSRF};
use Chat\Auth\{LoginHandler, RegisterHandler, VKOAuth, GoogleOAuth};
use Chat\Chat\{RoomController, MessageController, WhisperController};
use Chat\Admin\{AdminPanel, UserManager, RoomManager};

// ─── Router ──────────────────────────────────────────────────────────────────

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path   = '/' . trim($path, '/');

// Serve avatars from storage
if (str_starts_with($path, '/storage/avatars/')) {
    $file = AVATAR_PATH . '/' . basename($path);
    if (is_file($file)) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=604800');
        readfile($file);
    } else {
        http_response_code(404);
    }
    exit;
}

// Auth routes (no session required)
if ($method === 'POST' && $path === '/auth/login') {
    LoginHandler::handle();
}
if ($method === 'POST' && $path === '/auth/register') {
    RegisterHandler::handle();
}
if ($path === '/auth/vk') {
    VKOAuth::redirect();
}
if ($path === '/auth/vk/callback') {
    VKOAuth::callback();
}
if ($path === '/auth/google') {
    GoogleOAuth::redirect();
}
if ($path === '/auth/google/callback') {
    GoogleOAuth::callback();
}

// All routes below require authentication
$user = Session::current();

if ($path === '/auth/logout') {
    if ($user) {
        Session::destroy($_COOKIE['chat_session'] ?? '');
    }
    Session::clearCookie();
    header('Location: /');
    exit;
}

// API routes (authenticated)
if ($user) {
    header('Vary: Accept');

    // Rooms
    if ($method === 'GET' && $path === '/api/rooms') {
        RoomController::list((int)$user['id'], $user['global_role']);
    }
    if ($method === 'GET' && $path === '/api/numera') {
        RoomController::numera((int)$user['id']);
    }
    if ($method === 'POST' && $path === '/api/rooms') {
        RoomController::create((int)$user['id'], $user);
    }

    // Messages
    if ($method === 'GET' && preg_match('~^/api/rooms/(\d+)/messages$~', $path, $m)) {
        $before = isset($_GET['before']) ? (int)$_GET['before'] : null;
        MessageController::history((int)$m[1], (int)$user['id'], $user['global_role'], $before);
    }

    // User profile & settings
    if ($method === 'GET' && preg_match('~^/api/users/(\d+)$~', $path, $m)) {
        UserManager::profile((int)$m[1]);
    }
    if ($method === 'POST' && $path === '/api/settings') {
        UserManager::updateSettings((int)$user['id'], $_POST, $_FILES);
    }

    // Friends
    if ($method === 'GET' && $path === '/api/friends') {
        $db      = \Chat\DB\Connection::getInstance();
        $friends = $db->fetchAll(
            "SELECT u.id, u.username, u.nick_color, u.avatar_url, u.last_seen_at,
                    f.status,
                    (SELECT r.name FROM rooms r
                     JOIN room_members rm2 ON rm2.room_id = r.id AND rm2.user_id = u.id
                     WHERE r.type = 'public' AND r.is_closed = 0 LIMIT 1) AS current_room
             FROM friendships f
             JOIN users u ON u.id = CASE WHEN f.requester_id = ? THEN f.addressee_id ELSE f.requester_id END
             WHERE (f.requester_id = ? OR f.addressee_id = ?) AND f.status = 'accepted'
             ORDER BY u.username",
            [(int)$user['id'], (int)$user['id'], (int)$user['id']]
        );
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'friends' => $friends]);
        exit;
    }
    if ($method === 'POST' && $path === '/api/friends') {
        if (!CSRF::verifyRequest()) { http_response_code(403); echo json_encode(['error'=>'CSRF']); exit; }
        $toId = (int)($_POST['to_user_id'] ?? 0);
        $db   = \Chat\DB\Connection::getInstance();
        $db->execute(
            'INSERT IGNORE INTO friendships (requester_id, addressee_id) VALUES (?, ?)',
            [(int)$user['id'], $toId]
        );
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
    if ($method === 'POST' && preg_match('~^/api/friends/(\d+)/respond$~', $path, $m)) {
        if (!CSRF::verifyRequest()) { http_response_code(403); echo json_encode(['error'=>'CSRF']); exit; }
        $response = $_POST['response'] ?? '';
        $db = \Chat\DB\Connection::getInstance();
        $status = $response === 'accept' ? 'accepted' : 'declined';
        $db->execute(
            'UPDATE friendships SET status = ?, updated_at = NOW() WHERE id = ? AND addressee_id = ?',
            [$status, (int)$m[1], (int)$user['id']]
        );
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }

    // Color contrast check
    if ($method === 'POST' && $path === '/api/color-check') {
        $error = \Chat\Security\ColorContrast::validate($_POST['color'] ?? '');
        $ratios = \Chat\Security\ColorContrast::ratios($_POST['color'] ?? '#ffffff');
        header('Content-Type: application/json');
        echo json_encode(['valid' => !$error, 'error' => $error, 'ratios' => $ratios]);
        exit;
    }

    // Admin routes
    if (str_starts_with($path, '/admin') || str_starts_with($path, '/api/admin')) {
        $admin = AdminPanel::requireAdmin();

        if ($method === 'GET' && $path === '/api/admin/dashboard') {
            AdminPanel::dashboard();
        }
        if ($method === 'GET' && $path === '/api/admin/users') {
            UserManager::list((int)($_GET['page'] ?? 1), $_GET['search'] ?? '');
        }
        if ($method === 'POST' && preg_match('~^/api/admin/users/(\d+)$~', $path, $m)) {
            UserManager::update((int)$m[1], $_POST);
        }
        if ($method === 'DELETE' && preg_match('~^/api/admin/users/(\d+)$~', $path, $m)) {
            UserManager::delete((int)$m[1]);
        }
        if ($method === 'GET' && $path === '/api/admin/rooms') {
            RoomManager::list((int)($_GET['page'] ?? 1));
        }
        if ($method === 'POST' && preg_match('~^/api/admin/rooms/(\d+)/rename$~', $path, $m)) {
            RoomManager::rename((int)$m[1], $_POST['name'] ?? '');
        }
        if ($method === 'DELETE' && preg_match('~^/api/admin/rooms/(\d+)$~', $path, $m)) {
            RoomManager::delete((int)$m[1]);
        }
        if ($method === 'GET' && preg_match('~^/api/admin/rooms/(\d+)/members$~', $path, $m)) {
            RoomManager::members((int)$m[1]);
        }
        if ($method === 'GET' && $path === '/api/admin/numera') {
            RoomManager::numeraArchive((int)($_GET['page'] ?? 1), $_GET);
        }
        if ($method === 'GET' && preg_match('~^/api/admin/numera/(\d+)/messages$~', $path, $m)) {
            RoomManager::numeraMessages((int)$m[1]);
        }
        if ($method === 'GET' && $path === '/api/admin/whispers') {
            WhisperController::archive((int)($_GET['page'] ?? 1), $_GET);
        }
        if ($method === 'GET' && $path === '/api/admin/moderators') {
            AdminPanel::globalModerators();
        }
        if ($method === 'GET' && $path === '/api/admin/room-creators') {
            AdminPanel::roomCreators();
        }
        if ($method === 'GET' && $path === '/api/admin/status-override-settings') {
            AdminPanel::statusOverrideSettings();
        }
        if ($method === 'POST' && $path === '/api/admin/status-override-settings') {
            if (!CSRF::verifyRequest()) { http_response_code(403); echo json_encode(['error' => 'CSRF']); exit; }
            AdminPanel::updateStatusOverrideSettings($admin, $_POST);
        }
    }
}

// ─── CSRF token endpoint ──────────────────────────────────────────────────────
if ($path === '/api/csrf') {
    header('Content-Type: application/json');
    echo json_encode(['token' => CSRF::token()]);
    exit;
}

// ─── Main HTML page ───────────────────────────────────────────────────────────
$nonce = base64_encode(random_bytes(16));
$csrfToken = CSRF::token();
$isLoggedIn = (bool) $user;
$userJson = $user ? json_encode([
    'id'             => (int) $user['id'],
    'username'       => $user['username'],
    'nick_color'     => $user['nick_color'],
    'text_color'     => $user['text_color'],
    'avatar_url'     => $user['avatar_url'],
    'signature'      => $user['signature'],
    'custom_status'  => $user['custom_status'] ?? null,
    'global_role'    => $user['global_role'],
    'can_create_room'=> (bool) $user['can_create_room'],
]) : 'null';

header("Content-Security-Policy: default-src 'self'; script-src 'self' cdn.jsdelivr.net cdnjs.cloudflare.com 'nonce-$nonce'; style-src 'self' cdn.jsdelivr.net cdnjs.cloudflare.com 'nonce-$nonce'; img-src * data:; connect-src 'self' ws: wss:; font-src cdn.jsdelivr.net cdnjs.cloudflare.com;");
?><!DOCTYPE html>
<html lang="ru" data-bs-theme="auto">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#212529">
<title><?= htmlspecialchars(APP_NAME, ENT_QUOTES) ?></title>
<link rel="manifest" href="/manifest.json">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style nonce="<?= $nonce ?>">
:root { --sidebar-w: 260px; --right-panel-w: 220px; }
body { overflow: hidden; height: 100vh; }
.chat-layout { display: flex; height: 100vh; }

/* Left sidebar */
#sidebar-left { width: var(--sidebar-w); min-width: var(--sidebar-w); display: flex; flex-direction: column; border-right: 1px solid var(--bs-border-color); background: var(--bs-body-bg); overflow: hidden; }
#sidebar-left .sidebar-header { padding: 12px 16px; border-bottom: 1px solid var(--bs-border-color); font-weight: 700; font-size: 1.1rem; }
#sidebar-left .sidebar-section { padding: 8px 16px 4px; font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: var(--bs-secondary-color); }
.room-item { display: flex; align-items: center; padding: 6px 16px; cursor: pointer; border-radius: 0; transition: background .15s; }
.room-item:hover, .room-item.active { background: var(--bs-secondary-bg); }
.room-item .room-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .92rem; }
.friend-item { display: flex; align-items: center; gap: 8px; padding: 4px 16px; font-size: .88rem; }
.online-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.online-dot.online { background: #28a745; }
.online-dot.offline { background: #6c757d; }
#sidebar-left .sidebar-bottom { margin-top: auto; padding: 12px 16px; border-top: 1px solid var(--bs-border-color); display: flex; align-items: center; gap: 10px; }

/* Main chat */
#chat-main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
#chat-header { padding: 10px 16px; border-bottom: 1px solid var(--bs-border-color); display: flex; align-items: center; gap: 10px; }
#messages-container { flex: 1; overflow-y: auto; padding: 12px 16px; display: flex; flex-direction: column; gap: 2px; }
.msg { padding: 4px 0; }
.msg-body { min-width: 0; }
.msg-meta { font-size: .8rem; color: var(--bs-secondary-color); display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
.msg-username { font-weight: 600; }
.msg-content { font-size: .93rem; word-break: break-word; padding-left: 0; display: inline; }
.msg-inline-content { color: inherit; }
.msg-inline-content * { color: inherit !important; }
.msg-system { text-align: center; font-style: italic; color: var(--bs-secondary-color); font-size: .82rem; padding: 2px 0; }
.msg-whisper { background: rgba(108,117,125,.08); border-left: 3px solid #6c757d; padding: 4px 10px; border-radius: 0 6px 6px 0; font-style: italic; color: var(--bs-secondary-color); }
.msg-delete-btn { opacity: 0; font-size: .75rem; color: var(--bs-secondary-color); cursor: pointer; }
.msg:hover .msg-delete-btn { opacity: 1; }
.scroll-bottom-btn { position: absolute; bottom: 90px; right: 240px; z-index: 10; border-radius: 20px; display: none; }

/* Input area */
#input-area { border-top: 1px solid var(--bs-border-color); padding: 10px 16px; }
#whisper-bar { display: none; padding: 4px 10px; background: var(--bs-secondary-bg); border-radius: 6px; margin-bottom: 6px; font-size: .85rem; }
.md-toolbar { display: flex; gap: 4px; margin-bottom: 4px; }
.md-btn { border: 1px solid var(--bs-border-color); background: transparent; color: var(--bs-body-color); border-radius: 4px; padding: 1px 8px; font-size: .82rem; cursor: pointer; }
.md-btn:hover { background: var(--bs-secondary-bg); }
.char-counter { font-size: .75rem; color: var(--bs-secondary-color); text-align: right; }
.char-counter.over { color: #dc3545; }

/* Right panel */
#panel-right { width: var(--right-panel-w); min-width: var(--right-panel-w); border-left: 1px solid var(--bs-border-color); overflow-y: auto; background: var(--bs-body-bg); }
#panel-right .panel-header { padding: 10px 12px; border-bottom: 1px solid var(--bs-border-color); font-size: .85rem; font-weight: 600; }
.online-user { padding: 10px 12px; display: grid; grid-template-columns: 42px 1fr; gap: 8px 10px; align-items: start; }
.online-user:hover { background: var(--bs-secondary-bg); }
.online-user-avatar { position: relative; width: 42px; cursor: pointer; }
.online-user-avatar img { width: 42px; height: 42px; border-radius: 50%; object-fit: cover; display: block; }
.online-user-main { min-width: 0; cursor: pointer; }
.online-user-name { font-size: .9rem; font-weight: 600; line-height: 1.2; }
.online-user-role { margin-top: 4px; }
.online-user-actions { grid-column: 1 / span 2; display: flex; gap: 6px; padding-top: 2px; }
.user-action-btn { width: 28px; height: 28px; border: 1px solid var(--bs-border-color); background: transparent; border-radius: 6px; color: var(--bs-secondary-color); display: inline-flex; align-items: center; justify-content: center; }
.user-action-btn:hover { background: var(--bs-tertiary-bg); color: var(--bs-body-color); }

/* Embeds */
.embed-yt { position: relative; display: inline-block; max-width: 300px; }
.embed-yt-thumb { max-width: 100%; border-radius: 6px; }
.embed-yt-play { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%); font-size: 3rem; color: rgba(255,255,255,.9); pointer-events: none; }
.embed-image { max-width: 300px; max-height: 300px; border-radius: 6px; object-fit: cover; }
.embed-og { border: 1px solid var(--bs-border-color); border-radius: 8px; overflow: hidden; max-width: 360px; }
.embed-og a { display: flex; text-decoration: none; color: inherit; }
.embed-og-image { width: 80px; height: 80px; object-fit: cover; }
.embed-og-body { padding: 8px; }
.embed-og-title { font-weight: 600; font-size: .9rem; }
.embed-og-desc { font-size: .8rem; color: var(--bs-secondary-color); }

/* Auth page */
#auth-page { display: flex; align-items: center; justify-content: center; height: 100vh; }
.auth-card { width: 100%; max-width: 400px; }

/* Responsive */
@media (max-width: 768px) {
    #sidebar-left { position: fixed; left: -100%; top: 0; height: 100vh; z-index: 1050; transition: left .3s; }
    #sidebar-left.show { left: 0; }
    #panel-right { display: none; }
    .scroll-bottom-btn { right: 16px; }
}

/* Color preview */
.color-preview-box { width: 80px; height: 40px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; font-size: .8rem; font-weight: 600; }
</style>
</head>
<body>
<?php if (!$isLoggedIn): ?>
<!-- ═══════════════════ AUTH PAGE ═══════════════════ -->
<div id="auth-page">
  <div class="auth-card p-4 shadow rounded">
    <h4 class="text-center mb-4"><i class="fa fa-comments me-2"></i><?= htmlspecialchars(APP_NAME, ENT_QUOTES) ?></h4>

    <?php if (!empty($_GET['auth_error'])): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($_GET['auth_error'], ENT_QUOTES) ?></div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-3" id="authTabs">
      <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#loginTab">Войти</a></li>
      <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#registerTab">Регистрация</a></li>
    </ul>

    <div class="tab-content">
      <!-- Login -->
      <div class="tab-pane fade show active" id="loginTab">
        <form id="loginForm">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
          <div class="mb-3"><label class="form-label">Email</label>
            <input type="text" class="form-control" name="email" required></div>
          <div class="mb-3"><label class="form-label">Пароль</label>
            <input type="password" class="form-control" name="password" required></div>
          <button type="submit" class="btn btn-primary w-100">Войти</button>
        </form>
        <hr>
        <div class="d-grid gap-2">
          <a href="/auth/vk" class="btn btn-outline-primary"><i class="fab fa-vk me-2"></i>Войти через VK</a>
          <a href="/auth/google" class="btn btn-outline-danger"><i class="fab fa-google me-2"></i>Войти через Google</a>
        </div>
        <div id="loginError" class="alert alert-danger mt-2 d-none"></div>
      </div>
      <!-- Register -->
      <div class="tab-pane fade" id="registerTab">
        <form id="registerForm">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
          <div class="mb-3"><label class="form-label">Имя пользователя</label>
            <input type="text" class="form-control" name="username" minlength="3" maxlength="50" required></div>
          <div class="mb-3"><label class="form-label">Email (необязательно)</label>
            <input type="email" class="form-control" name="email"></div>
          <div class="mb-3"><label class="form-label">Пароль (мин. 8 символов)</label>
            <input type="password" class="form-control" name="password" minlength="8" required></div>
          <button type="submit" class="btn btn-success w-100">Зарегистрироваться</button>
        </form>
        <div id="registerError" class="alert alert-danger mt-2 d-none"></div>
      </div>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ═══════════════════ CHAT APP ═══════════════════ -->
<div class="chat-layout">

  <!-- ─── LEFT SIDEBAR ─── -->
  <div id="sidebar-left">
    <div class="sidebar-header d-flex align-items-center justify-content-between">
      <span><i class="fa fa-comments me-2 text-primary"></i><?= htmlspecialchars(APP_NAME, ENT_QUOTES) ?></span>
      <button id="themeToggle" class="btn btn-sm btn-outline-secondary" title="Сменить тему"><i class="fa fa-moon"></i></button>
    </div>

    <div class="flex-1 overflow-y-auto">
      <div class="sidebar-section">Комнаты</div>
      <div id="rooms-list"></div>

      <div class="sidebar-section mt-2">Мои нумера</div>
      <div id="numera-list"></div>

      <div class="sidebar-section mt-2">Друзья</div>
      <div class="px-3 pb-1">
        <input type="text" class="form-control form-control-sm" id="friend-search" placeholder="Поиск друзей...">
      </div>
      <div id="friends-list"></div>
    </div>

    <div class="sidebar-bottom">
      <img id="my-avatar" src="/assets/avatar-default.svg" alt="" class="rounded-circle" width="32" height="32" style="object-fit:cover">
      <div class="flex-1 overflow-hidden">
        <div id="my-username" class="fw-semibold text-truncate" style="font-size:.9rem"></div>
        <div id="my-role-badge" class="badge bg-secondary" style="font-size:.68rem"></div>
      </div>
      <a href="/auth/logout" class="btn btn-sm btn-outline-danger" title="Выйти"><i class="fa fa-sign-out-alt"></i></a>
    </div>
  </div>

  <!-- ─── MAIN CHAT ─── -->
  <div id="chat-main">
    <div id="chat-header">
      <button class="btn btn-sm btn-outline-secondary d-md-none" id="toggleSidebar"><i class="fa fa-bars"></i></button>
      <div id="room-title" class="fw-bold flex-1">Выберите комнату</div>
      <div id="room-online-count" class="text-muted small"></div>
      <button id="room-manage-btn" class="btn btn-sm btn-outline-secondary d-none" title="Управление"><i class="fa fa-cog"></i></button>
      <?php if (in_array($user['global_role'], ['platform_owner', 'admin'], true)): ?>
      <a href="#" id="admin-btn" class="btn btn-sm btn-outline-warning" title="Администрирование"><i class="fa fa-shield-alt"></i></a>
      <?php endif; ?>
    </div>

    <div id="messages-container">
      <div id="load-more-btn-wrap" class="text-center d-none py-2">
        <button id="load-more-btn" class="btn btn-sm btn-outline-secondary">Загрузить ещё</button>
      </div>
      <div id="messages-list"></div>
    </div>

    <button id="scroll-bottom-btn" class="btn btn-primary scroll-bottom-btn">
      <i class="fa fa-arrow-down me-1"></i> Новые сообщения
    </button>

    <div id="input-area">
      <div id="whisper-bar">
        <i class="fa fa-user-secret me-1"></i> Шёпот для <strong id="whisper-target-name"></strong>
        <button type="button" class="btn-close btn-sm float-end" id="cancel-whisper"></button>
      </div>
      <div class="md-toolbar">
        <button class="md-btn" data-md="**" title="Жирный"><b>B</b></button>
        <button class="md-btn" data-md="_" title="Курсив"><i>I</i></button>
        <button class="md-btn" data-md="__" title="Подчёркнутый"><u>U</u></button>
        <button class="md-btn" data-md="~~" title="Зачёркнутый"><s>S</s></button>
      </div>
      <textarea id="msg-input" class="form-control" rows="1" placeholder="Сообщение..." maxlength="2000" style="resize:none"></textarea>
      <div class="d-flex justify-content-between align-items-center mt-1">
        <div class="char-counter"><span id="char-count">0</span>/2000</div>
        <button id="send-btn" class="btn btn-primary btn-sm" disabled><i class="fa fa-paper-plane"></i></button>
      </div>
    </div>
  </div>

  <!-- ─── RIGHT PANEL ─── -->
  <div id="panel-right">
    <div class="panel-header">
      Онлайн в комнате <span id="panel-online-count" class="badge bg-secondary ms-1">0</span>
    </div>
    <div id="online-users-list"></div>
  </div>
</div>

<!-- ─── Modals ─── -->

<!-- Context menu -->
<div id="ctx-menu" class="dropdown-menu shadow" style="position:fixed;display:none;z-index:2000"></div>

<!-- Room manage modal -->
<div class="modal fade" id="roomManageModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Управление комнатой</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="room-manage-body"></div>
  </div></div>
</div>

<!-- Settings modal -->
<div class="modal fade" id="settingsModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Настройки профиля</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <form id="settingsForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Имя пользователя</label>
            <input type="text" class="form-control" name="username" minlength="3" maxlength="50">
          </div>
          <div class="col-md-6">
            <label class="form-label">Подпись (до 300 символов)</label>
            <textarea class="form-control" name="signature" maxlength="300" rows="2"></textarea>
          </div>
          <div class="col-md-6">
            <label class="form-label">Отображаемый статус (до 80 символов)</label>
            <input type="text" class="form-control" name="custom_status" maxlength="80" placeholder="Например: В отпуске">
          </div>
          <div class="col-md-6">
            <label class="form-label">Цвет ника</label>
            <div class="d-flex gap-2 align-items-center">
              <input type="color" class="form-control form-control-color" name="nick_color" id="nickColorPicker">
              <div>
                <div class="color-preview-box" id="nick-preview-light" style="background:#f8f9fa">Ник</div>
                <div class="color-preview-box" id="nick-preview-dark" style="background:#212529;margin-top:2px">Ник</div>
              </div>
              <div id="nick-color-feedback" class="small"></div>
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Цвет текста</label>
            <div class="d-flex gap-2 align-items-center">
              <input type="color" class="form-control form-control-color" name="text_color" id="textColorPicker">
              <div>
                <div class="color-preview-box" id="text-preview-light" style="background:#f8f9fa">Текст</div>
                <div class="color-preview-box" id="text-preview-dark" style="background:#212529;margin-top:2px">Текст</div>
              </div>
              <div id="text-color-feedback" class="small"></div>
            </div>
          </div>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="showSystemMessagesSetting">
              <label class="form-check-label" for="showSystemMessagesSetting">Показывать сервисные сообщения в чате</label>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label">Аватар — загрузить файл (JPEG/PNG/GIF/WEBP ≤2MB)</label>
            <input type="file" class="form-control" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp">
          </div>
          <div class="col-12">
            <label class="form-label">или URL аватара</label>
            <input type="url" class="form-control" name="avatar_url" placeholder="https://...">
          </div>
          <div class="col-12">
            <label class="form-label">Новый пароль (оставьте пустым чтобы не менять)</label>
            <input type="password" class="form-control" name="password" minlength="8">
          </div>
        </div>
        <div id="settings-error" class="alert alert-danger mt-3 d-none"></div>
        <div id="settings-success" class="alert alert-success mt-3 d-none">Настройки сохранены.</div>
        <button type="submit" class="btn btn-primary mt-3" id="settings-save-btn">Сохранить</button>
      </form>
    </div>
  </div></div>
</div>

<!-- Admin modal -->
<div class="modal fade" id="adminModal" tabindex="-1">
  <div class="modal-dialog modal-xl"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="fa fa-shield-alt me-2"></i>Администрирование</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <ul class="nav nav-tabs mb-3" id="adminTabs">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#adminDash">Обзор</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#adminUsers">Пользователи</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#adminRooms">Комнаты</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#adminNumera">Нумера</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#adminWhispers">Шёпот</a></li>
      </ul>
      <div class="tab-content">
        <div class="tab-pane fade show active" id="adminDash">
          <div class="row g-3" id="admin-stats"></div>
        </div>
        <div class="tab-pane fade" id="adminUsers">
          <div class="mb-2 d-flex gap-2">
            <input type="text" class="form-control form-control-sm" id="admin-user-search" placeholder="Поиск...">
            <button class="btn btn-sm btn-outline-primary" id="admin-user-search-btn">Найти</button>
          </div>
          <div id="admin-users-table"></div>
        </div>
        <div class="tab-pane fade" id="adminRooms">
          <div id="admin-rooms-table"></div>
        </div>
        <div class="tab-pane fade" id="adminNumera">
          <div id="admin-numera-table"></div>
        </div>
        <div class="tab-pane fade" id="adminWhispers">
          <div class="mb-2 d-flex gap-2">
            <input type="text" class="form-control form-control-sm" id="whisper-filter-from" placeholder="От пользователя">
            <input type="text" class="form-control form-control-sm" id="whisper-filter-to" placeholder="Кому">
            <button class="btn btn-sm btn-outline-primary" id="whisper-search-btn">Найти</button>
          </div>
          <div id="admin-whispers-table"></div>
        </div>
      </div>
    </div>
  </div></div>
</div>

<!-- Invite modal -->
<div class="modal fade" id="inviteModal" tabindex="-1">
  <div class="modal-dialog modal-sm"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Приглашение в нумер</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="invite-modal-body"></div>
  </div></div>
</div>

<?php endif; ?>

<script nonce="<?= $nonce ?>" src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script nonce="<?= $nonce ?>" src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script nonce="<?= $nonce ?>" src="https://cdn.jsdelivr.net/npm/dayjs@1.11.10/dayjs.min.js"></script>
<script nonce="<?= $nonce ?>" src="https://cdn.jsdelivr.net/npm/dayjs@1.11.10/locale/ru.js"></script>
<script nonce="<?= $nonce ?>" src="https://cdn.jsdelivr.net/npm/dayjs@1.11.10/plugin/relativeTime.js"></script>
<script nonce="<?= $nonce ?>" src="https://cdn.jsdelivr.net/npm/autosize@6.0.1/dist/autosize.min.js"></script>
<script nonce="<?= $nonce ?>">
dayjs.locale('ru');
dayjs.extend(dayjs_plugin_relativeTime);

const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
const CURRENT_USER = <?= $userJson ?>;

<?php if ($isLoggedIn): ?>
// ════════════════════════════════════════════════
//  THEME
// ════════════════════════════════════════════════
(function() {
  const saved = localStorage.getItem('theme');
  const preferred = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  document.documentElement.setAttribute('data-bs-theme', saved || preferred);
})();

$('#themeToggle').on('click', function() {
  const curr = document.documentElement.getAttribute('data-bs-theme');
  const next = curr === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-bs-theme', next);
  localStorage.setItem('theme', next);
  $(this).find('i').toggleClass('fa-moon fa-sun');
});

// ════════════════════════════════════════════════
//  STATE
// ════════════════════════════════════════════════
let ws = null;
let currentRoomId = null;
let currentRoomRole = null;
let currentOnlineUsers = [];
let whisperToId   = null;
let whisperToName = null;
let isScrolledToBottom = true;
let oldestMessageId = null;
let rooms = [];
let numera = [];
const ignoredUserIds = new Set();
const pendingInviteRooms = new Map();
const DEFAULT_AVATAR_URL = '/assets/avatar-default.svg';

// ════════════════════════════════════════════════
//  INIT
// ════════════════════════════════════════════════
$(function() {
  initUser();
  loadRooms();
  loadFriends();
  connectWS();
  initInput();
  initSettings();
  initSidebar();
  initAdmin();

  // Auto-size textarea
  autosize($('#msg-input'));

  // Scroll tracking
  $('#messages-container').on('scroll', function() {
    const el = this;
    isScrolledToBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 60;
    $('#scroll-bottom-btn').toggle(!isScrolledToBottom);
  });
  $('#scroll-bottom-btn').on('click', scrollToBottom);

  // Mobile sidebar
  $('#toggleSidebar').on('click', () => $('#sidebar-left').toggleClass('show'));
});

// ════════════════════════════════════════════════
//  USER INIT
// ════════════════════════════════════════════════
function initUser() {
  if (!CURRENT_USER) return;
  $('#my-username').text(CURRENT_USER.username).css('color', CURRENT_USER.nick_color);
  $('#my-role-badge').text(displayStatusLabel(CURRENT_USER));
  $('#my-avatar').attr('src', CURRENT_USER.avatar_url || DEFAULT_AVATAR_URL);
  $('#my-avatar').off('error').on('error', function(){ this.onerror = null; this.src = DEFAULT_AVATAR_URL; });
}


// ════════════════════════════════════════════════
//  WEBSOCKET
// ════════════════════════════════════════════════
function connectWS() {
  const proto = location.protocol === 'https:' ? 'wss' : 'ws';
  ws = new WebSocket(`${proto}://${location.host}/wss`);

  ws.onopen = () => console.log('[WS] Connected');

  ws.onmessage = (e) => {
    let data;
    try { data = JSON.parse(e.data); } catch { return; }
    handleWS(data);
  };

  ws.onclose = () => {
    console.log('[WS] Disconnected, reconnecting in 3s...');
    setTimeout(connectWS, 3000);
  };

  ws.onerror = (err) => console.error('[WS] Error', err);

  // Ping every 30s
  setInterval(() => { if (ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify({event:'ping'})); }, 30000);
}

function wsSend(event, data = {}) {
  if (ws && ws.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify({event, ...data}));
  }
}

function handleWS(data) {
  switch (data.event) {
    case 'connected':       onWSConnected(data); break;
    case 'room_joined':     onRoomJoined(data); break;
    case 'user_joined':     onUserJoined(data); break;
    case 'user_left':       onUserLeft(data); break;
    case 'new_message':     onNewMessage(data.message); break;
    case 'message_deleted': onMessageDeleted(data); break;
    case 'system_message':  onSystemMessage(data.message); break;
    case 'whisper_sent':    onWhisperMessage(data.message, true); break;
    case 'whisper_received':onWhisperMessage(data.message, false); break;
    case 'invite_received': onInviteReceived(data.invitation); break;
    case 'invite_sent':     onInviteSent(data.invitation); break;
    case 'invite_accepted': onInviteAccepted(data); break;
    case 'invite_declined': onInviteDeclined(data); break;
    case 'invite_expired':  onInviteExpired(data); break;
    case 'invite_accepted': showToast('Приглашение принято: ' + (data.user?.username || '')); break;
    case 'invite_declined': showToast('Приглашение отклонено.'); break;
    case 'invite_expired':  showToast('Приглашение истекло.'); break;
    case 'numer_joined':    onNumerJoined(data); break;
    case 'numer_participant_joined': onNumerParticipantJoined(data); break;
    case 'numer_participant_left':   onNumerParticipantLeft(data); break;
    case 'numer_owner_changed': onNumerOwnerChanged(data); break;
    case 'numer_destroyed': onNumerDestroyed(data); break;
    case 'kicked_from_room': onKickedFromRoom(data); break;
    case 'banned_from_room': onKickedFromRoom(data); break;
    case 'room_deleted':     onRoomDeleted(data); break;
    case 'room_updated':     loadRooms(); break;
    case 'friend_online':
    case 'friend_offline':  loadFriends(); break;
    case 'error':           showToast(data.message, 'danger'); break;
    case 'pong':            break;
  }
}

function onWSConnected(data) {
  if (currentRoomId) {
    wsSend('join_room', {room_id: currentRoomId});
  }
}

// ════════════════════════════════════════════════
//  ROOMS
// ════════════════════════════════════════════════
function loadRooms() {
  $.get('/api/rooms', function(resp) {
    if (!resp.success) return;
    rooms = resp.rooms;
    const $list = $('#rooms-list').empty();
    rooms.forEach(r => {
      const $item = $(`<div class="room-item" data-id="${r.id}"><span class="room-name">${esc(r.name)}</span></div>`);
      if (r.id === currentRoomId) $item.addClass('active');
      $item.on('click', () => joinRoom(r.id));
      $list.append($item);
    });
    if (!currentRoomId && rooms.length > 0) {
      joinRoom(rooms[0].id);
    }
  });
  $.get('/api/numera', function(resp) {
    if (!resp.success) return;
    numera = resp.numera;
    const $list = $('#numera-list').empty();
    numera.forEach(r => {
      const $item = $(`<div class="room-item" data-id="${r.id}"><span class="room-name"><i class="fa fa-lock me-1"></i>${esc(r.name)}</span></div>`);
      if (r.id === currentRoomId) $item.addClass('active');
      $item.on('click', () => joinRoom(r.id, true));
      $list.append($item);
    });
  });
}

function joinRoom(roomId, isNumer) {
  if (currentRoomId === roomId) return;
  if (currentRoomId) wsSend('leave_room', {room_id: currentRoomId});
  currentRoomId = roomId;
  currentRoomRole = null;
  oldestMessageId = null;
  clearWhisperMode();
  $('#messages-list').empty();
  $('#load-more-btn-wrap').addClass('d-none');

  $('.room-item').removeClass('active');
  $(`.room-item[data-id="${roomId}"]`).addClass('active');

  loadHistory(roomId);
  wsSend('join_room', {room_id: roomId});
}

function loadHistory(roomId, before) {
  let url = `/api/rooms/${roomId}/messages`;
  if (before) url += `?before=${before}`;
  $.get(url, function(resp) {
    if (!resp.success) return;
    const msgs = resp.messages;
    if (msgs.length === 0) { $('#load-more-btn-wrap').addClass('d-none'); return; }
    if (msgs.length === 50) $('#load-more-btn-wrap').removeClass('d-none');
    if (msgs.length > 0) oldestMessageId = msgs[0].id;

    if (before) {
      const $list = $('#messages-list');
      const prevScrollH = $('#messages-container')[0].scrollHeight;
      msgs.forEach(m => {
        if (!shouldRenderMessage(m)) return;
        const html = buildMessage(m);
        if (html) $list.prepend(html);
      });
      const newScrollH = $('#messages-container')[0].scrollHeight;
      $('#messages-container').scrollTop(newScrollH - prevScrollH);
    } else {
      msgs.forEach(m => appendMessage(m));
      scrollToBottom();
    }
  });
}

$('#load-more-btn').on('click', function() {
  if (currentRoomId && oldestMessageId) {
    loadHistory(currentRoomId, oldestMessageId);
  }
});

function onRoomJoined(data) {
  const room = rooms.find(r => r.id === data.room_id) || numera.find(r => r.id === data.room_id) || {name: 'Комната'};
  currentRoomRole = data.my_role || null;
  $('#room-title').text(room.name || 'Комната');
  renderOnlineList(data.online || []);
  $('#panel-online-count').text((data.online || []).length);
  $('#room-online-count').text(`${(data.online || []).length} онлайн`);

  const canManage = ['platform_owner', 'admin'].includes(CURRENT_USER.global_role) || ['owner', 'local_admin', 'local_moderator'].includes(data.my_role);
  $('#room-manage-btn').toggleClass('d-none', !canManage);
  $('#send-btn').prop('disabled', $('#msg-input').val().trim().length === 0);
}

function onUserJoined(data) {
  if (data.room_id !== currentRoomId) return;
  addToOnlineList(data.user);
}

function onUserLeft(data) {
  if (data.room_id !== currentRoomId) return;
  removeFromOnlineList(data.user_id);
}

// ════════════════════════════════════════════════
//  MESSAGES
// ════════════════════════════════════════════════
function buildMessage(m) {
  if (m.type === 'system') {
    return shouldShowSystemMessages() ? `<div class="msg-system">${esc(m.content)}</div>` : '';
  }

  const time = dayjs(m.created_at).format('HH:mm:ss');
  const canDelete = canDeleteMessage(m);
  const deleteBtn = canDelete ? `<span class="msg-delete-btn" data-id="${m.id}" title="Удалить"><i class="fa fa-trash"></i></span>` : '';

  let embed = '';
  if (m.embed_data) {
    const ed = typeof m.embed_data === 'string' ? JSON.parse(m.embed_data) : m.embed_data;
    embed = `<div class="mt-1">${ed.html || ''}</div>`;
  }

  return `<div class="msg" id="msg-${m.id}">
    <div class="msg-body">
      <div class="msg-meta">
        <span class="msg-username" style="color:${esc(m.nick_color || 'inherit')}">${esc(m.username)}</span>
        <span>${time}</span>
        <span class="msg-content msg-inline-content" style="color:${esc(m.text_color || 'inherit')} !important">${m.content}</span>
        ${deleteBtn}
      </div>
      ${embed}
    </div>
  </div>`;
}

function buildWhisperMessage(m, isSent) {
  const time = dayjs(m.created_at).format('HH:mm:ss');
  const from = m.from || {};
  const to   = m.to   || {};
  const label = isSent
    ? `🤫 шёпот для <strong>@${esc(to.username || '')}</strong>`
    : `🤫 шёпот от <strong>@${esc(from.username || '')}</strong>`;
  return `<div class="msg" id="msg-${m.message_id}">
    <div class="msg-whisper flex-1">
      <div class="msg-meta small">${label} · ${time}</div>
      <div class="msg-content">${m.content}</div>
    </div>
  </div>`;
}

function appendMessage(m) {
  if (!shouldRenderMessage(m)) return;
  const html = buildMessage(m);
  if (!html) return;
  const $container = $('#messages-container');
  const atBottom = isScrolledToBottom;
  $('#messages-list').append(html);
  if (atBottom) {
    scrollToBottom();
  } else {
    $('#scroll-bottom-btn').show();
  }
}

function onNewMessage(m) {
  if (m.room_id !== currentRoomId) return;
  appendMessage(m);
}

function onMessageDeleted(data) {
  $(`#msg-${data.message_id}`).fadeOut(200, function() { $(this).remove(); });
}

function onSystemMessage(m) {
  if (m.scope === 'staff_call') {
    showToast(m.content || 'Вызов персонала.', 'warning');
    if (m.room_id !== currentRoomId || !shouldShowSystemMessages()) return;
  } else if (m.room_id !== currentRoomId) {
    return;
  }
  if (!shouldShowSystemMessages()) return;
  $('#messages-list').append(`<div class="msg-system">${esc(m.content)}</div>`);
  if (isScrolledToBottom) scrollToBottom();
}

function onWhisperMessage(m, isSent) {
  if (m.room_id !== currentRoomId) return;
  $('#messages-list').append(buildWhisperMessage(m, isSent));
  if (isScrolledToBottom) scrollToBottom();
}

function shouldRenderMessage(m) {
  if (!m || m.type === 'system') return true;
  const userId = Number(m.user_id || 0);
  return !ignoredUserIds.has(userId) || userId === Number(CURRENT_USER.id);
}

function canDeleteMessage(m) {
  if (['platform_owner', 'admin', 'moderator'].includes(CURRENT_USER.global_role)) return true;
  if (m.user_id === CURRENT_USER.id) return true;
  return ['owner', 'local_admin', 'local_moderator'].includes(currentRoomRole);
}

function shouldShowSystemMessages() {
  return localStorage.getItem('show_system_messages') !== '0';
}

function avatarMarkup(url, size = 42) {
  return `<img src="${esc(url || DEFAULT_AVATAR_URL)}" width="${size}" height="${size}" alt="" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src='${DEFAULT_AVATAR_URL}'">`;
}

function visibleRoleLabel(u) {
  if (u && Number(u.id) === Number(CURRENT_USER.id) && CURRENT_USER.custom_status) return String(CURRENT_USER.custom_status);
  if (u.custom_status) return String(u.custom_status);
  if (u.room_role && !['member', 'banned'].includes(u.room_role)) return roomRoleLabel(u.room_role);
  if (u.global_role && u.global_role !== 'user') return roleLabel(u.global_role);
  return '';
}

function displayStatusLabel(u) {
  if (u && u.custom_status) return String(u.custom_status);
  if (u && u.global_role) return roleLabel(u.global_role);
  return '';
}

function visibleRoleClass(u) {
  if (u.room_role && !['member', 'banned'].includes(u.room_role)) return 'bg-info';
  if (u.global_role === 'platform_owner') return 'bg-dark';
  if (u.global_role === 'admin') return 'bg-danger';
  if (u.global_role === 'moderator') return 'bg-warning text-dark';
  return 'bg-secondary';
}

function appendInputToken(token) {
  const $input = $('#msg-input');
  const base = $input.val();
  const normalizedBase = String(base || '');
  if (normalizedBase.endsWith(token)) {
    $input.focus();
    return;
  }
  const next = normalizedBase ? `${normalizedBase}${normalizedBase.endsWith(' ') ? '' : ' '}${token}` : token;
  $input.val(next).trigger('input').focus();
}

function insertDirectAddress(username) {
  appendInputToken(`${username}, `);
}

function insertWhisperTarget(username) {
  appendInputToken(`@p+${username} `);
}

// Delete message
$('#messages-list').on('click', '.msg-delete-btn', function() {
  const msgId = $(this).data('id');
  if (!confirm('Удалить сообщение?')) return;
  wsSend('delete_message', {message_id: msgId});
});

// ════════════════════════════════════════════════
//  INPUT & SEND
// ════════════════════════════════════════════════
function initInput() {
  const $input = $('#msg-input');
  const $send  = $('#send-btn');

  $input.on('input', function() {
    const len = $(this).val().length;
    $('#char-count').text(len);
    $('.char-counter').toggleClass('over', len > 2000);
    $send.prop('disabled', len === 0 || len > 2000 || !currentRoomId);
    autosize.update(this);
  });

  $input.on('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  });

  $send.on('click', sendMessage);

  // Markdown toolbar
  $('.md-btn').on('click', function() {
    const md = $(this).data('md');
    wrapSelection($input[0], md);
    $input.trigger('input');
  });
  // Cancel whisper
  $('#cancel-whisper').on('click', clearWhisperMode);
}

function sendMessage() {
  const content = $('#msg-input').val().trim();
  if (!content || !currentRoomId) return;

  if (whisperToId) {
    wsSend('send_whisper', {
      room_id:     currentRoomId,
      to_user_id:  whisperToId,
      content:     content,
    });
    clearWhisperMode();
  } else {
    wsSend('send_message', {
      room_id: currentRoomId,
      content: content,
    });
  }

  $('#msg-input').val('').trigger('input');
  autosize.update(document.getElementById('msg-input'));
}

function wrapSelection(el, marker) {
  const start = el.selectionStart;
  const end   = el.selectionEnd;
  const val   = el.value;
  const sel   = val.slice(start, end);
  el.value = val.slice(0, start) + marker + sel + marker + val.slice(end);
  el.selectionStart = start + marker.length;
  el.selectionEnd   = end + marker.length;
  el.focus();
}


// ════════════════════════════════════════════════
//  WHISPER MODE
// ════════════════════════════════════════════════
function activateWhisperMode(userId, username) {
  whisperToId   = userId;
  whisperToName = username;
  $('#whisper-target-name').text('@' + username);
  $('#whisper-bar').show();
  $('#msg-input').attr('placeholder', 'Шёпот для @' + username + '...').focus();
}

function clearWhisperMode() {
  whisperToId   = null;
  whisperToName = null;
  $('#whisper-bar').hide();
  $('#msg-input').attr('placeholder', 'Сообщение...');
}

// ════════════════════════════════════════════════
//  ONLINE USERS LIST
// ════════════════════════════════════════════════
function renderOnlineList(users) {
  currentOnlineUsers = users.slice();
  const $list = $('#online-users-list').empty();
  $('#panel-online-count').text(users.length);
  $('#room-online-count').text(`${users.length} онлайн`);
  users.forEach(u => $list.append(buildOnlineUser(u)));
}

function addToOnlineList(u) {
  if ($(`#online-user-${u.id}`).length) return;
  currentOnlineUsers = currentOnlineUsers.filter(item => item.id !== u.id).concat([u]);
  $('#online-users-list').append(buildOnlineUser(u));
  const cnt = Math.max(0, currentOnlineUsers.length);
  $('#panel-online-count').text(cnt);
  $('#room-online-count').text(`${cnt} онлайн`);
}

function removeFromOnlineList(userId) {
  currentOnlineUsers = currentOnlineUsers.filter(item => Number(item.id) !== Number(userId));
  $(`#online-user-${userId}`).remove();
  const cnt = Math.max(0, currentOnlineUsers.length);
  $('#panel-online-count').text(cnt);
  $('#room-online-count').text(`${cnt} онлайн`);
}

function buildOnlineUser(u) {
  const role = visibleRoleLabel(u);
  const roleBadge = role ? `<span class="badge ${visibleRoleClass(u)}" style="font-size:.65rem">${role}</span>` : '';
  const ignored = ignoredUserIds.has(Number(u.id));
  return `<div class="online-user" id="online-user-${u.id}" data-id="${u.id}" data-username="${esc(u.username)}">
    <div class="online-user-avatar" data-action="mention">${avatarMarkup(u.avatar_url, 42)}</div>
    <div class="online-user-main" data-action="mention">
      <div class="online-user-name" style="color:${esc(u.nick_color || 'inherit')}">${esc(u.username)}</div>
      <div class="online-user-role">${roleBadge}</div>
    </div>
    <div class="online-user-actions">
      <button type="button" class="user-action-btn" title="Личное обращение" data-action="mention" data-id="${u.id}" data-name="${esc(u.username)}"><i class="fa fa-at"></i></button>
      <button type="button" class="user-action-btn" title="Шёпот" data-action="whisper" data-id="${u.id}" data-name="${esc(u.username)}"><i class="fa fa-user-secret"></i></button>
      <button type="button" class="user-action-btn" title="Пригласить в нумер" data-action="invite" data-id="${u.id}"><i class="fa fa-door-open"></i></button>
      <button type="button" class="user-action-btn" title="${ignored ? 'Убрать игнор' : 'Игнор'}" data-action="ignore" data-id="${u.id}" data-name="${esc(u.username)}"><i class="fa ${ignored ? 'fa-user-check' : 'fa-user-slash'}"></i></button>
      <button type="button" class="user-action-btn" title="Информация" data-action="info" data-id="${u.id}" data-name="${esc(u.username)}"><i class="fa fa-circle-info"></i></button>
    </div>
  </div>`;
}

$('#online-users-list').on('click', '.online-user-avatar, .online-user-main', function(e) {
  if ($(e.target).closest('.user-action-btn').length) return;
  const $user = $(this).closest('.online-user');
  const uname = $user.data('username');
  insertDirectAddress(uname);
});

$('#online-users-list').on('click', '.user-action-btn', function(e) {
  e.preventDefault();
  e.stopPropagation();
  const action = $(this).data('action');
  const $user = $(this).closest('.online-user');
  const uid = Number($(this).data('id') || $user.data('id') || 0);
  const uname = String($(this).data('name') || $user.data('username') || '');
  if (!uid || !uname) return;

  switch (action) {
    case 'mention':
      insertDirectAddress(uname);
      break;
    case 'whisper':
      if (uid === Number(CURRENT_USER.id)) {
        showToast('Нельзя отправить шёпот самому себе.', 'warning');
      } else {
        insertWhisperTarget(uname);
        activateWhisperMode(uid, uname);
        showToast(`Режим шёпота: @${uname}`);
      }
      break;
    case 'invite':
      if (uid === Number(CURRENT_USER.id)) {
        showToast('Нельзя пригласить себя в нумер.', 'warning');
        break;
      }
      wsSend('invite_user', {to_user_id: uid});
      showToast(`Приглашение в нумер отправлено: ${uname}`);
      break;
    case 'ignore':
      toggleIgnoreUser(uid, uname);
      break;
    case 'info':
      showUserCtxMenu(e, uid, uname);
      break;
  }
});

function showUserCtxMenu(e, uid, uname) {
  e.preventDefault();
  const $menu = $('#ctx-menu').empty();
  if (uid === Number(CURRENT_USER.id)) return;

  $menu.append(`<a class="dropdown-item" href="#" data-action="mention" data-id="${uid}" data-name="${esc(uname)}"><i class="fa fa-at me-2"></i>Личное обращение</a>`);
  $menu.append(`<a class="dropdown-item" href="#" data-action="whisper" data-id="${uid}" data-name="${esc(uname)}"><i class="fa fa-user-secret me-2"></i>Шёпот</a>`);
  $menu.append(`<a class="dropdown-item" href="#" data-action="invite" data-id="${uid}"><i class="fa fa-door-open me-2"></i>Пригласить в нумер</a>`);
  $menu.append(`<a class="dropdown-item" href="#" data-action="friend" data-id="${uid}"><i class="fa fa-user-plus me-2"></i>Добавить в друзья</a>`);
  $menu.append(`<a class="dropdown-item" href="#" data-action="ignore" data-id="${uid}" data-name="${esc(uname)}"><i class="fa fa-user-slash me-2"></i>${ignoredUserIds.has(uid) ? 'Убрать игнор' : 'Игнор'}</a>`);

  if (canModerateCurrentRoom()) {
    $menu.append('<div class="dropdown-divider"></div>');
    $menu.append(`<a class="dropdown-item text-warning" href="#" data-action="room-kick" data-id="${uid}"><i class="fa fa-user-minus me-2"></i>Удалить из комнаты</a>`);
    $menu.append(`<a class="dropdown-item text-danger" href="#" data-action="room-ban" data-id="${uid}"><i class="fa fa-ban me-2"></i>Забанить в комнате</a>`);
    if (canAssignLocalModerator()) {
      $menu.append(`<a class="dropdown-item" href="#" data-action="set-local-moderator" data-id="${uid}"><i class="fa fa-gavel me-2"></i>Назначить модератором</a>`);
      $menu.append(`<a class="dropdown-item" href="#" data-action="set-member" data-id="${uid}"><i class="fa fa-user me-2"></i>Снять локальную роль</a>`);
    }
    if (canAssignLocalAdmin()) {
      $menu.append(`<a class="dropdown-item" href="#" data-action="set-local-admin" data-id="${uid}"><i class="fa fa-user-shield me-2"></i>Назначить локальным админом</a>`);
    }
  }

  if (['platform_owner', 'admin', 'moderator'].includes(CURRENT_USER.global_role)) {
    $menu.append('<div class="dropdown-divider"></div>');
    $menu.append(`<a class="dropdown-item text-danger" href="#" data-action="ban-global" data-id="${uid}"><i class="fa fa-skull-crossbones me-2"></i>Глобальный бан</a>`);
  }

  $menu.css({top: e.clientY, left: e.clientX}).show();
}

$(document).on('click', '#ctx-menu a', function(e) {
  e.preventDefault();
  const action = $(this).data('action');
  const uid = Number($(this).data('id'));
  const uname = $(this).data('name');
  $('#ctx-menu').hide();

  switch (action) {
    case 'mention':
      insertDirectAddress(uname);
      break;
    case 'whisper':
      if (uid === Number(CURRENT_USER.id)) {
        showToast('Нельзя отправить шёпот самому себе.', 'warning');
        break;
      }
      insertWhisperTarget(uname);
      activateWhisperMode(uid, uname);
      break;
    case 'invite':
      if (uid === Number(CURRENT_USER.id)) {
        showToast('Нельзя пригласить себя в нумер.', 'warning');
        break;
      }
      wsSend('invite_user', {to_user_id: uid});
      break;
    case 'friend':
      $.post('/api/friends', {csrf_token: CSRF_TOKEN, to_user_id: uid}, () => showToast('Запрос отправлен.'));
      break;
    case 'ignore':
      toggleIgnoreUser(uid, uname);
      break;
    case 'room-kick':
      executeRoomAction('kick', uid, 'Удалить пользователя из комнаты?');
      break;
    case 'room-ban':
      executeRoomAction('ban', uid, 'Забанить пользователя в комнате?');
      break;
    case 'set-local-moderator':
      executeRoomAction('set_role', uid, null, {role: 'local_moderator'});
      break;
    case 'set-local-admin':
      executeRoomAction('set_role', uid, null, {role: 'local_admin'});
      break;
    case 'set-member':
      executeRoomAction('set_role', uid, null, {role: 'member'});
      break;
    case 'ban-global':
      if (confirm('Забанить пользователя глобально?')) {
        $.post(`/api/admin/users/${uid}`, {csrf_token: CSRF_TOKEN, is_banned: 1}, () => showToast('Пользователь заблокирован.'));
      }
      break;
  }
});

$(document).on('click', function(e) {
  if (!$(e.target).closest('#ctx-menu, .online-user, .user-action-btn').length) $('#ctx-menu').hide();
});

function toggleIgnoreUser(uid, uname) {
  if (ignoredUserIds.has(uid)) {
    ignoredUserIds.delete(uid);
    showToast(`Игнор снят: ${uname}`);
  } else {
    ignoredUserIds.add(uid);
    showToast(`Пользователь в игноре: ${uname}`);
  }
  if (currentRoomId) {
    $('#messages-list').empty();
    loadHistory(currentRoomId);
  }
  renderOnlineList(currentOnlineUsers);
}

function canModerateCurrentRoom() {
  return ['platform_owner', 'admin', 'moderator'].includes(CURRENT_USER.global_role) || ['owner', 'local_admin', 'local_moderator'].includes(currentRoomRole);
}

function canAssignLocalModerator() {
  return ['platform_owner', 'admin'].includes(CURRENT_USER.global_role) || ['owner', 'local_admin'].includes(currentRoomRole);
}

function canAssignLocalAdmin() {
  return ['platform_owner', 'admin'].includes(CURRENT_USER.global_role) || currentRoomRole === 'owner';
}

function executeRoomAction(action, targetUserId, confirmText = null, extra = {}) {
  if (!currentRoomId) return;
  if (confirmText && !confirm(confirmText)) return;
  wsSend('room_action', {room_id: currentRoomId, action, target_user_id: targetUserId, ...extra});
}


// ════════════════════════════════════════════════
//  НУМЕРА
// ════════════════════════════════════════════════
function onNumerJoined(data) {
  loadRooms();
  joinRoom(data.room_id, true);
}

function onInviteSent(invitation) {
  if (!invitation) return;
  pendingInviteRooms.set(Number(invitation.invitation_id), Number(invitation.room_id));
  loadRooms();
}

function onInviteAccepted(data) {
  const invitationId = Number(data.invitation_id || 0);
  const roomId = Number(pendingInviteRooms.get(invitationId) || 0);
  if (invitationId) pendingInviteRooms.delete(invitationId);
  showToast('Приглашение принято: ' + (data.user?.username || ''));
  loadRooms();
  if (roomId) {
    joinRoom(roomId, true);
  }
}

function onInviteDeclined(data) {
  const invitationId = Number(data?.invitation_id || 0);
  if (invitationId) pendingInviteRooms.delete(invitationId);
  showToast('Приглашение отклонено.');
}

function onInviteExpired(data) {
  const invitationId = Number(data?.invitation_id || 0);
  if (invitationId) pendingInviteRooms.delete(invitationId);
  showToast('Приглашение истекло.');
}

function onNumerParticipantJoined(data) {
  if (data.room_id === currentRoomId) {
    addToOnlineList(data.user);
    showToast(data.user.username + ' присоединился к нумеру.');
  }
}

function onNumerParticipantLeft(data) {
  if (data.room_id === currentRoomId) {
    removeFromOnlineList(data.user_id);
  }
}

function onNumerOwnerChanged(data) {
  if (data.room_id !== currentRoomId) return;
  const owner = data.owner || {};
  if (owner.id) {
    currentOnlineUsers = currentOnlineUsers.map(u => {
      if (Number(u.id) === Number(owner.id)) return {...u, room_role: 'owner'};
      if (u.room_role === 'owner') return {...u, room_role: 'member'};
      return u;
    });
    renderOnlineList(currentOnlineUsers);
  }
  loadRooms();
  if (owner.username) {
    showToast('Новый владелец нумера: ' + owner.username);
  }
}

function onNumerDestroyed(data) {
  if (data.room_id === currentRoomId) {
    showToast('Нумер завершён.');
    currentRoomId = null;
    $('#room-title').text('Выберите комнату');
    $('#messages-list').empty();
    $('#online-users-list').empty();
    loadRooms();
  }
}

function onInviteReceived(inv) {
  const from = inv.from || {};
  $('#invite-modal-body').html(`
    <p><strong>${esc(from.username)}</strong> приглашает вас в нумер.</p>
    <p class="text-muted small">Приглашение истекает через 30 секунд.</p>
    <div class="d-flex gap-2">
      <button class="btn btn-success flex-1" id="accept-invite" data-id="${inv.invitation_id}">Принять</button>
      <button class="btn btn-outline-secondary flex-1" id="decline-invite" data-id="${inv.invitation_id}">Отклонить</button>
    </div>
  `);
  new bootstrap.Modal(document.getElementById('inviteModal')).show();

  const timer = setTimeout(() => {
    $('#inviteModal').modal && bootstrap.Modal.getInstance(document.getElementById('inviteModal'))?.hide();
  }, 30000);

  $('#accept-invite').one('click', function() {
    clearTimeout(timer);
    wsSend('invite_respond', {invitation_id: inv.invitation_id, response: 'accept'});
    bootstrap.Modal.getInstance(document.getElementById('inviteModal'))?.hide();
  });
  $('#decline-invite').one('click', function() {
    clearTimeout(timer);
    wsSend('invite_respond', {invitation_id: inv.invitation_id, response: 'decline'});
    bootstrap.Modal.getInstance(document.getElementById('inviteModal'))?.hide();
  });
}

// ════════════════════════════════════════════════
//  KICK / BAN / DELETE
// ════════════════════════════════════════════════
function onKickedFromRoom(data) {
  if (data.room_id === currentRoomId) {
    showToast('Вы были удалены из комнаты.', 'warning');
    currentRoomId = null;
    $('#room-title').text('Выберите комнату');
    $('#messages-list').empty();
    $('#online-users-list').empty();
    loadRooms();
  }
}

function onRoomDeleted(data) {
  if (data.room_id === currentRoomId) {
    showToast('Комната была удалена.', 'warning');
    currentRoomId = null;
    $('#room-title').text('Выберите комнату');
    $('#messages-list').empty();
  }
  loadRooms();
}

// ════════════════════════════════════════════════
//  FRIENDS
// ════════════════════════════════════════════════
function loadFriends() {
  $.get('/api/friends', function(resp) {
    if (!resp.success) return;
    renderFriends(resp.friends);
  });
}

function renderFriends(friends) {
  const $list = $('#friends-list').empty();
  const q = $('#friend-search').val().toLowerCase();
  const filtered = q ? friends.filter(f => f.username.toLowerCase().includes(q)) : friends;
  filtered.forEach(f => {
    const isOnline = !!f.current_room;
    const dot = `<span class="online-dot ${isOnline?'online':'offline'}"></span>`;
    const where = isOnline ? `<span class="text-muted small ms-1">${esc(f.current_room)}</span>` : `<span class="text-muted small ms-1">${f.last_seen_at ? dayjs(f.last_seen_at).fromNow() : ''}</span>`;
    $list.append(`<div class="friend-item">${dot} <span>${esc(f.username)}</span>${where}</div>`);
  });
}

$('#friend-search').on('input', function() { loadFriends(); });

// ════════════════════════════════════════════════
//  SETTINGS
// ════════════════════════════════════════════════
function initSettings() {
  const colorValidity = { nick_color: true, text_color: true };

  function syncSettingsSaveState() {
    const ok = colorValidity.nick_color && colorValidity.text_color;
    $('#settings-save-btn').prop('disabled', !ok);
  }

  $('#my-username').parent().closest('.sidebar-bottom').on('click', '#my-username', function() {
    const modal = new bootstrap.Modal(document.getElementById('settingsModal'));
    $('[name="username"]').val(CURRENT_USER.username);
    $('[name="signature"]').val(CURRENT_USER.signature || '');
    $('[name="custom_status"]').val(CURRENT_USER.custom_status || '');
    $('[name="nick_color"]').val(CURRENT_USER.nick_color || '#ffffff');
    $('[name="text_color"]').val(CURRENT_USER.text_color || '#dee2e6');
    $('#showSystemMessagesSetting').prop('checked', shouldShowSystemMessages());
    modal.show();
    $('[name="nick_color"]').trigger('input');
    $('[name="text_color"]').trigger('input');
    syncSettingsSaveState();
  });

  function updateColorPreview(pickerName, previewLightId, previewDarkId, feedbackId) {
    $(`[name="${pickerName}"]`).off('input.colorcheck').on('input.colorcheck', function() {
      const hex = $(this).val();
      const $fb = $(`#${feedbackId}`);
      $(`#${previewLightId}`).css('color', hex);
      $(`#${previewDarkId}`).css('color', hex);
      $fb.html('<span class="text-muted">Проверка...</span>');

      $.post('/api/color-check', {color: hex}, function(resp) {
        colorValidity[pickerName] = !!resp.valid;
        if (resp.valid) {
          $fb.html(`<span class="text-success"><i class="fa fa-check"></i></span> Свет: ${resp.ratios.light}:1 / Тёмная: ${resp.ratios.dark}:1`);
        } else {
          $fb.html(`<span class="text-danger"><i class="fa fa-times"></i> ${esc(resp.error)}</span>`);
        }
        syncSettingsSaveState();
      }, 'json').fail(function() {
        colorValidity[pickerName] = false;
        $fb.html('<span class="text-danger"><i class="fa fa-times"></i> Не удалось проверить цвет.</span>');
        syncSettingsSaveState();
      });
    });
  }

  updateColorPreview('nick_color', 'nick-preview-light', 'nick-preview-dark', 'nick-color-feedback');
  updateColorPreview('text_color', 'text-preview-light', 'text-preview-dark', 'text-color-feedback');

  $('#settingsForm').off('submit').on('submit', function(e) {
    e.preventDefault();
    syncSettingsSaveState();
    if ($('#settings-save-btn').prop('disabled')) {
      $('#settings-error').text('Выбранный цвет плохо читается на теме. Исправьте цвета и сохраните снова.').removeClass('d-none');
      return;
    }

    const fd = new FormData(this);
    fd.set('csrf_token', CSRF_TOKEN);
    $('#settings-error').addClass('d-none');
    $('#settings-success').addClass('d-none');
    $.ajax({
      url: '/api/settings',
      method: 'POST',
      data: fd,
      processData: false,
      contentType: false,
      success: function(resp) {
        if (resp.success) {
          if (resp.user) {
            Object.assign(CURRENT_USER, resp.user);
          }
          localStorage.setItem('show_system_messages', $('#showSystemMessagesSetting').is(':checked') ? '1' : '0');
          $('#settings-success').removeClass('d-none');
          initUser();
          if (ws && ws.readyState === WebSocket.OPEN) {
            ws.close();
          }
          setTimeout(() => location.reload(), 250);
        } else {
          $('#settings-error').text(resp.error || 'Не удалось сохранить настройки.').removeClass('d-none');
        }
      },
      error: function(xhr) {
        const err = xhr.responseJSON?.error || 'Не удалось сохранить настройки.';
        $('#settings-error').text(err).removeClass('d-none');
      }
    });
  });
}
// ════════════════════════════════════════════════
//  SIDEBAR TOGGLE
// ════════════════════════════════════════════════

function initSidebar() {
  $('#my-username').css('cursor','pointer');
  $('#my-username').on('click', () => new bootstrap.Modal(document.getElementById('settingsModal')).show());

  $('#room-manage-btn').on('click', function() {
    if (!currentRoomId) return;
    const room = rooms.find(r => r.id === currentRoomId) || numera.find(r => r.id === currentRoomId);
    const canRenameDelete = ['platform_owner', 'admin'].includes(CURRENT_USER.global_role) || currentRoomRole === 'owner';
    const canAssignRoles = canAssignLocalModerator() || canAssignLocalAdmin();
    let html = '';
    if (canRenameDelete && room) {
      html += `<div class="mb-3"><label class="form-label">Название комнаты</label><div class="input-group"><input type="text" class="form-control" id="room-manage-name" value="${esc(room.name)}"><button class="btn btn-primary" id="room-rename-btn">Сохранить</button></div></div>`;
      html += `<div class="mb-3"><button class="btn btn-outline-danger" id="room-delete-btn">Удалить комнату</button></div>`;
    }
    html += '<div class="fw-semibold mb-2">Сейчас в комнате</div>';
    html += '<div class="list-group">';
    currentOnlineUsers.forEach(u => {
      if (Number(u.id) === Number(CURRENT_USER.id)) return;
      const role = visibleRoleLabel(u);
      html += `<div class="list-group-item"><div class="d-flex align-items-center gap-2 mb-2">${avatarMarkup(u.avatar_url, 32)}<div class="flex-1"><div>${esc(u.username)}</div>${role ? `<div class="small text-muted">${role}</div>` : ''}</div></div><div class="d-flex flex-wrap gap-2"><button class="btn btn-sm btn-outline-secondary room-action-btn" data-action="kick" data-id="${u.id}">Удалить</button><button class="btn btn-sm btn-outline-danger room-action-btn" data-action="ban" data-id="${u.id}">Бан</button>${canAssignRoles ? `<button class="btn btn-sm btn-outline-primary room-action-btn" data-action="set_role" data-role="local_moderator" data-id="${u.id}">Модератор</button><button class="btn btn-sm btn-outline-primary room-action-btn" data-action="set_role" data-role="member" data-id="${u.id}">Участник</button>` : ''}${canAssignLocalAdmin() ? `<button class="btn btn-sm btn-outline-warning room-action-btn" data-action="set_role" data-role="local_admin" data-id="${u.id}">Локальный админ</button>` : ''}</div></div>`;
    });
    html += '</div>';
    $('#room-manage-body').html(html || '<div class="text-muted">Нет доступных действий.</div>');
    new bootstrap.Modal(document.getElementById('roomManageModal')).show();
  });

  $(document).on('click', '#room-rename-btn', function() {
    const name = $('#room-manage-name').val().trim();
    if (!name) return;
    wsSend('room_action', {room_id: currentRoomId, action: 'rename', name});
    showToast('Название отправлено на сохранение.');
  });

  $(document).on('click', '#room-delete-btn', function() {
    if (!confirm('Удалить комнату?')) return;
    wsSend('room_action', {room_id: currentRoomId, action: 'delete'});
  });

  $(document).on('click', '.room-action-btn', function() {
    const action = $(this).data('action');
    const uid = Number($(this).data('id'));
    const role = $(this).data('role');
    if (action === 'kick') {
      executeRoomAction('kick', uid, 'Удалить пользователя из комнаты?');
    } else if (action === 'ban') {
      executeRoomAction('ban', uid, 'Забанить пользователя в комнате?');
    } else if (action === 'set_role') {
      executeRoomAction('set_role', uid, null, {role});
    }
  });
}

function initAdmin() {
  if (!CURRENT_USER || !['platform_owner', 'admin'].includes(CURRENT_USER.global_role)) return;

  $('#admin-btn').on('click', function(e) {
    e.preventDefault();
    loadAdminDash();
    new bootstrap.Modal(document.getElementById('adminModal')).show();
  });

  // Tab switch
  $('#adminTabs a[data-bs-toggle="tab"]').on('shown.bs.tab', function() {
    const tab = $(this).attr('href');
    if (tab === '#adminDash')    loadAdminDash();
    if (tab === '#adminUsers')   loadAdminUsers();
    if (tab === '#adminRooms')   loadAdminRooms();
    if (tab === '#adminNumera')  loadAdminNumera();
    if (tab === '#adminWhispers')loadAdminWhispers();
  });

  $('#admin-user-search-btn').on('click', loadAdminUsers);
  $('#whisper-search-btn').on('click', loadAdminWhispers);
}

function loadAdminDash() {
  $.get('/api/admin/dashboard', function(resp) {
    if (!resp.success) return;
    const s = resp.stats;
    $('#admin-stats').html(`
      ${statCard('Пользователей', s.users_total, 'fa-users', 'primary')}
      ${statCard('Сообщений сегодня', s.messages_today, 'fa-envelope', 'success')}
      ${statCard('Активных комнат', s.rooms_active, 'fa-door-open', 'info')}
      ${statCard('Активных нумеров', s.numera_active, 'fa-lock', 'warning')}
    `);
  });
}

function statCard(label, val, icon, color) {
  return `<div class="col-md-3"><div class="card text-center">
    <div class="card-body"><i class="fa ${icon} fa-2x text-${color} mb-2"></i>
    <h4>${val}</h4><div class="text-muted small">${label}</div></div></div></div>`;
}

function loadAdminUsers(page) {
  page = page || 1;
  const search = $('#admin-user-search').val();
  $.get('/api/admin/users', {page, search}, function(resp) {
    if (!resp.success) return;
    let html = `
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="small text-muted">Управление отображаемыми статусами пользователей</div>
        <div class="form-check form-switch mb-0">
          <input class="form-check-input" type="checkbox" id="admin-status-override-toggle">
          <label class="form-check-label" for="admin-status-override-toggle">Разрешить глобальным админам менять статусы</label>
        </div>
      </div>
      <table class="table table-sm table-hover">
      <thead>
        <tr>
          <th>ID</th>
          <th>Пользователь</th>
          <th>Email</th>
          <th>Глобальная роль</th>
          <th>Отображаемый статус</th>
          <th>Бан</th>
          <th>Создание комнат</th>
          <th></th>
        </tr>
      </thead><tbody>`;
    resp.users.forEach(u => {
      html += `<tr><td>${u.id}</td><td>${esc(u.username)}</td><td>${esc(u.email||'')}</td>
        <td><select class="form-select form-select-sm user-role-sel" data-id="${u.id}" data-prev="${u.global_role}" style="width:auto">
          <option value="user" ${u.global_role==='user'?'selected':''}>Пользователь</option>
          <option value="moderator" ${u.global_role==='moderator'?'selected':''}>Глобальный модератор</option>
          <option value="admin" ${u.global_role==='admin'?'selected':''}>Глобальный администратор</option>
          <option value="platform_owner" ${u.global_role==='platform_owner'?'selected':''}>Владелец</option>
        </select></td>
        <td>
          <div class="input-group input-group-sm">
            <input type="text" class="form-control user-status-input" data-id="${u.id}" value="${esc(u.custom_status || '')}" maxlength="80" placeholder="Статус">
            <button class="btn btn-outline-primary user-status-save-btn" data-id="${u.id}" type="button">Сохранить</button>
          </div>
        </td>
        <td><input type="checkbox" class="form-check-input user-ban-cb" data-id="${u.id}" ${u.is_banned?'checked':''}></td>
        <td><input type="checkbox" class="form-check-input user-room-cb" data-id="${u.id}" ${u.can_create_room?'checked':''}></td>
        <td><button class="btn btn-sm btn-danger user-del-btn" data-id="${u.id}"><i class="fa fa-trash"></i></button></td></tr>`;
    });
    html += '</tbody></table>';
    $('#admin-users-table').html(html);
    loadAdminStatusOverrideSetting();
  });
}

function loadAdminStatusOverrideSetting() {
  $.get('/api/admin/status-override-settings', function(resp) {
    if (!resp.success) return;
    const enabledForAdmins = !!resp.allow_admin_status_override;
    $('#admin-status-override-toggle').prop('checked', enabledForAdmins);
    const ownerCanToggle = CURRENT_USER.global_role === 'platform_owner';
    $('#admin-status-override-toggle').prop('disabled', !ownerCanToggle);
    const canEditStatuses = ownerCanToggle || (CURRENT_USER.global_role === 'admin' && enabledForAdmins);
    $('.user-status-input, .user-status-save-btn').prop('disabled', !canEditStatuses);
  });
}

$('#admin-users-table').off('change', '.user-role-sel').on('change', '.user-role-sel', function() {
  const $select = $(this);
  const id = $select.data('id');
  const prev = $select.attr('data-prev');
  const next = $select.val();
  $.post(`/api/admin/users/${id}`, {csrf_token: CSRF_TOKEN, global_role: next}, function(resp) {
    if (resp.success) {
      $select.attr('data-prev', next);
      showToast('Глобальная роль обновлена.', 'success');
      return;
    }
    $select.val(prev);
    showToast(resp.error || 'Не удалось изменить роль.', 'danger');
  }, 'json').fail(function(xhr) {
    $select.val(prev);
    showToast(xhr.responseJSON?.error || 'Не удалось изменить роль.', 'danger');
  });
});

$('#admin-users-table').off('click', '.user-status-save-btn').on('click', '.user-status-save-btn', function() {
  const userId = Number($(this).data('id'));
  const value = $(`.user-status-input[data-id="${userId}"]`).val() || '';
  $.post(`/api/admin/users/${userId}`, {csrf_token: CSRF_TOKEN, custom_status: value}, function(resp) {
    if (resp.success) {
      showToast('Статус пользователя обновлён.', 'success');
    } else {
      showToast(resp.error || 'Не удалось обновить статус.', 'danger');
    }
  }, 'json').fail(function(xhr) {
    showToast(xhr.responseJSON?.error || 'Не удалось обновить статус.', 'danger');
  });
});

$('#admin-users-table').off('change', '#admin-status-override-toggle').on('change', '#admin-status-override-toggle', function() {
  const enabled = $(this).is(':checked') ? 1 : 0;
  $.post('/api/admin/status-override-settings', {csrf_token: CSRF_TOKEN, allow_admin_status_override: enabled}, function(resp) {
    if (resp.success) {
      showToast('Правило изменения статусов обновлено.', 'success');
    } else {
      showToast(resp.error || 'Не удалось обновить правило.', 'danger');
    }
  }, 'json').fail(function(xhr) {
    showToast(xhr.responseJSON?.error || 'Не удалось обновить правило.', 'danger');
    loadAdminStatusOverrideSetting();
  });
});

$('#admin-users-table').off('change', '.user-ban-cb').on('change', '.user-ban-cb', function() {
  const $cb = $(this);
  const id = $cb.data('id');
  const prev = !$cb.is(':checked');
  $.post(`/api/admin/users/${id}`, {csrf_token: CSRF_TOKEN, is_banned: $cb.is(':checked') ? 1 : 0}, function(resp) {
    if (resp.success) {
      showToast('Статус бана обновлён.', 'success');
      return;
    }
    $cb.prop('checked', prev);
    showToast(resp.error || 'Не удалось обновить бан.', 'danger');
  }, 'json').fail(function(xhr) {
    $cb.prop('checked', prev);
    showToast(xhr.responseJSON?.error || 'Не удалось обновить бан.', 'danger');
  });
});

$('#admin-users-table').off('change', '.user-room-cb').on('change', '.user-room-cb', function() {
  const $cb = $(this);
  const id = $cb.data('id');
  const prev = !$cb.is(':checked');
  $.post(`/api/admin/users/${id}`, {csrf_token: CSRF_TOKEN, can_create_room: $cb.is(':checked') ? 1 : 0}, function(resp) {
    if (resp.success) {
      showToast('Право на создание комнат обновлено.', 'success');
      return;
    }
    $cb.prop('checked', prev);
    showToast(resp.error || 'Не удалось обновить право.', 'danger');
  }, 'json').fail(function(xhr) {
    $cb.prop('checked', prev);
    showToast(xhr.responseJSON?.error || 'Не удалось обновить право.', 'danger');
  });
});

$('#admin-users-table').off('click', '.user-del-btn').on('click', '.user-del-btn', function() {
  const id = $(this).data('id');
  if (!confirm('Удалить пользователя?')) return;
  $.ajax({
    url: `/api/admin/users/${id}`,
    method: 'DELETE',
    data: {csrf_token: CSRF_TOKEN},
    success: function(resp) {
      if (resp.success) {
        showToast('Пользователь удалён.', 'success');
        loadAdminUsers();
      } else {
        showToast(resp.error || 'Не удалось удалить пользователя.', 'danger');
      }
    },
    error: function(xhr) {
      showToast(xhr.responseJSON?.error || 'Не удалось удалить пользователя.', 'danger');
    }
  });
});

function loadAdminRooms() {
  $.get('/api/admin/rooms', function(resp) {
    if (!resp.success) return;
    let html = '<table class="table table-sm"><thead><tr><th>ID</th><th>Название</th><th>Тип</th><th>Участников</th><th>Владелец</th><th></th></tr></thead><tbody>';
    resp.rooms.forEach(r => {
      html += `<tr><td>${r.id}</td><td>${esc(r.name)}</td><td><span class="badge bg-${r.type==='public'?'primary':'warning'}">${r.type}</span></td>
        <td>${r.member_count}</td><td>${esc(r.owner_username||'')}</td>
        <td><button class="btn btn-sm btn-danger room-del-btn" data-id="${r.id}"><i class="fa fa-trash"></i></button></td></tr>`;
    });
    html += '</tbody></table>';
    $('#admin-rooms-table').html(html);
  });
}
$('#admin-rooms-table').on('click', '.room-del-btn', function() {
  const id = $(this).data('id');
  if (!confirm('Удалить комнату?')) return;
  $.ajax({url:`/api/admin/rooms/${id}`,method:'DELETE',data:{csrf_token:CSRF_TOKEN},success:()=>{ showToast('Удалена.'); loadAdminRooms(); }});
});

function loadAdminNumera() {
  $.get('/api/admin/numera', function(resp) {
    if (!resp.success) return;
    let html = '<table class="table table-sm"><thead><tr><th>ID</th><th>Участники</th><th>Сообщений</th><th>Закрыт</th></tr></thead><tbody>';
    resp.numera.forEach(r => {
      html += `<tr><td>${r.id}</td><td>${esc(r.participants)}</td><td>${r.message_count}</td><td>${r.closed_at||''}</td></tr>`;
    });
    html += '</tbody></table>';
    $('#admin-numera-table').html(html);
  });
}

function loadAdminWhispers() {
  const from = $('#whisper-filter-from').val();
  const to   = $('#whisper-filter-to').val();
  $.get('/api/admin/whispers', {from_username:from, to_username:to}, function(resp) {
    if (!resp.success) return;
    let html = '<table class="table table-sm"><thead><tr><th>ID</th><th>Комната</th><th>От</th><th>Кому</th><th>Время</th><th>Текст</th></tr></thead><tbody>';
    resp.whispers.forEach(w => {
      html += `<tr><td>${w.id}</td><td>${esc(w.room_name)}</td><td>${esc(w.from_username)}</td><td>${esc(w.to_username)}</td><td>${w.created_at}</td><td>${w.content}</td></tr>`;
    });
    html += '</tbody></table>';
    $('#admin-whispers-table').html(html);
  });
}

// ════════════════════════════════════════════════
//  HELPERS
// ════════════════════════════════════════════════
function scrollToBottom() {
  const el = document.getElementById('messages-container');
  el.scrollTop = el.scrollHeight;
  isScrolledToBottom = true;
  $('#scroll-bottom-btn').hide();
}

function esc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function showToast(msg, type) {
  type = type || 'info';
  const colors = {success:'#198754',danger:'#dc3545',warning:'#ffc107',info:'#0dcaf0'};
  const $t = $(`<div style="position:fixed;top:16px;right:16px;z-index:9999;padding:10px 18px;border-radius:8px;background:${colors[type]||colors.info};color:${type==='warning'?'#000':'#fff'};box-shadow:0 4px 12px rgba(0,0,0,.2);max-width:300px">${esc(msg)}</div>`);
  $('body').append($t);
  setTimeout(() => $t.fadeOut(400, function(){ $(this).remove(); }), 3500);
}

function roleLabel(role) {
  return {platform_owner:'Владелец', admin:'Глобальный администратор', moderator:'Глобальный модератор', user:''}[role] || role;
}

function roomRoleLabel(role) {
  return {owner:'Владелец комнаты', local_admin:'Локальный администратор', local_moderator:'Локальный модератор', member:'', banned:'Забанен'}[role] || role;
}

<?php endif; ?>

// Auth forms
$('#loginForm').on('submit', function(e) {
  e.preventDefault();
  $('#loginError').addClass('d-none');
  $.post('/auth/login', $(this).serialize(), function(resp) {
    if (resp.success) location.href = resp.redirect || '/';
    else $('#loginError').text(resp.error).removeClass('d-none');
  }).fail(function(xhr) {
    const err = xhr.responseJSON?.error || 'Ошибка';
    $('#loginError').text(err).removeClass('d-none');
  });
});

$('#registerForm').on('submit', function(e) {
  e.preventDefault();
  $('#registerError').addClass('d-none');
  $.post('/auth/register', $(this).serialize(), function(resp) {
    if (resp.success) location.href = resp.redirect || '/';
    else $('#registerError').text(resp.error).removeClass('d-none');
  }).fail(function(xhr) {
    const err = xhr.responseJSON?.error || 'Ошибка';
    $('#registerError').text(err).removeClass('d-none');
  });
});
</script>
</body>
</html>
