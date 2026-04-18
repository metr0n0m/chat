<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Chat\Security\CSRF;
use Chat\Support\Lang;

Lang::init(APP_LOCALE);

$router = new \Chat\Http\Router();
$router->dispatch();
$user = $router->getUser();

// ─── Main HTML page ───────────────────────────────────────────────────────────
$nonce = base64_encode(random_bytes(16));
$csrfToken = CSRF::token();
$isLoggedIn = (bool) $user;
$_sysMsgColor = '#DEC8A4';
if ($isLoggedIn) {
    try {
        $db = \Chat\DB\Connection::getInstance();
        $row = $db->fetchOne("SELECT value FROM app_settings WHERE name = 'system_message_color'");
        if ($row && !empty($row['value'])) $_sysMsgColor = (string) $row['value'];
    } catch (\Throwable $e) {}
}
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

header("Content-Security-Policy: default-src 'self'; script-src 'self' cdn.jsdelivr.net cdnjs.cloudflare.com 'nonce-$nonce'; style-src 'self' 'unsafe-inline' cdn.jsdelivr.net cdnjs.cloudflare.com 'nonce-$nonce'; style-src-attr 'unsafe-inline'; img-src * data:; connect-src 'self' ws: wss:; font-src cdn.jsdelivr.net cdnjs.cloudflare.com;");
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
:root { --sidebar-w: 260px; --right-panel-w: 220px; --sys-msg-color: <?= htmlspecialchars($_sysMsgColor, ENT_QUOTES) ?>; }
body { height: 100vh; margin: 0; }
.chat-layout { display: flex; height: 100vh; overflow: hidden; }

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
.msg-system { text-align: center; font-style: italic; color: var(--sys-msg-color); font-size: .82rem; padding: 2px 0; }
.msg-time { color: var(--sys-msg-color); font-size: .8rem; }
.msg-sep { color: var(--sys-msg-color); }
.msg-whisper-row { background: rgba(170,170,170,.13); border-radius: 4px; padding: 2px 6px; }
.room-desc { font-size: .78rem; color: var(--bs-secondary-color); }
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
      <div class="d-flex gap-1">
        <?php if ($user['can_create_room'] || in_array($user['global_role'], ['platform_owner', 'admin'], true)): ?>
        <button id="createRoomBtn" class="btn btn-sm btn-outline-primary" title="Создать комнату"><i class="fa fa-plus"></i></button>
        <?php endif; ?>
        <button id="themeToggle" class="btn btn-sm btn-outline-secondary" title="Сменить тему"><i class="fa fa-moon"></i></button>
      </div>
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
      <img id="my-avatar" src="/assets/avatar-default.svg" alt="" class="rounded-circle" style="width:36px;height:36px;object-fit:cover;flex-shrink:0;cursor:pointer" title="Настройки">
      <div class="flex-1 overflow-hidden" style="cursor:pointer" id="my-username-wrap">
        <div id="my-username" class="fw-semibold text-truncate" style="font-size:.88rem;line-height:1.2"></div>
        <div id="my-status" class="text-truncate text-muted" style="font-size:.75rem;line-height:1.2"></div>
      </div>
      <div class="d-flex gap-1">
        <button id="settings-btn" class="btn btn-sm btn-outline-secondary" title="Настройки"><i class="fa fa-gear"></i></button>
        <a href="/auth/logout" class="btn btn-sm btn-outline-danger" title="Выйти"><i class="fa fa-sign-out-alt"></i></a>
      </div>
    </div>
  </div>

  <!-- ─── MAIN CHAT ─── -->
  <div id="chat-main">
    <div id="chat-header">
      <button class="btn btn-sm btn-outline-secondary d-md-none" id="toggleSidebar"><i class="fa fa-bars"></i></button>
      <div class="flex-1 overflow-hidden">
        <div id="room-title" class="fw-bold">Выберите комнату</div>
        <div id="room-description" class="room-desc d-none"></div>
      </div>
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
    <div class="modal-body p-0">
      <form id="settingsForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
        <div class="d-flex" style="min-height:420px">
          <!-- vertical tabs nav -->
          <ul class="nav flex-column nav-pills p-3 border-end" style="min-width:140px">
            <li class="nav-item"><a class="nav-link active" data-bs-toggle="pill" href="#sTab1">Профиль</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#sTab2">Внешний вид</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#sTab3">Аватар</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#sTab4">Приватность</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#sTab5">Безопасность</a></li>
          </ul>
          <!-- tab panes -->
          <div class="tab-content flex-1 p-3">
            <!-- Профиль -->
            <div class="tab-pane fade show active" id="sTab1">
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label">Имя пользователя (ник)</label>
                  <input type="text" class="form-control" name="username" minlength="3" maxlength="50" id="usernameInput" autocomplete="off">
                  <div id="username-check" class="small mt-1"></div>
                </div>
                <div class="col-12">
                  <label class="form-label">Отображаемый статус (до 80 символов)</label>
                  <input type="text" class="form-control" name="custom_status" maxlength="80" placeholder="Например: В отпуске">
                </div>
                <div class="col-12">
                  <label class="form-label">Подпись (до 300 символов)</label>
                  <textarea class="form-control" name="signature" maxlength="300" rows="2"></textarea>
                </div>
                <div class="col-12">
                  <label class="form-label">О себе (до 500 символов)</label>
                  <textarea class="form-control" name="bio" maxlength="500" rows="2"></textarea>
                </div>
                <div class="col-12">
                  <label class="form-label">Telegram</label>
                  <input type="url" class="form-control" name="social_telegram" placeholder="https://t.me/...">
                </div>
                <div class="col-12">
                  <label class="form-label">WhatsApp</label>
                  <input type="url" class="form-control" name="social_whatsapp" placeholder="https://wa.me/...">
                </div>
                <div class="col-12">
                  <label class="form-label">VK</label>
                  <input type="url" class="form-control" name="social_vk" placeholder="https://vk.com/...">
                </div>
              </div>
            </div>
            <!-- Внешний вид -->
            <div class="tab-pane fade" id="sTab2">
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label">Цвет ника</label>
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <input type="color" class="form-control form-control-color" name="nick_color" id="nickColorPicker" style="width:46px;height:38px;padding:2px">
                    <div class="color-preview-box" id="nick-preview-light" style="background:#f8f9fa">Ник</div>
                    <div class="color-preview-box" id="nick-preview-dark" style="background:#212529">Ник</div>
                  </div>
                  <div id="nick-color-feedback" class="small"></div>
                </div>
                <div class="col-12">
                  <label class="form-label">Цвет текста</label>
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <input type="color" class="form-control form-control-color" name="text_color" id="textColorPicker" style="width:46px;height:38px;padding:2px">
                    <div class="color-preview-box" id="text-preview-light" style="background:#f8f9fa">Текст</div>
                    <div class="color-preview-box" id="text-preview-dark" style="background:#212529">Текст</div>
                  </div>
                  <div id="text-color-feedback" class="small"></div>
                </div>
              </div>
            </div>
            <!-- Аватар -->
            <div class="tab-pane fade" id="sTab3">
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label">Загрузить файл (JPEG/PNG/GIF/WEBP ≤2MB)</label>
                  <input type="file" class="form-control" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp">
                </div>
                <div class="col-12">
                  <label class="form-label">или URL аватара</label>
                  <input type="url" class="form-control" name="avatar_url" placeholder="https://...">
                </div>
              </div>
            </div>
            <!-- Приватность -->
            <div class="tab-pane fade" id="sTab4">
              <div class="row g-3">
                <div class="col-12">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="showSystemMessagesSetting">
                    <label class="form-check-label" for="showSystemMessagesSetting">Показывать сервисные сообщения в чате</label>
                  </div>
                </div>
                <div class="col-12">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="hideLastSeenSetting" name="hide_last_seen" value="1">
                    <label class="form-check-label" for="hideLastSeenSetting">Скрывать последний вход от обычных пользователей</label>
                  </div>
                </div>
              </div>
            </div>
            <!-- Безопасность -->
            <div class="tab-pane fade" id="sTab5">
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label">Новый пароль (оставьте пустым чтобы не менять)</label>
                  <input type="password" class="form-control" name="password" minlength="8">
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="border-top p-3">
          <div id="settings-error" class="alert alert-danger mb-2 d-none"></div>
          <div id="settings-success" class="alert alert-success mb-2 d-none">Настройки сохранены.</div>
          <button type="submit" class="btn btn-primary" id="settings-save-btn">Сохранить</button>
        </div>
      </form>
    </div>
  </div></div>
