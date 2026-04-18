<?php
declare(strict_types=1);

namespace Chat\Chat;

use Chat\DB\Connection;

class NumerPage
{
    public static function render(int $roomId, array $user): never
    {
        $db = Connection::getInstance();

        $room = $db->fetchOne(
            "SELECT id, name, owner_id FROM rooms WHERE id = ? AND type = 'numer' AND is_closed = 0",
            [$roomId]
        );
        if (!$room) {
            http_response_code(404);
            self::errorPage('Нумер не найден или уже закрыт.');
        }

        $userId = (int) $user['id'];
        $member = $db->fetchOne(
            "SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ? AND room_role != 'banned'",
            [$roomId, $userId]
        );
        if (!$member) {
            http_response_code(403);
            self::errorPage('У вас нет доступа к этому нумеру.');
        }

        $members = $db->fetchAll(
            "SELECT u.id, u.username, u.nick_color, u.avatar_url, rm.room_role
             FROM room_members rm
             JOIN users u ON u.id = rm.user_id
             WHERE rm.room_id = ? AND rm.room_role != 'banned'
             ORDER BY rm.joined_at ASC",
            [$roomId]
        );

        $messages = array_reverse($db->fetchAll(
            "SELECT m.id, m.content, m.created_at, m.type, u.username, u.nick_color
             FROM messages m
             LEFT JOIN users u ON u.id = m.user_id
             WHERE m.room_id = ? AND m.type != 'system'
             ORDER BY m.id DESC LIMIT 80",
            [$roomId]
        ));

        $roomNameHtml = htmlspecialchars((string) $room['name'], ENT_QUOTES);
        $membersJson  = json_encode($members,  JSON_HEX_TAG | JSON_HEX_AMP);
        $messagesJson = json_encode($messages, JSON_HEX_TAG | JSON_HEX_AMP);
        $meJson       = json_encode([
            'id'         => $userId,
            'username'   => $user['username'],
            'nick_color' => $user['nick_color'] ?? null,
            'text_color' => $user['text_color'] ?? null,
            'global_role'=> $user['global_role'],
        ], JSON_HEX_TAG | JSON_HEX_AMP);

        header('Content-Type: text/html; charset=UTF-8');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' cdn.jsdelivr.net cdnjs.cloudflare.com 'unsafe-inline'; style-src 'self' 'unsafe-inline' cdn.jsdelivr.net cdnjs.cloudflare.com; img-src * data:; connect-src 'self' ws: wss:; font-src cdn.jsdelivr.net cdnjs.cloudflare.com;");
        echo self::html($roomId, $roomNameHtml, $membersJson, $messagesJson, $meJson);
        exit;
    }

    private static function errorPage(string $msg): never
    {
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Нумер</title>'
           . '<style>body{background:#1a1a1a;color:#aaa;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}</style>'
           . '</head><body><p>' . htmlspecialchars($msg, ENT_QUOTES) . '</p></body></html>';
        exit;
    }

