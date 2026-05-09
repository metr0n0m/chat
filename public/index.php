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
$_sysMsgColorLight = '#7a6a4a';
$_sysMsgColorDark  = '#DEC8A4';
$_timeFormat       = 'HH:mm:ss';
$_datetimeFormat   = 'DD.MM.YY HH:mm';
try {
    $db = \Chat\DB\Connection::getInstance();
    $row = $db->fetchOne("SELECT value FROM app_settings WHERE name = 'system_message_color_light'");
    if ($row && !empty($row['value'])) $_sysMsgColorLight = (string) $row['value'];
    $row = $db->fetchOne("SELECT value FROM app_settings WHERE name = 'system_message_color_dark'");
    if ($row && !empty($row['value'])) $_sysMsgColorDark = (string) $row['value'];
    $row = $db->fetchOne("SELECT value FROM app_settings WHERE name = 'time_format'");
    if ($row && !empty($row['value'])) $_timeFormat = (string) $row['value'];
    $row = $db->fetchOne("SELECT value FROM app_settings WHERE name = 'datetime_format'");
    if ($row && !empty($row['value'])) $_datetimeFormat = (string) $row['value'];
} catch (\Throwable $e) {}
$userJson = $user ? json_encode([
    'id'             => (int) $user['id'],
    'username'       => $user['username'],
    'nick_color'     => $user['nick_color'],
    'text_color'     => $user['text_color'],
    'avatar_url'     => $user['avatar_url'],
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
:root { --sys-msg-color-light: <?= htmlspecialchars($_sysMsgColorLight, ENT_QUOTES) ?>; --sys-msg-color-dark: <?= htmlspecialchars($_sysMsgColorDark, ENT_QUOTES) ?>; }
</style>
<link rel="stylesheet" href="/assets/css/chat.css">
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
    <div class="modal-body">
      <form id="settingsForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
        <ul class="nav nav-tabs mb-3">
          <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#sTab1">Профиль</a></li>
          <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#sTab2">Внешний вид</a></li>
          <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#sTab3">Аватар</a></li>
          <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#sTab4">Безопасность</a></li>
        </ul>
        <div class="tab-content">
          <div class="tab-pane fade show active" id="sTab1">
            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label">Имя пользователя (ник)</label>
                <input type="text" class="form-control" name="username" minlength="3" maxlength="50" id="usernameInput" autocomplete="off">
                <div id="username-check" class="small mt-1"></div>
              </div>
              <div class="col-md-6">
                <label class="form-label">Статус (до 80 символов)</label>
                <input type="text" class="form-control" name="custom_status" maxlength="80" placeholder="Например: В отпуске">
              </div>
              <div class="col-12">
                <label class="form-label">О себе (до 500 символов)</label>
                <textarea class="form-control" name="bio" maxlength="500" rows="2"></textarea>
              </div>
              <div class="col-md-4">
                <label class="form-label">Telegram</label>
                <input type="url" class="form-control form-control-sm" name="social_telegram" placeholder="https://t.me/...">
              </div>
              <div class="col-md-4">
                <label class="form-label">WhatsApp</label>
                <input type="url" class="form-control form-control-sm" name="social_whatsapp" placeholder="https://wa.me/...">
              </div>
              <div class="col-md-4">
                <label class="form-label">VK</label>
                <input type="url" class="form-control form-control-sm" name="social_vk" placeholder="https://vk.com/...">
              </div>
            </div>
          </div>
          <div class="tab-pane fade" id="sTab2">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Цвет ника</label>
                <div class="d-flex align-items-center gap-2 mb-1">
                  <input type="color" class="form-control form-control-color" name="nick_color" id="nickColorPicker" style="width:46px;height:38px;padding:2px">
                  <div class="color-preview-box" id="nick-preview-light" style="background:#f8f9fa">Ник</div>
                  <div class="color-preview-box" id="nick-preview-dark" style="background:#212529">Ник</div>
                </div>
              </div>
              <div class="col-md-6">
                <label class="form-label">Цвет текста</label>
                <div class="d-flex align-items-center gap-2 mb-1">
                  <input type="color" class="form-control form-control-color" name="text_color" id="textColorPicker" style="width:46px;height:38px;padding:2px">
                  <div class="color-preview-box" id="text-preview-light" style="background:#f8f9fa">Текст</div>
                  <div class="color-preview-box" id="text-preview-dark" style="background:#212529">Текст</div>
                </div>
              </div>
            </div>
          </div>
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
          <div class="tab-pane fade" id="sTab4">
            <div class="row g-3">
              <div class="col-12">
                <label class="form-label">Новый пароль (оставьте пустым чтобы не менять)</label>
                <input type="password" class="form-control" name="password" minlength="8">
              </div>
              <div class="col-12">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="showSystemMessagesSetting">
                  <label class="form-check-label" for="showSystemMessagesSetting">Показывать сервисные сообщения в чате</label>
                </div>
                <div class="form-check mt-1">
                  <input class="form-check-input" type="checkbox" id="hideLastSeenSetting" name="hide_last_seen" value="1">
                  <label class="form-check-label" for="hideLastSeenSetting">Скрывать последний визит от других пользователей</label>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div id="settings-error" class="alert alert-danger mt-3 d-none"></div>
        <div id="settings-success" class="alert alert-success mt-3 d-none">Настройки сохранены.</div>
        <button type="submit" class="btn btn-primary mt-3" id="settings-save-btn">Сохранить</button>
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
              <label class="form-label">Цвет системных сообщений (светлая тема)</label>
              <div class="d-flex align-items-center gap-2">
                <input type="color" class="form-control form-control-color" name="system_message_color_light" style="width:46px;height:38px;padding:2px">
                <div id="sys-msg-color-preview-light" style="background:#f8f9fa;padding:2px 8px;border-radius:4px;font-style:italic;font-size:.82rem">Системное</div>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Цвет системных сообщений (тёмная тема)</label>
              <div class="d-flex align-items-center gap-2">
                <input type="color" class="form-control form-control-color" name="system_message_color_dark" style="width:46px;height:38px;padding:2px">
                <div id="sys-msg-color-preview-dark" style="background:#212529;padding:2px 8px;border-radius:4px;font-style:italic;font-size:.82rem">Системное</div>
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
window.ChatConfig = {
  csrfToken: <?= json_encode($csrfToken) ?>,
  currentUser: <?= $userJson ?>,
  timeFormat: <?= json_encode($_timeFormat) ?>,
  datetimeFormat: <?= json_encode($_datetimeFormat) ?>
};
window.CHAT_BOOTSTRAP = window.ChatConfig;
</script>
<script nonce="<?= $nonce ?>" src="/assets/js/chat-utils.js"></script>
<script nonce="<?= $nonce ?>" src="/assets/js/chat-display.js"></script>
<script nonce="<?= $nonce ?>" src="/assets/js/chat-input.js"></script>
<script nonce="<?= $nonce ?>" src="/assets/js/chat.js"></script>
<script nonce="<?= $nonce ?>" src="/assets/js/chat-auth.js"></script>
</body>
</html>