</div>

<!-- Create user modal (platform_owner only) -->
<div class="modal fade" id="createUserModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Создать пользователя</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <form id="createUserForm">
        <div class="mb-3">
          <label class="form-label">Имя пользователя (ник) *</label>
          <input type="text" class="form-control" name="username" minlength="3" maxlength="50" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Email (необязательно)</label>
          <input type="email" class="form-control" name="email">
        </div>
        <div class="mb-3">
          <label class="form-label">Пароль *</label>
          <input type="password" class="form-control" name="password" minlength="8" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Роль</label>
          <select class="form-select" name="global_role">
            <option value="user">Пользователь</option>
            <option value="moderator">Модератор</option>
            <option value="admin">Администратор</option>
          </select>
        </div>
        <div id="create-user-error" class="alert alert-danger d-none"></div>
        <button type="submit" class="btn btn-success w-100">Создать</button>
      </form>
    </div>
  </div></div>
</div>

<!-- Create room modal -->
<div class="modal fade" id="createRoomModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Создать комнату</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <form id="createRoomForm">
        <div class="mb-3">
          <label class="form-label">Название (2–100 символов)</label>
          <input type="text" class="form-control" name="name" minlength="2" maxlength="100" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Описание (необязательно)</label>
          <input type="text" class="form-control" name="description" maxlength="300">
        </div>
        <div id="create-room-error" class="alert alert-danger d-none"></div>
        <button type="submit" class="btn btn-primary w-100">Создать</button>
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
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#adminBans">Баны</a></li>
        <?php if ($user['global_role'] === 'platform_owner'): ?>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#adminSettings">Настройки</a></li>
        <?php endif; ?>
      </ul>
      <div class="tab-content">
        <div class="tab-pane fade show active" id="adminDash">
          <div class="row g-3" id="admin-stats"></div>
        </div>
        <div class="tab-pane fade" id="adminUsers">
          <div class="mb-2 d-flex gap-2 flex-wrap">
            <input type="text" class="form-control form-control-sm" id="admin-user-search" placeholder="Поиск..." style="max-width:220px">
            <button class="btn btn-sm btn-outline-primary" id="admin-user-search-btn">Найти</button>
            <?php if ($user['global_role'] === 'platform_owner'): ?>
            <button class="btn btn-sm btn-outline-success ms-auto" id="admin-create-user-btn"><i class="fa fa-plus me-1"></i>Создать пользователя</button>
            <?php endif; ?>
          </div>
          <div id="admin-users-table"></div>
        </div>
        <div class="tab-pane fade" id="adminRooms">
          <div id="admin-rooms-table"></div>
        </div>
        <div class="tab-pane fade" id="adminNumera">
          <div id="admin-numera-table"></div>
        </div>
        <div class="tab-pane fade" id="adminBans">
          <div id="admin-bans-table"></div>
        </div>
        <div class="tab-pane fade" id="adminWhispers">
          <div class="mb-2 d-flex gap-2">
            <input type="text" class="form-control form-control-sm" id="whisper-filter-from" placeholder="От пользователя">
            <input type="text" class="form-control form-control-sm" id="whisper-filter-to" placeholder="Кому">
            <button class="btn btn-sm btn-outline-primary" id="whisper-search-btn">Найти</button>
          </div>
          <div id="admin-whispers-table"></div>
        </div>
        <?php if ($user['global_role'] === 'platform_owner'): ?>
        <div class="tab-pane fade" id="adminSettings">
          <form id="adminSettingsForm" class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Формат даты/времени</label>
              <input type="text" class="form-control" name="datetime_format" placeholder="DD.MM.YY HH:mm">
            </div>
            <div class="col-md-6">
              <label class="form-label">Формат времени</label>
              <input type="text" class="form-control" name="time_format" placeholder="HH:mm">
            </div>
            <div class="col-md-6">
              <label class="form-label">Цвет системных сообщений</label>
              <div class="d-flex align-items-center gap-2">
                <input type="color" class="form-control form-control-color" name="system_message_color" id="sysMsgColorPicker" style="width:46px;height:38px;padding:2px">
                <div id="sys-msg-color-preview" class="color-preview-box">Системное</div>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Тема по умолчанию</label>
              <select class="form-select" name="system_theme">
                <option value="auto">Авто</option>
                <option value="light">Светлая</option>
                <option value="dark">Тёмная</option>
              </select>
            </div>
            <div class="col-md-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="registration_enabled" id="admin-reg-enabled" value="1">
                <label class="form-check-label" for="admin-reg-enabled">Регистрация открыта</label>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="maintenance_mode" id="admin-maint-mode" value="1">
                <label class="form-check-label" for="admin-maint-mode">Режим обслуживания</label>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label">Сообщение при обслуживании</label>
              <input type="text" class="form-control" name="maintenance_message" placeholder="Текст для пользователей">
            </div>
            <div class="col-12">
              <div id="admin-settings-error" class="alert alert-danger d-none"></div>
              <div id="admin-settings-success" class="alert alert-success d-none">Настройки сохранены.</div>
              <button type="submit" class="btn btn-primary">Сохранить настройки</button>
            </div>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div></div>