    private static function html(int $roomId, string $roomName, string $membersJson, string $messagesJson, string $meJson): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="ru" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Нумер — {$roomName}</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  * { box-sizing: border-box; }
  html, body { height: 100%; margin: 0; overflow: hidden; }
  body { display: flex; flex-direction: column; background: #1a1c1e; color: #cdd0d4; font-size: .9rem; }
  #layout { display: flex; flex: 1; min-height: 0; }
  #msg-col { display: flex; flex-direction: column; flex: 1; min-width: 0; }
  #messages { flex: 1; overflow-y: auto; padding: 10px 12px; display: flex; flex-direction: column; gap: 1px; }
  .msg { padding: 2px 0; word-break: break-word; }
  .msg-time { color: #8a7a5a; font-size: .78rem; }
  .msg-sep  { color: #8a7a5a; }
  #input-row { display: flex; gap: 6px; padding: 8px 10px; border-top: 1px solid #2e3035; flex-shrink: 0; }
  #msg-input { flex: 1; background: #2a2c30; border: 1px solid #3a3c42; color: #cdd0d4; border-radius: 6px; padding: 6px 10px; resize: none; min-height: 34px; max-height: 80px; font-size: .9rem; }
  #msg-input:focus { outline: none; border-color: #5a6aaa; }
  #send-btn { background: #3d5afe; border: none; color: #fff; border-radius: 6px; padding: 6px 14px; cursor: pointer; font-size: .9rem; white-space: nowrap; }
  #send-btn:hover { background: #5370ff; }
  #right-col { width: 160px; flex-shrink: 0; border-left: 1px solid #2e3035; background: #16181a; display: flex; flex-direction: column; padding: 0; overflow-y: auto; }
  .r-section { padding: 10px 10px 8px; border-bottom: 1px solid #2e3035; }
  .r-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; color: #666; margin-bottom: 6px; }
  .r-name  { font-size: .85rem; }
  #participant-list { display: flex; flex-direction: column; gap: 4px; }
  .p-item { display: flex; align-items: center; gap: 5px; font-size: .83rem; }
  .p-dot  { width: 7px; height: 7px; border-radius: 50%; background: #28a745; flex-shrink: 0; }
  #leave-btn { width: 100%; background: transparent; border: 1px solid #8b3a3a; color: #e07a7a; border-radius: 5px; padding: 5px 8px; cursor: pointer; font-size: .82rem; transition: background .15s; }
  #leave-btn:hover { background: #5a2020; }
  #countdown-box { padding: 8px 10px; font-size: .78rem; color: #c9a03a; border-top: 1px solid #2e3035; display: none; }
  a.refresh-link { color: #8a9bcc; font-size: .82rem; text-decoration: none; }
  a.refresh-link:hover { text-decoration: underline; }
  #status-bar { padding: 4px 10px; font-size: .75rem; color: #555; border-bottom: 1px solid #2e3035; flex-shrink: 0; }
</style>
</head>
<body>
<div id="status-bar">Подключение...</div>
<div id="layout">
  <div id="msg-col">
    <div id="messages"></div>
    <div id="input-row">
      <textarea id="msg-input" rows="1" placeholder="Сообщение..."></textarea>
      <button id="send-btn">&#x2192;</button>
    </div>
  </div>
  <div id="right-col">
    <div class="r-section" style="text-align:center">
      <a href="javascript:refreshNumer()" class="refresh-link"><i class="fa fa-rotate-right me-1"></i>Обновить</a>
    </div>
    <div class="r-section">
      <div class="r-label">Хозяин номера</div>
      <div id="owner-name" class="r-name">—</div>
    </div>
    <div class="r-section" style="flex:1">
      <div class="r-label">В номере</div>
      <div id="participant-list"></div>
    </div>
    <div id="invite-section" class="r-section" style="display:none;position:relative">
      <div class="r-label">Пригласить</div>
      <button id="invite-btn" style="width:100%;background:transparent;border:1px solid #3a5a3a;color:#7ab07a;border-radius:4px;padding:4px 6px;font-size:.8rem;cursor:pointer"><i class="fa fa-user-plus me-1"></i>Онлайн</button>
      <div id="invite-dropdown" style="display:none;position:absolute;bottom:calc(100% + 4px);left:0;right:0;background:#22262a;border:1px solid #3a3c42;border-radius:6px;max-height:180px;overflow-y:auto;z-index:100;box-shadow:0 -4px 16px rgba(0,0,0,.4)">
        <div id="invite-list"></div>
      </div>
      <div id="invite-status" style="font-size:.75rem;margin-top:4px;color:#888"></div>
    </div>
    <div class="r-section">
      <button id="leave-btn"><i class="fa fa-door-open me-1"></i>Покинуть</button>
    </div>
    <div id="countdown-box"><i class="fa fa-hourglass-half me-1"></i>Закрытие через <span id="countdown-time">30:00</span></div>
  </div>
</div>

<script>
const ROOM_ID   = {$roomId};
const ME        = {$meJson};
let members     = {$membersJson};
const initMsgs  = {$messagesJson};

let ws = null;
let countdownTimer = null;

function esc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function setStatus(txt) { document.getElementById('status-bar').textContent = txt; }

// ── Participants ──────────────────────────────────────────────
function renderParticipants() {
  const owner = members.find(m => m.room_role === 'owner');
  const ownerEl = document.getElementById('owner-name');
  if (owner) {
    ownerEl.innerHTML = `<span style="color:\${esc(owner.nick_color||'inherit')}">\u25cf \${esc(owner.username)}</span>`;
  } else {
    ownerEl.textContent = '—';
  }
  const list = document.getElementById('participant-list');
  list.innerHTML = '';
  members.forEach(m => {
    const d = document.createElement('div');
    d.className = 'p-item';
    d.innerHTML = `<span class="p-dot"></span><span style="color:\${esc(m.nick_color||'inherit')}">\${esc(m.username)}</span>`;
    list.appendChild(d);
  });
  // Show invite section only for owner and if room not full
  const isOwner = members.some(m => m.id == ME.id && m.room_role === 'owner');
  const invSec = document.getElementById('invite-section');
  if (invSec) invSec.style.display = (isOwner && members.length < 4) ? '' : 'none';
}

function refreshNumer() {
  fetch('/api/rooms/' + ROOM_ID + '/members')
    .then(r => r.json())
    .then(d => { if (d.members) { members = d.members; renderParticipants(); } })
    .catch(() => {});
}

// ── Messages ──────────────────────────────────────────────────
function appendMsg(m) {
  if (!m || m.type === 'system') return;
  const el = document.createElement('div');
  el.className = 'msg';
  const t = m.created_at ? new Date(m.created_at.replace(' ','T')).toLocaleTimeString('ru',{hour:'2-digit',minute:'2-digit',second:'2-digit'}) : '';
  el.innerHTML = `<span class="msg-time">\${esc(t)}</span><span class="msg-sep"> \u00bb </span><em><span style="color:\${esc(m.nick_color||'inherit')};font-weight:600">\${esc(m.username||'')}</span>: \${esc(m.content)}</em>`;
  const box = document.getElementById('messages');
  const atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 60;
  box.appendChild(el);
  if (atBottom) box.scrollTop = box.scrollHeight;
}

function appendSys(text) {
  const el = document.createElement('div');
  el.style.cssText = 'text-align:center;font-style:italic;color:#8a7a5a;font-size:.78rem;padding:2px 0';
  el.textContent = text;
  document.getElementById('messages').appendChild(el);
}

// ── Countdown ────────────────────────────────────────────────
function startCountdown(seconds) {
  stopCountdown();
  const box = document.getElementById('countdown-box');
  box.style.display = 'block';
  let rem = seconds;
  const fmt = s => Math.floor(s/60) + ':' + String(s%60).padStart(2,'0');
  document.getElementById('countdown-time').textContent = fmt(rem);
  countdownTimer = setInterval(() => {
    rem--;
    if (rem <= 0) { stopCountdown(); return; }
    document.getElementById('countdown-time').textContent = fmt(rem);
  }, 1000);
}

function stopCountdown() {
  if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
  document.getElementById('countdown-box').style.display = 'none';
}

// ── WebSocket ────────────────────────────────────────────────
function connect() {
  const proto = location.protocol === 'https:' ? 'wss' : 'ws';
  ws = new WebSocket(`\${proto}://\${location.host}/wss`);

  ws.onopen = () => {
    setStatus('Соединено');
    ws.send(JSON.stringify({event:'join_room', room_id: ROOM_ID}));
  };

  ws.onclose = () => {
    setStatus('Соединение разорвано. Переподключение...');
    setTimeout(connect, 3000);
  };

  ws.onerror = () => ws.close();

  ws.onmessage = e => {
    let d;
    try { d = JSON.parse(e.data); } catch { return; }
    switch (d.event) {
      case 'room_joined':
        // Keep PHP-loaded members (they have correct room_role); just check countdown
        if (members.length === 1) startCountdown(1800);
        break;
      case 'new_message':
        if (d.message && d.message.room_id == ROOM_ID) appendMsg(d.message);
        break;
      case 'numer_participant_joined':
        if (d.room_id != ROOM_ID) break;
        stopCountdown();
        if (d.user) {
          members = members.filter(m => m.id != d.user.id);
          members.push(d.user);
          renderParticipants();
          appendSys(d.user.username + ' вошёл(а) в нумер');
        }
        break;
      case 'numer_participant_left':
        if (d.room_id != ROOM_ID) break;
        members = members.filter(m => m.id != d.user_id);
        renderParticipants();
        break;
      case 'numer_owner_changed':
        if (d.room_id != ROOM_ID) break;
        members = members.map(m => ({...m, room_role: m.id == d.owner?.id ? 'owner' : (m.room_role === 'owner' ? 'member' : m.room_role)}));
        renderParticipants();
        break;
      case 'numer_countdown':
        if (d.room_id == ROOM_ID) startCountdown(d.seconds || 1800);
        break;
      case 'numer_countdown_cancelled':
        if (d.room_id == ROOM_ID) stopCountdown();
        break;
      case 'online_users':
        renderInviteDropdown(d.users || []);
        break;
      case 'numer_destroyed':
        if (d.room_id != ROOM_ID) break;
        stopCountdown();
        appendSys('Нумер завершён.');
        setStatus('Нумер закрыт.');
        ws.onclose = null;
        ws.close();
        setTimeout(() => window.close(), 2000);
        break;
      case 'numer_left':
        if (d.room_id != ROOM_ID) break;
        ws.onclose = null;
        ws.close();
        window.close();
        break;
    }
  };
}

// ── Send message ─────────────────────────────────────────────
function sendMessage() {
  const inp = document.getElementById('msg-input');
  const content = inp.value.trim();
  if (!content || !ws || ws.readyState !== WebSocket.OPEN) return;
  ws.send(JSON.stringify({event:'send_message', room_id: ROOM_ID, content}));
  inp.value = '';
  inp.style.height = 'auto';
}

document.getElementById('send-btn').addEventListener('click', sendMessage);
document.getElementById('msg-input').addEventListener('keydown', e => {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
});
document.getElementById('msg-input').addEventListener('input', function() {
  this.style.height = 'auto';
  this.style.height = Math.min(this.scrollHeight, 80) + 'px';
});

// ── Leave ─────────────────────────────────────────────────────
function leaveNumer() {
  if (ws && ws.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify({event:'leave_numer', room_id: ROOM_ID}));
  }
}

document.getElementById('leave-btn').addEventListener('click', leaveNumer);

// ── Invite dropdown ───────────────────────────────────────────
const inviteDropdown = document.getElementById('invite-dropdown');
const inviteList     = document.getElementById('invite-list');
const inviteStatus   = document.getElementById('invite-status');

document.getElementById('invite-btn').addEventListener('click', function() {
  const open = inviteDropdown.style.display !== 'none';
  if (open) { inviteDropdown.style.display = 'none'; return; }
  inviteList.innerHTML = '<div style="padding:8px;color:#666;font-size:.8rem">Загрузка...</div>';
  inviteDropdown.style.display = 'block';
  if (ws && ws.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify({event: 'get_online_users'}));
  }
});

document.addEventListener('click', function(e) {
  if (!document.getElementById('invite-section').contains(e.target)) {
    inviteDropdown.style.display = 'none';
  }
});

function renderInviteDropdown(users) {
  const alreadyIn = new Set(members.map(m => Number(m.id)));
  const candidates = users.filter(u => !alreadyIn.has(Number(u.id)) && Number(u.id) !== ME.id);
  if (!candidates.length) {
    inviteList.innerHTML = '<div style="padding:8px;color:#666;font-size:.8rem">Нет доступных пользователей</div>';
    return;
  }
  inviteList.innerHTML = '';
  candidates.forEach(u => {
    const d = document.createElement('div');
    d.style.cssText = 'padding:6px 10px;cursor:pointer;font-size:.83rem;display:flex;align-items:center;gap:6px;border-bottom:1px solid #2e3035';
    d.innerHTML = `<span style="width:7px;height:7px;border-radius:50%;background:#28a745;display:inline-block;flex-shrink:0"></span><span style="color:\${esc(u.nick_color||'inherit')}">\${esc(u.username)}</span>`;
    d.addEventListener('mouseenter', () => d.style.background = '#2e3440');
    d.addEventListener('mouseleave', () => d.style.background = '');
    d.addEventListener('click', () => {
      if (ws && ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify({event: 'invite_user', to_user_id: Number(u.id)}));
        inviteStatus.textContent = 'Приглашение → ' + esc(u.username);
        setTimeout(() => { inviteStatus.textContent = ''; }, 3000);
      }
      inviteDropdown.style.display = 'none';
    });
    inviteList.appendChild(d);
  });
}

// ── Init ──────────────────────────────────────────────────────
initMsgs.forEach(m => appendMsg(m));
document.getElementById('messages').scrollTop = document.getElementById('messages').scrollHeight;
renderParticipants();
connect();
</script>
</body>
</html>
HTML;
    }
}