</div>

<!-- User info modal -->
<div class="modal fade" id="userInfoModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title"><i class="fa fa-circle-info me-2"></i>Информация о пользователе</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body" id="user-info-body">
      <div class="text-muted">Загрузка...</div>
    </div>
    <div class="modal-footer d-flex justify-content-between">
      <div id="user-info-actions" class="d-flex flex-wrap gap-2"></div>
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Закрыть</button>
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
let infoUserId = null;
let infoUsername = '';
let isScrolledToBottom = true;
let oldestMessageId = null;
let rooms = [];
let numera = [];
const ignoredUserIds = new Set();
const pendingInviteRooms = new Map();
const onlineCountsByRoom = new Map();
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
  $('#my-status').text(CURRENT_USER.custom_status || '');
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
    case 'numer_joined':    onNumerJoined(data); break;
    case 'numer_participant_joined': onNumerParticipantJoined(data); break;
    case 'numer_participant_left':   onNumerParticipantLeft(data); break;
    case 'numer_owner_changed': onNumerOwnerChanged(data); break;
    case 'numer_destroyed':
      onNumerDestroyed(data);
      if ($('#adminModal').hasClass('show') && $('#adminNumera').hasClass('active')) loadAdminNumera();
      break;
    case 'kicked_from_room': onKickedFromRoom(data); break;
    case 'banned_from_room': onKickedFromRoom(data); break;
    case 'muted_in_room':    onMutedInRoom(data); break;
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
function updateRoomBadge(roomId) {
  const count = onlineCountsByRoom.get(Number(roomId)) || 0;
  const $item = $(`.room-item[data-id="${roomId}"]`);
  $item.find('.room-count-badge').remove();
  if (count > 1) {
    $item.append(`<span class="badge bg-secondary ms-1 room-count-badge" style="font-size:.65rem">${count}</span>`);
  }
}

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
    onlineCountsByRoom.forEach((_, roomId) => updateRoomBadge(roomId));
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
    onlineCountsByRoom.forEach((_, roomId) => updateRoomBadge(roomId));
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
  const room = rooms.find(r => Number(r.id) === Number(data.room_id)) || numera.find(r => Number(r.id) === Number(data.room_id)) || {name: 'Комната'};
  currentRoomRole = data.my_role || null;
  $('#room-title').text(room.name || 'Комната');
  const desc = (room.description != null && room.description !== '') ? String(room.description) : '';
  desc ? $('#room-description').text(desc).removeClass('d-none') : $('#room-description').addClass('d-none');
  renderOnlineList(data.online || []);
  onlineCountsByRoom.set(Number(data.room_id), (data.online || []).length);
  updateRoomBadge(data.room_id);

  const canManage = ['platform_owner', 'admin'].includes(CURRENT_USER.global_role) || ['owner', 'local_admin', 'local_moderator'].includes(data.my_role);
  $('#room-manage-btn').toggleClass('d-none', !canManage);
  $('#send-btn').prop('disabled', $('#msg-input').val().trim().length === 0);
}

function onUserJoined(data) {
  if (data.room_id !== currentRoomId) return;
  addToOnlineList(data.user);
  onlineCountsByRoom.set(Number(data.room_id), (onlineCountsByRoom.get(Number(data.room_id)) || 0) + 1);
  updateRoomBadge(data.room_id);
}

function onUserLeft(data) {
  if (data.room_id !== currentRoomId) return;
  removeFromOnlineList(data.user_id);
  const cur = onlineCountsByRoom.get(Number(data.room_id)) || 0;
  onlineCountsByRoom.set(Number(data.room_id), Math.max(0, cur - 1));
  updateRoomBadge(data.room_id);
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
  const deleteBtn = canDelete ? ` <span class="msg-delete-btn" data-id="${m.id}" title="Удалить"><i class="fa fa-trash"></i></span>` : '';

  let embed = '';
  if (m.embed_data) {
    const ed = typeof m.embed_data === 'string' ? JSON.parse(m.embed_data) : m.embed_data;
    embed = `<div class="mt-1">${ed.html || ''}</div>`;
  }

  return `<div class="msg" id="msg-${m.id}">
    <div class="msg-body">
      <span class="msg-time">${time}</span><span class="msg-sep"> » </span><em><span class="msg-username" style="color:${esc(m.nick_color || 'inherit')}">${esc(m.username)}</span> <span class="msg-content msg-inline-content" style="color:${esc(m.text_color || 'inherit')} !important">${m.content}</span>${deleteBtn}</em>
      ${embed}
    </div>
  </div>`;
}

function buildWhisperMessage(m, isSent) {
  const time = dayjs(m.created_at).format('HH:mm:ss');
  const from = m.from || {};
  const to   = m.to   || {};
  const partner = isSent ? to : from;
  const pid = Number(partner.id || 0);
  const pname = esc(partner.username || '');
  const dir = isSent ? 'для' : 'от';
  return `<div class="msg msg-whisper-row" id="msg-${m.message_id}">
    <div class="msg-body">
      <span class="msg-time">${time}</span><span class="msg-sep"> »»» </span><em><span class="msg-username whisper-nick-link" style="cursor:pointer" data-id="${pid}" data-name="${pname}">${pname}</span> <span class="msg-sep small">(шёпот ${dir})</span> <span class="msg-content">${m.content}</span></em>
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

// Delete message
$('#messages-list').on('click', '.msg-delete-btn', function() {
  const msgId = $(this).data('id');
  if (!confirm('Удалить сообщение?')) return;
  wsSend('delete_message', {message_id: msgId});
});

// Whisper nick click → activate whisper mode
$('#messages-list').on('click', '.whisper-nick-link', function() {
  const uid = Number($(this).data('id'));
  const uname = String($(this).data('name') || '');
  if (uid && uname) activateWhisperMode(uid, uname);
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
    const whisperContent = normalizeWhisperContent(content, whisperToName);
    if (!whisperContent) {
      showToast('Введите текст шёпота.', 'warning');
      return;
    }
    wsSend('send_whisper', {
      room_id:     currentRoomId,
      to_user_id:  whisperToId,
      content:     whisperContent,
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

function normalizeWhisperContent(content, username) {
  let text = String(content || '').trim();
  if (!text) return '';

  const target = String(username || '').trim();
  if (target) {
    const escTarget = target.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    text = text.replace(new RegExp(`^@p\\+${escTarget}\\s+`, 'iu'), '');
    text = text.replace(new RegExp(`^${escTarget},\\s*`, 'iu'), '');
  }

  text = text.replace(/^@p\+\S+\s+/iu, '').trim();
  return text;
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
  const uname = String($(this).data('name') || $user.data('username') || $user.find('.online-user-name').text() || '').trim();
  if (!uid) return;

  switch (action) {
    case 'mention':
      insertDirectAddress(uname);
      break;
    case 'whisper':
      activateWhisperMode(uid, uname);
      showToast(`Режим шёпота: @${uname}`);
      break;
    case 'invite':
      wsSend('invite_user', {to_user_id: uid});
      showToast(`Запрос в нумер: ${uname}`);
      break;
    case 'ignore':
      if (!uname) return;
      toggleIgnoreUser(uid, uname);
      break;
    case 'info':
      openUserInfo(uid, uname || (`ID ${uid}`));
      break;
  }
});

function showUserCtxMenu(e, uid, uname) {
  e.preventDefault();
  const $menu = $('#ctx-menu').empty();
  if (uid === Number(CURRENT_USER.id)) {
    $menu.append('<a class="dropdown-item" href="#" data-action="open-settings"><i class="fa fa-user-gear me-2"></i>Профиль и настройки</a>');
    $menu.css({top: e.clientY, left: e.clientX}).show();
    return;
  }

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

function openUserInfo(uid, uname = '') {
  infoUserId = Number(uid || 0);
  infoUsername = String(uname || '').trim();
  if (!infoUserId) return;

  const $body = $('#user-info-body');
  const $actions = $('#user-info-actions');
  $body.html('<div class="text-muted">Загрузка...</div>');
  $actions.empty();

  $.get(`/api/users/${infoUserId}`, function(resp) {
    if (!resp || !resp.success || !resp.user) {
      $body.html('<div class="alert alert-danger mb-0">Не удалось загрузить профиль.</div>');
      return;
    }

    const u = resp.user;
    const showLastSeen = !(Number(u.hide_last_seen || 0) === 1) || canModerateCurrentRoom() || ['platform_owner', 'admin'].includes(CURRENT_USER.global_role);
    const roleText = roleLabel(u.global_role) || roomRoleLabel(u.room_role || '') || 'Пользователь';
    const lastSeenText = showLastSeen
      ? (u.last_seen_at ? dayjs(u.last_seen_at).format('YYYY-MM-DD HH:mm:ss') : 'нет данных')
      : 'скрыт';

    const contacts = [];
    if (u.social_telegram) contacts.push(`<a href="${esc(u.social_telegram)}" target="_blank" rel="noopener noreferrer">Telegram</a>`);
    if (u.social_whatsapp) contacts.push(`<a href="${esc(u.social_whatsapp)}" target="_blank" rel="noopener noreferrer">WhatsApp</a>`);
    if (u.social_vk) contacts.push(`<a href="${esc(u.social_vk)}" target="_blank" rel="noopener noreferrer">VK</a>`);

    $body.html(`
      <div class="d-flex gap-3 align-items-start">
        <div>${avatarMarkup(u.avatar_url, 72)}</div>
        <div class="flex-1">
          <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
            <strong style="color:${esc(u.nick_color || '#fff')}">${esc(u.username || infoUsername || ('ID ' + infoUserId))}</strong>
            <span class="badge bg-secondary">${esc(roleText)}</span>
          </div>
          <div class="small text-muted mb-2">Последний вход: ${esc(lastSeenText)}</div>
          <div class="mb-2"><strong>Статус:</strong> ${esc(u.custom_status || '—')}</div>
          ${u.signature ? `<div class="mb-2"><strong>Подпись:</strong> ${esc(u.signature)}</div>` : ''}
          <div class="mb-2"><strong>О себе:</strong> ${esc(u.bio || '—')}</div>
          <div class="mb-2"><strong>Друзей:</strong> ${Number(u.friend_count || 0)}</div>
          <div><strong>Контакты:</strong> ${contacts.length ? contacts.join(' · ') : '—'}</div>
        </div>
      </div>
    `);

    const isSelf = Number(infoUserId) === Number(CURRENT_USER.id);
    const canModRoom = canModerateCurrentRoom() && !isSelf;
    const canGlobal = ['platform_owner', 'admin', 'moderator'].includes(CURRENT_USER.global_role) && !isSelf;

    $actions.append(`<button type="button" class="btn btn-sm btn-outline-secondary user-info-action-btn" data-action="mention">Обратиться</button>`);
    $actions.append(`<button type="button" class="btn btn-sm btn-outline-secondary user-info-action-btn" data-action="whisper">Шёпот</button>`);
    if (!isSelf) {
      $actions.append(`<button type="button" class="btn btn-sm btn-outline-secondary user-info-action-btn" data-action="invite">В нумер</button>`);
    }

    if (canModRoom) {
      $actions.append(`<button type="button" class="btn btn-sm btn-outline-warning user-info-action-btn" data-action="room-kick">Удалить из комнаты</button>`);
      $actions.append(`<button type="button" class="btn btn-sm btn-outline-danger user-info-action-btn" data-action="room-ban">Бан в комнате</button>`);
      $actions.append(`<button type="button" class="btn btn-sm btn-outline-danger user-info-action-btn" data-action="room-mute">Кляп</button>`);
    }
    if (canGlobal) {
      $actions.append(`<button type="button" class="btn btn-sm btn-danger user-info-action-btn" data-action="ban-global">Глобальный бан</button>`);
    }
  }).fail(function() {
    $body.html('<div class="alert alert-danger mb-0">Не удалось загрузить профиль.</div>');
  });

  new bootstrap.Modal(document.getElementById('userInfoModal')).show();
}

$('#user-info-actions').on('click', '.user-info-action-btn', function() {
  const action = String($(this).data('action') || '');
  const uid = Number(infoUserId || 0);
  const uname = infoUsername || (`ID ${uid}`);
  if (!uid) return;

  switch (action) {
    case 'mention':
      insertDirectAddress(uname);
      break;
    case 'whisper':
      activateWhisperMode(uid, uname);
      break;
    case 'invite':
      wsSend('invite_user', {to_user_id: uid});
      showToast(`Запрос в нумер: ${uname}`);
      break;
    case 'room-kick':
      executeRoomAction('kick', uid, 'Удалить пользователя из комнаты?');
      break;
    case 'room-ban':
      executeRoomAction('ban', uid, 'Забанить пользователя в комнате?');
      break;
    case 'room-mute': {
      const minutesRaw = prompt('Кляп на сколько минут? (1-1440)', '15');
      if (minutesRaw === null) return;
      const minutes = Math.max(1, Math.min(1440, Number(minutesRaw) || 15));
      const reason = prompt('Причина кляпа (необязательно):', '') || '';
      executeRoomAction('mute', uid, null, {minutes, reason});
      break;
    }
    case 'ban-global':
      if (confirm('Забанить пользователя глобально?')) {
        $.post(`/api/admin/users/${uid}`, {csrf_token: CSRF_TOKEN, is_banned: 1}, () => showToast('Пользователь заблокирован.'));
      }
      break;
  }
});

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
      activateWhisperMode(uid, uname);
      break;
    case 'invite':
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
    case 'open-settings':
      new bootstrap.Modal(document.getElementById('settingsModal')).show();
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
  loadRooms();
}

function onInviteAccepted(data) {
  showToast('Приглашение принято: ' + (data.user?.username || ''));
  loadRooms();
  if (data.room_id) {
    joinRoom(Number(data.room_id), true);
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
  let countdown = 30;
  $('#invite-modal-body').html(`
    <p><strong>${esc(from.username)}</strong> приглашает вас в нумер.</p>
    <p class="text-muted small">Приглашение истекает через <span id="invite-countdown">30</span> сек.</p>
    <div class="d-flex gap-2">
      <button class="btn btn-success flex-1" id="accept-invite" data-id="${inv.invitation_id}">Принять</button>
      <button class="btn btn-outline-secondary flex-1" id="decline-invite" data-id="${inv.invitation_id}">Отклонить</button>
    </div>
  `);
  new bootstrap.Modal(document.getElementById('inviteModal')).show();

  const countdownInterval = setInterval(() => {
    countdown--;
    $('#invite-countdown').text(countdown);
    if (countdown <= 0) clearInterval(countdownInterval);
  }, 1000);

  const timer = setTimeout(() => {
    clearInterval(countdownInterval);
    bootstrap.Modal.getInstance(document.getElementById('inviteModal'))?.hide();
  }, 30000);

  function cleanup() { clearTimeout(timer); clearInterval(countdownInterval); }

  $('#accept-invite').one('click', function() {
    cleanup();
    wsSend('invite_respond', {invitation_id: inv.invitation_id, response: 'accept'});
    bootstrap.Modal.getInstance(document.getElementById('inviteModal'))?.hide();
  });
  $('#decline-invite').one('click', function() {
    cleanup();
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

function onMutedInRoom(data) {
  if (data.room_id !== currentRoomId) return;
  const until = data.muted_until ? dayjs(data.muted_until).format('HH:mm:ss') : '';
  const reason = data.reason ? ` Причина: ${data.reason}` : '';
  showToast(`Вам выдан кляп${until ? ` до ${until}` : ''}.${reason}`, 'warning');
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
function openSettingsModal() {
  const modal = new bootstrap.Modal(document.getElementById('settingsModal'));
  $('[name="username"]').val(CURRENT_USER.username);
  $('[name="signature"]').val(CURRENT_USER.signature || '');
  $('[name="bio"]').val(CURRENT_USER.bio || '');
  $('[name="social_telegram"]').val(CURRENT_USER.social_telegram || '');
  $('[name="social_whatsapp"]').val(CURRENT_USER.social_whatsapp || '');
  $('[name="social_vk"]').val(CURRENT_USER.social_vk || '');
  $('[name="custom_status"]').val(CURRENT_USER.custom_status || '');
  $('[name="nick_color"]').val(CURRENT_USER.nick_color || '#ffffff');
  $('[name="text_color"]').val(CURRENT_USER.text_color || '#dee2e6');
  $('#hideLastSeenSetting').prop('checked', Number(CURRENT_USER.hide_last_seen || 0) === 1);
  $('#showSystemMessagesSetting').prop('checked', shouldShowSystemMessages());
  modal.show();

  $.get(`/api/users/${CURRENT_USER.id}`, function(resp) {
    if (!resp || !resp.success || !resp.user) return;
    Object.assign(CURRENT_USER, resp.user);
    $('[name="signature"]').val(resp.user.signature || '');
    $('[name="bio"]').val(resp.user.bio || '');
    $('[name="social_telegram"]').val(resp.user.social_telegram || '');
    $('[name="social_whatsapp"]').val(resp.user.social_whatsapp || '');
    $('[name="social_vk"]').val(resp.user.social_vk || '');
    $('[name="custom_status"]').val(resp.user.custom_status || '');
    $('#hideLastSeenSetting').prop('checked', Number(resp.user.hide_last_seen || 0) === 1);
  });

  $('[name="nick_color"]').trigger('input');
  $('[name="text_color"]').trigger('input');
}

function initSettings() {
  const colorValidity = { nick_color: true, text_color: true };

  function syncSettingsSaveState() {
    const ok = colorValidity.nick_color && colorValidity.text_color;
    $('#settings-save-btn').prop('disabled', !ok);
  }

  $('#showSystemMessagesSetting').on('change', function() {
    localStorage.setItem('show_system_messages', $(this).is(':checked') ? '1' : '0');
  });

  let usernameCheckTimer = null;
  $('#usernameInput').on('input', function() {
    const val = $(this).val().trim();
    clearTimeout(usernameCheckTimer);
    const $fb = $('#username-check').html('');
    if (val === CURRENT_USER.username || val.length < 3) return;
    usernameCheckTimer = setTimeout(function() {
      $.get('/api/users/check', {username: val}, function(r) {
        $fb.html(r.available
          ? '<span class="text-success"><i class="fa fa-check"></i> Свободен</span>'
          : '<span class="text-danger"><i class="fa fa-times"></i> Занят</span>');
      });
    }, 400);
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
    fd.set('hide_last_seen', $('#hideLastSeenSetting').is(':checked') ? '1' : '0');
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
  $('#settings-btn, #my-avatar, #my-username-wrap').on('click', openSettingsModal);

  $('#createRoomBtn').on('click', function() {
    $('#createRoomForm')[0].reset();
    $('#create-room-error').addClass('d-none');
    new bootstrap.Modal(document.getElementById('createRoomModal')).show();
  });

  $('#createRoomForm').on('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    fd.set('csrf_token', CSRF_TOKEN);
    $.ajax({
      url: '/api/rooms',
      method: 'POST',
      data: fd,
      processData: false,
      contentType: false,
      success: function(resp) {
        if (resp.success) {
          bootstrap.Modal.getInstance(document.getElementById('createRoomModal'))?.hide();
          loadRooms();
          if (resp.room_id) setTimeout(() => joinRoom(resp.room_id), 300);
        } else {
          $('#create-room-error').text(resp.error || 'Ошибка').removeClass('d-none');
        }
      },
      error: function(xhr) {
        $('#create-room-error').text(xhr.responseJSON?.error || 'Не удалось создать комнату.').removeClass('d-none');
      }
    });
  });

  $('#room-manage-btn').on('click', function() {
    if (!currentRoomId) return;
    const room = rooms.find(r => Number(r.id) === Number(currentRoomId)) || numera.find(r => Number(r.id) === Number(currentRoomId));
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
    if (tab === '#adminDash')     loadAdminDash();
    if (tab === '#adminUsers')    loadAdminUsers();
    if (tab === '#adminRooms')    loadAdminRooms();
    if (tab === '#adminNumera')   loadAdminNumera();
    if (tab === '#adminWhispers') loadAdminWhispers();
    if (tab === '#adminBans')     loadAdminBans();
    if (tab === '#adminSettings') loadAdminSettings();
  });

  $('#admin-user-search-btn').on('click', loadAdminUsers);
  $('#whisper-search-btn').on('click', loadAdminWhispers);

  $('#admin-create-user-btn').on('click', function() {
    $('#createUserForm')[0].reset();
    $('#create-user-error').addClass('d-none');
    new bootstrap.Modal(document.getElementById('createUserModal')).show();
  });

  $('#createUserForm').on('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    fd.set('csrf_token', CSRF_TOKEN);
    $.ajax({
      url: '/api/admin/users',
      method: 'POST',
      data: fd,
      processData: false,
      contentType: false,
      success: function(resp) {
        if (resp.success) {
          bootstrap.Modal.getInstance(document.getElementById('createUserModal'))?.hide();
          showToast('Пользователь создан.', 'success');
          loadAdminUsers();
        } else {
          $('#create-user-error').text(resp.error || 'Ошибка').removeClass('d-none');
        }
      },
      error: function(xhr) {
        $('#create-user-error').text(xhr.responseJSON?.error || 'Не удалось создать.').removeClass('d-none');
      }
    });
  });
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
    headers: {'X-CSRF-Token': CSRF_TOKEN},
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
    const catLabel = {permanent:'Постоянная', user:'Пользовательская', commercial:'Коммерческая'};
    const catColor = {permanent:'secondary', user:'primary', commercial:'warning'};
    let html = '<table class="table table-sm"><thead><tr><th>ID</th><th>Название</th><th>Категория</th><th>Участников</th><th>Сообщений</th><th>Владелец</th><th>Дней</th><th></th></tr></thead><tbody>';
    resp.rooms.forEach(r => {
      const cat = r.room_category || 'user';
      const delBtn = cat !== 'permanent'
        ? `<button class="btn btn-sm btn-danger room-del-btn" data-id="${r.id}" title="Удалить"><i class="fa fa-trash"></i></button>`
        : `<span class="text-muted small">—</span>`;
      html += `<tr>
        <td>${r.id}</td>
        <td>${esc(r.name)}</td>
        <td><span class="badge bg-${catColor[cat]||'secondary'}">${catLabel[cat]||cat}</span></td>
        <td>${r.member_count}</td>
        <td>${r.message_count}</td>
        <td>${esc(r.owner_username||'—')}</td>
        <td>${r.days_running ?? 0}</td>
        <td class="d-flex gap-1">
          <button class="btn btn-sm btn-outline-info room-history-btn" data-id="${r.id}" data-name="${esc(r.name)}" title="История"><i class="fa fa-clock-rotate-left"></i></button>
          ${delBtn}
        </td></tr>`;
    });
    html += '</tbody></table>';
    $('#admin-rooms-table').html(html);
  });
}
$('#admin-rooms-table').on('click', '.room-del-btn', function() {
  const id = $(this).data('id');
  if (!confirm('Удалить комнату?')) return;
  $.ajax({
    url: `/api/admin/rooms/${id}`,
    method: 'DELETE',
    headers: {'X-CSRF-Token': CSRF_TOKEN},
    success: () => { showToast('Удалена.', 'success'); loadAdminRooms(); },
    error: (xhr) => showToast(xhr.responseJSON?.error || 'Не удалось удалить.', 'danger'),
  });
});

function loadAdminNumera() {
  $.get('/api/admin/numera', function(resp) {
    if (!resp.success) return;
    if (!resp.numera.length) {
      $('#admin-numera-table').html('<div class="text-muted p-2">Активных нумеров нет.</div>');
      return;
    }
    const fmtDuration = (min) => {
      if (min < 60) return `${min} мин`;
      const h = Math.floor(min / 60), m = min % 60;
      return `${h}ч ${m}м`;
    };
    let html = '<table class="table table-sm"><thead><tr><th>ID</th><th>Создан</th><th>Создатель</th><th>Участники</th><th>Кол-во</th><th>Идёт</th><th></th></tr></thead><tbody>';
    resp.numera.forEach(r => {
      const started = r.created_at ? r.created_at.slice(0, 16).replace('T', ' ') : '—';
      const duration = fmtDuration(Number(r.minutes_running) || 0);
      const statusDot = Number(r.member_count) > 0
        ? '<span class="badge bg-success">Активен</span>'
        : '<span class="badge bg-warning text-dark">Завис</span>';
      html += `<tr>
        <td>${r.id}</td>
        <td>${started}</td>
        <td>${esc(r.owner_username||'—')}</td>
        <td class="small">${esc(r.participants||'—')}</td>
        <td>${statusDot} ${r.member_count}</td>
        <td>${duration}</td>
        <td><button class="btn btn-sm btn-outline-info numer-history-btn" data-id="${r.id}" title="История"><i class="fa fa-clock-rotate-left"></i></button></td></tr>`;
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

function loadAdminBans() {
  $.get('/api/admin/bans', function(resp) {
    if (!resp.success) { $('#admin-bans-table').html('<div class="text-muted">Нет данных.</div>'); return; }
    if (!resp.bans || !resp.bans.length) {
      $('#admin-bans-table').html('<div class="text-muted p-2">Заблокированных нет.</div>');
      return;
    }
    let html = '<table class="table table-sm"><thead><tr><th>Пользователь</th><th>Комната</th><th>Роль</th><th>Кляп до</th><th></th></tr></thead><tbody>';
    resp.bans.forEach(b => {
      const mutedUntil = b.muted_until ? b.muted_until.slice(0,16) : '—';
      const unbanBtn = b.room_role === 'banned'
        ? `<button class="btn btn-xs btn-sm btn-outline-success admin-unban-btn" data-room="${b.room_id}" data-user="${b.user_id}" title="Разбанить"><i class="fa fa-unlock"></i></button>`
        : '';
      const unmuteBtn = b.muted_until
        ? `<button class="btn btn-xs btn-sm btn-outline-warning admin-unmute-btn" data-room="${b.room_id}" data-user="${b.user_id}" title="Снять кляп"><i class="fa fa-comment"></i></button>`
        : '';
      html += `<tr><td>${esc(b.username)}</td><td>${esc(b.room_name||'—')}</td><td>${esc(b.room_role||'—')}</td><td>${mutedUntil}</td><td class="d-flex gap-1">${unbanBtn}${unmuteBtn}</td></tr>`;
    });
    html += '</tbody></table>';
    $('#admin-bans-table').html(html);
  });
}

$('#admin-bans-table').on('click', '.admin-unban-btn', function() {
  const roomId = $(this).data('room'), userId = $(this).data('user');
  $.post(`/api/admin/rooms/${roomId}/unban/${userId}`, {csrf_token: CSRF_TOKEN}, function(resp) {
    if (resp.success) { showToast('Разбанен.', 'success'); loadAdminBans(); }
    else showToast(resp.error || 'Ошибка.', 'danger');
  }, 'json');
});

$('#admin-bans-table').on('click', '.admin-unmute-btn', function() {
  const roomId = $(this).data('room'), userId = $(this).data('user');
  $.post(`/api/admin/rooms/${roomId}/unmute/${userId}`, {csrf_token: CSRF_TOKEN}, function(resp) {
    if (resp.success) { showToast('Кляп снят.', 'success'); loadAdminBans(); }
    else showToast(resp.error || 'Ошибка.', 'danger');
  }, 'json');
});

$('#admin-rooms-table').on('click', '.room-history-btn', function() {
  const id = $(this).data('id'), name = $(this).data('name');
  openRoomHistory(id, name);
});

$('#admin-numera-table').on('click', '.numer-history-btn', function() {
  const id = $(this).data('id');
  openNumerHistory(id);
});

function openRoomHistory(roomId, roomName) {
  $.get(`/api/admin/rooms/${roomId}/messages`, function(resp) {
    if (!resp.success) { showToast('Не удалось загрузить историю.', 'danger'); return; }
    let html = `<h6>${esc(roomName)} — история</h6>`;
    if (!resp.messages || !resp.messages.length) {
      html += '<div class="text-muted">Сообщений нет.</div>';
    } else {
      html += '<div style="max-height:400px;overflow-y:auto"><table class="table table-sm table-striped"><thead><tr><th>Время</th><th>От</th><th>Сообщение</th></tr></thead><tbody>';
      resp.messages.forEach(m => {
        html += `<tr><td style="white-space:nowrap">${esc(String(m.created_at||'').slice(0,16))}</td><td>${esc(m.username||'—')}</td><td>${esc(m.content||'')}</td></tr>`;
      });
      html += '</tbody></table></div>';
    }
    $('#room-manage-body').html(html);
    new bootstrap.Modal(document.getElementById('roomManageModal')).show();
  });
}

function openNumerHistory(numerId) {
  $.get(`/api/admin/numera/${numerId}/messages`, function(resp) {
    if (!resp.success) { showToast('Не удалось загрузить историю.', 'danger'); return; }
    let html = `<h6>Нумер #${numerId} — история</h6>`;
    if (!resp.messages || !resp.messages.length) {
      html += '<div class="text-muted">Сообщений нет.</div>';
    } else {
      html += '<div style="max-height:400px;overflow-y:auto"><table class="table table-sm table-striped"><thead><tr><th>Время</th><th>От</th><th>Сообщение</th></tr></thead><tbody>';
      resp.messages.forEach(m => {
        html += `<tr><td style="white-space:nowrap">${esc(String(m.created_at||'').slice(0,16))}</td><td>${esc(m.username||'—')}</td><td>${esc(m.content||'')}</td></tr>`;
      });
      html += '</tbody></table></div>';
    }
    $('#room-manage-body').html(html);
    new bootstrap.Modal(document.getElementById('roomManageModal')).show();
  });
}

function loadAdminSettings() {
  $.get('/api/admin/system-settings', function(resp) {
    if (!resp.success) return;
    const s = resp.settings;
    $('[name="datetime_format"]').val(s.datetime_format || '');
    $('[name="time_format"]').val(s.time_format || '');
    $('[name="system_message_color"]').val(s.system_message_color || '#DEC8A4');
    $('[name="system_theme"]').val(s.system_theme || 'auto');
    $('#admin-reg-enabled').prop('checked', s.registration_enabled === '1');
    $('#admin-maint-mode').prop('checked', s.maintenance_mode === '1');
    $('[name="maintenance_message"]').val(s.maintenance_message || '');
    const color = s.system_message_color || '#DEC8A4';
    $('#sys-msg-color-preview').css('color', color).text('Системное');
  });
}

$('#adminSettingsForm').on('submit', function(e) {
  e.preventDefault();
  const data = {
    csrf_token: CSRF_TOKEN,
    datetime_format: $('[name="datetime_format"]').val(),
    time_format: $('[name="time_format"]').val(),
    system_message_color: $('[name="system_message_color"]').val(),
    system_theme: $('[name="system_theme"]').val(),
    registration_enabled: $('#admin-reg-enabled').is(':checked') ? '1' : '0',
    maintenance_mode: $('#admin-maint-mode').is(':checked') ? '1' : '0',
    maintenance_message: $('[name="maintenance_message"]').val(),
  };
  $.post('/api/admin/system-settings', data, function(resp) {
    if (resp.success) {
      $('#admin-settings-success').removeClass('d-none');
      $('#admin-settings-error').addClass('d-none');
      document.documentElement.style.setProperty('--sys-msg-color', data.system_message_color);
      setTimeout(() => $('#admin-settings-success').addClass('d-none'), 3000);
    } else {
      $('#admin-settings-error').text(resp.error || 'Ошибка').removeClass('d-none');
    }
  }, 'json').fail(function(xhr) {
    $('#admin-settings-error').text(xhr.responseJSON?.error || 'Не удалось сохранить.').removeClass('d-none');
  });
});

$('#sysMsgColorPicker').on('input', function() {
  $('#sys-msg-color-preview').css('color', $(this).val());
});

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
