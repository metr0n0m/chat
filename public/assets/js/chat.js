dayjs.locale('ru');
dayjs.extend(dayjs_plugin_relativeTime);

// SECTION: BOOTSTRAP
const ChatConfig = window.ChatConfig || window.CHAT_BOOTSTRAP || {};
const CSRF_TOKEN = ChatConfig.csrfToken;
const CURRENT_USER = ChatConfig.currentUser;
const CHAT_TIME_FORMAT     = ChatConfig.timeFormat;
const CHAT_DATETIME_FORMAT = ChatConfig.datetimeFormat;

if (CURRENT_USER) {
// SECTION: THEME
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
// SECTION: STATE
let ws = null;
let forcedLogout = false;       // set to true on force_logout to suppress reconnect
let currentRoomId = null;       // currently VIEWED room (public or numer)
let currentPublicRoomId = null; // public room you're WS-subscribed to
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
const onlineCountsByRoom = new Map();
const DEFAULT_AVATAR_URL = '/assets/avatar-default.svg';

// ════════════════════════════════════════════════
//  INIT
// ════════════════════════════════════════════════
// SECTION: APP INIT
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

});

// ════════════════════════════════════════════════
//  USER INIT
// ════════════════════════════════════════════════
// SECTION: USER INIT
function initUser() {
  if (!CURRENT_USER) return;
  $('#my-username').text(displayName(CURRENT_USER)).css('color', effectiveColor(CURRENT_USER.nick_color));
  $('#my-status').text(CURRENT_USER.custom_status || '');
  $('#my-avatar').attr('src', CURRENT_USER.avatar_url || DEFAULT_AVATAR_URL);
  $('#my-avatar').off('error').on('error', function(){ this.onerror = null; this.src = DEFAULT_AVATAR_URL; });
}


// ════════════════════════════════════════════════
//  WEBSOCKET
// ════════════════════════════════════════════════
// SECTION: WEBSOCKET
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
    if (forcedLogout) {
      console.log('[WS] Connection closed by server (force_logout), no reconnect.');
      return;
    }
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
    case 'room_counts':
      Object.entries(data.counts || {}).forEach(([id, count]) => {
        onlineCountsByRoom.set(Number(id), Number(count));
        updateRoomBadge(id);
      });
      break;
    case 'room_count_changed':       onRoomCountChanged(data); break;
    case 'numer_destroyed':
      numera = numera.filter(r => Number(r.id) !== Number(data.room_id));
      $(`#numera-list .room-item[data-id="${data.room_id}"]`).remove();
      if (
        $('#ownerModal').hasClass('show')
        && $('#ownerNumera').hasClass('active')
        && typeof loadAdminNumera === 'function'
      ) {
        loadAdminNumera();
      }
      break;
    case 'kicked_from_room': onKickedFromRoom(data); break;
    case 'banned_from_room': onBannedFromRoom(data); break;
    case 'muted_in_room':    onMutedInRoom(data); break;
    case 'room_deleted':     onRoomDeleted(data); break;
    case 'room_updated':
      if (data.data && data.data.name !== undefined) {
        $(`.room-item[data-id="${data.room_id}"] .room-name`).text(esc(data.data.name));
        const r = rooms.find(r => Number(r.id) === Number(data.room_id));
        if (r) r.name = data.data.name;
      }
      if (data.data && data.data.role !== undefined && data.data.target_user_id !== undefined) {
        updateOnlineUser(data.data.target_user_id, {room_role: data.data.role});
      }
      break;
    case 'friend_online':
    case 'friend_offline':
      if (typeof loadFriends === 'function') {
        loadFriends();
      }
      break;
    case 'force_logout': {
      forcedLogout = true;
      localStorage.removeItem('lastRoomId');
      const reason = data.reason || '';
      const msg = reason === 'kicked'  ? 'Вы были удалены модератором.' :
                  reason === 'banned'  ? 'Доступ ограничён.' :
                                        'Сессия завершена.';
      showToast(msg, 'danger');
      setTimeout(() => { location.href = '/'; }, 2000);
      break;
    }
    case 'error':           showToast(data.message, 'danger'); break;
    case 'pong':            break;
  }
}

function onWSConnected(data) {
  if (currentPublicRoomId) wsSend('join_room', {room_id: currentPublicRoomId});
  wsSend('get_room_counts', {});
}

// ════════════════════════════════════════════════
//  ROOMS
// ════════════════════════════════════════════════
// SECTION: ROOMS
function updateRoomBadge(roomId) {
  const count = onlineCountsByRoom.get(Number(roomId)) || 0;
  const $item = $(`.room-item[data-id="${roomId}"]`);
  $item.find('.room-count-badge').remove();
  if (count > 0) {
    $item.append(`<span class="badge bg-secondary ms-1 room-count-badge" style="font-size:.65rem">${count}</span>`);
  }
}

function loadRooms(skipAutoJoin = false) {
  $.get('/api/rooms', function(resp) {
    if (!resp.success) return;
    rooms = resp.rooms;
    const $list = $('#rooms-list').empty();
    rooms.forEach(r => {
      const $item = $(`<div class="room-item" data-id="${r.id}"><span class="room-name">${esc(r.name)}</span></div>`);
      if (Number(r.id) === Number(currentRoomId)) $item.addClass('active');
      $item.on('click', () => joinPublicRoom(r.id));
      $list.append($item);
    });
    onlineCountsByRoom.forEach((_, roomId) => updateRoomBadge(roomId));
    if (!skipAutoJoin && !currentPublicRoomId && rooms.length > 0) {
      const saved = localStorage.getItem('lastRoomId');
      const target = saved ? rooms.find(r => Number(r.id) === Number(saved)) : null;
      joinPublicRoom(target ? Number(target.id) : rooms[0].id);
    }
  });
  $.get('/api/numera', function(resp) {
    if (!resp.success) return;
    numera = resp.numera;
    const $list = $('#numera-list').empty();
    numera.forEach(r => {
      const $item = $(`<div class="room-item" data-id="${r.id}"><span class="room-name"><i class="fa fa-lock me-1"></i>${esc(r.name)}</span></div>`);
      if (Number(r.id) === Number(currentRoomId)) $item.addClass('active');
      $item.on('click', () => openNumerWindow(r.id));
      $list.append($item);
    });
    onlineCountsByRoom.forEach((_, roomId) => updateRoomBadge(roomId));
  });
}

// Join a public room (switches WS subscription, leaves old public room)
function joinPublicRoom(roomId) {
  if (currentPublicRoomId && Number(currentPublicRoomId) !== Number(roomId)) {
    wsSend('leave_room', {room_id: currentPublicRoomId});
  }
  currentPublicRoomId = roomId;
  currentRoomId = roomId;
  currentRoomRole = null;
  oldestMessageId = null;
  clearWhisperMode();
  localStorage.setItem('lastRoomId', roomId);
  $('#messages-list').empty();
  $('#load-more-btn-wrap').addClass('d-none');
  $('.room-item').removeClass('active');
  $(`.room-item[data-id="${roomId}"]`).addClass('active');
  loadHistory(roomId);
  wsSend('join_room', {room_id: roomId});
}

function openNumerWindow(roomId) {
  window.open('/numer/' + roomId, 'numer_' + roomId, 'width=700,height=540,toolbar=no,menubar=no,location=no,status=no,scrollbars=no,resizable=yes');
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
// SECTION: MESSAGES
function buildMessage(m) {
  if (m.type === 'system') {
    const sysTime = formatChatTime(m.created_at);
    return shouldShowSystemMessage(m)
      ? `<div class="msg-system"><span class="msg-time">${sysTime}</span><span class="msg-sep"> » </span>${esc(m.content)}</div>`
      : '';
  }

  if (m.type === 'whisper') {
    const isSent = Number(m.user_id) === Number(CURRENT_USER.id);
    const normalized = {
      message_id: m.id,
      room_id:    m.room_id,
      created_at: m.created_at,
      content:    m.content,
      from: { id: m.user_id,    username: m.username,            nickname: m.nickname,           nick_color: m.nick_color,          text_color: m.text_color },
      to:   { id: m.whisper_to, username: m.whisper_to_username, nickname: m.whisper_to_nickname, nick_color: m.whisper_to_nick_color },
    };
    return buildWhisperMessage(normalized, isSent);
  }

  const time = formatChatTime(m.created_at);
  const canDelete = canDeleteMessage(m);
  const deleteBtn = canDelete ? ` <span class="msg-delete-btn" data-id="${m.id}" title="Удалить"><i class="fa fa-trash"></i></span>` : '';

  let embed = '';
  if (m.embed_data) {
    const ed = typeof m.embed_data === 'string' ? JSON.parse(m.embed_data) : m.embed_data;
    embed = `<div class="mt-1">${ed.html || ''}</div>`;
  }

  return `<div class="msg" id="msg-${m.id}">
    <div class="msg-body">
      <span class="msg-time">${time}</span><span class="msg-sep"> » </span><span class="msg-username" style="color:${esc(effectiveColor(m.nick_color))}">${esc(displayName(m))}</span> <span class="msg-sep">»</span> <span class="msg-content msg-inline-content" style="color:${esc(effectiveColor(m.text_color))} !important">${m.content}</span>${deleteBtn}
      ${embed}
    </div>
  </div>`;
}

function buildWhisperMessage(m, isSent) {
  const time = formatChatTime(m.created_at);
  const from = m.from || {};
  const to   = m.to   || {};
  const fromName  = esc(displayName(from));
  const toName    = esc(displayName(to));
  const reply     = isSent ? to : from;
  const replyId   = Number(reply.id || 0);
  const replyName = esc(displayName(reply));
  return `<div class="msg msg-whisper-row" data-id="${replyId}" data-name="${replyName}" id="msg-${m.message_id}">
    <div class="msg-body">
      <span class="msg-time">${time}</span><span class="msg-sep"> »»» </span><span class="msg-username" style="color:${esc(effectiveColor(from.nick_color))}">${fromName}</span> <span class="msg-sep">»»»</span> <span class="msg-username" style="color:${esc(effectiveColor(to.nick_color))}">${toName}</span>: <span class="msg-content msg-inline-content" style="color:${esc(effectiveColor(from.text_color))} !important">${m.content}</span>
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
  const scope = m.system_scope || m.scope || '';
  if (scope === 'moderation_call' || scope === 'staff_call') {
    showToast(m.content || 'Вызов персонала.', 'warning');
    if (m.room_id !== currentRoomId || !shouldShowSystemMessage(m)) return;
  } else if (m.room_id !== currentRoomId) {
    return;
  }
  if (!shouldShowSystemMessage(m)) return;
  const sysTime = formatChatTime(m.created_at);
  $('#messages-list').append(`<div class="msg-system"><span class="msg-time">${sysTime}</span><span class="msg-sep"> » </span>${esc(m.content)}</div>`);
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
  if (CURRENT_USER && typeof CURRENT_USER.show_system_messages !== 'undefined') {
    return Number(CURRENT_USER.show_system_messages) !== 0;
  }
  return localStorage.getItem('show_system_messages') !== '0';
}

function shouldShowSystemMessage(m) {
  const importance = (m && m.system_importance) ? m.system_importance : 'optional';
  if (importance === 'important') return true;
  return shouldShowSystemMessages();
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
$('#messages-list').on('click', '.msg-whisper-row', function() {
  const uid = Number($(this).data('id'));
  const uname = String($(this).data('name') || '');
  if (uid && uname) activateWhisperMode(uid, uname);
});

// ════════════════════════════════════════════════
//  INPUT & SEND
// ════════════════════════════════════════════════
// SECTION: INPUT AND SEND
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

// ════════════════════════════════════════════════
//  WHISPER MODE
// ════════════════════════════════════════════════
// SECTION: WHISPER MODE
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
// SECTION: ONLINE USERS
function renderOnlineList(users) {
  currentOnlineUsers = users.slice();
  const $list = $('#online-users-list').empty();
  $('#panel-online-count').text(users.length);
  $('#room-online-count').text(`${users.length} онлайн`);
  users.forEach(u => $list.append(buildOnlineUser(u)));
  window.ChatShell?.renderMobileUsersRail?.(users);
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

function updateOnlineUser(userId, patch) {
  const u = currentOnlineUsers.find(u => Number(u.id) === Number(userId));
  if (!u) return;
  Object.assign(u, patch);
  $(`#online-user-${Number(userId)}`).replaceWith(buildOnlineUser(u));
}

// SECTION: ONLINE USER ACTIONS
function buildOnlineUser(u) {
  const role = visibleRoleLabel(u);
  const roleBadge = role ? `<span class="badge ${visibleRoleClass(u)}" style="font-size:.65rem">${role}</span>` : '';
  const ignored = ignoredUserIds.has(Number(u.id));
  return `<div class="online-user" id="online-user-${u.id}" data-id="${u.id}" data-username="${esc(u.username)}">
    <div class="online-user-avatar" data-action="mention">${avatarMarkup(u.avatar_url, 42)}</div>
    <div class="online-user-main" data-action="mention">
      <div class="online-user-name" style="color:${esc(effectiveColor(u.nick_color))}">${esc(displayName(u))}</div>
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

function showModalAbove(el) {
  const parentEl = document.querySelector('.modal.show');

  if (parentEl && parentEl !== el) {
    const baseZ = parseInt(getComputedStyle(parentEl).zIndex, 10) || 1055;
    el.style.zIndex = baseZ + 10;

    function _focusinGuard(e) {
      if (el.contains(e.target)) e.stopPropagation();
    }
    document.addEventListener('focusin', _focusinGuard, true);

    let elevatedBd = null;

    el.addEventListener('show.bs.modal', function onShow() {
      el.removeEventListener('show.bs.modal', onShow);
      requestAnimationFrame(function () {
        const bds = document.querySelectorAll('.modal-backdrop');
        if (bds.length > 1) {
          elevatedBd = bds[bds.length - 1];
          elevatedBd.style.zIndex = baseZ + 5;
        }
      });
    });

    el.addEventListener('hidden.bs.modal', function onHide() {
      el.removeEventListener('hidden.bs.modal', onHide);
      el.style.zIndex = '';
      document.removeEventListener('focusin', _focusinGuard, true);
      if (elevatedBd) { elevatedBd.style.zIndex = ''; elevatedBd = null; }
    });
  }

  bootstrap.Modal.getOrCreateInstance(el).show();
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
      ? (u.last_seen_at ? formatChatDateTime(u.last_seen_at) : 'нет данных')
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
            <strong style="color:${esc(effectiveColor(u.nick_color, '#fff'))}">${esc(displayName(u) || infoUsername || ('ID ' + infoUserId))}</strong>
            <span class="badge bg-secondary">${esc(roleText)}</span>
          </div>
          <div class="small text-muted mb-2">Последний визит: ${esc(lastSeenText)}</div>
          <div class="mb-2"><strong>Статус:</strong> ${esc(u.custom_status || '—')}</div>
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

  showModalAbove(document.getElementById('userInfoModal'));
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
      executeGlobalBan(uid);
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
      executeGlobalBan(uid);
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

function executeGlobalBan(userId) {
  const choice = prompt(
    'Срок бана:\n 1 — 1 час\n 2 — 24 часа\n 3 — 7 дней\n 0 — Навсегда',
    '0'
  );
  if (choice === null) return;
  const hoursMap = {'0': 0, '1': 1, '2': 24, '3': 168};
  const labelMap = {'0': 'навсегда', '1': '1 час', '2': '24 часа', '3': '7 дней'};
  if (!(choice in hoursMap)) {
    showToast('Неверный срок бана.', 'danger');
    return;
  }
  const hours = hoursMap[choice];
  const label = labelMap[choice];
  $.post(`/api/admin/users/${userId}`,
    {csrf_token: CSRF_TOKEN, is_banned: 1, ban_hours: hours},
    function(resp) {
      if (resp.success) {
        showToast(`Пользователь заблокирован (${label}).`, 'warning');
        if (typeof loadAdminBans === 'function') loadAdminBans();
        if (typeof loadAdminUsers === 'function') loadAdminUsers();
      } else {
        showToast(resp.error || 'Ошибка.', 'danger');
      }
    },
    'json'
  ).fail(() => showToast('Ошибка запроса.', 'danger'));
}


// ════════════════════════════════════════════════
//  НУМЕРА — открываем в отдельном окне браузера
// ════════════════════════════════════════════════

// SECTION: NUMER FLOW
function onNumerJoined(data) {
  if (data.room_id) openNumerWindow(data.room_id);
  if (data.room_id && !numera.some(r => Number(r.id) === Number(data.room_id))) {
    numera.push({id: data.room_id, name: data.room_name});
    const $item = $(`<div class="room-item" data-id="${data.room_id}"><span class="room-name"><i class="fa fa-lock me-1"></i>${esc(data.room_name)}</span></div>`);
    $item.on('click', () => openNumerWindow(data.room_id));
    $('#numera-list').append($item);
  }
}

function onInviteSent(invitation) {
  if (!invitation) return;
}

function onInviteAccepted(data) {
  showToast('Приглашение принято: ' + displayName(data.user));
  if (data.room_id && !numera.some(r => Number(r.id) === Number(data.room_id))) {
    numera.push({id: data.room_id, name: data.room_name});
    const $item = $(`<div class="room-item" data-id="${data.room_id}"><span class="room-name"><i class="fa fa-lock me-1"></i>${esc(data.room_name)}</span></div>`);
    $item.on('click', () => openNumerWindow(data.room_id));
    $('#numera-list').append($item);
  }
  if (data.room_id) openNumerWindow(data.room_id);
}

function onInviteDeclined(data) {
  showToast('Приглашение отклонено.');
}

function onInviteExpired(data) {
  showToast('Приглашение истекло.');
}


function onRoomCountChanged(data) {
  onlineCountsByRoom.set(Number(data.room_id), Number(data.count));
  updateRoomBadge(data.room_id);
}

function onInviteReceived(inv) {
  const from = inv.from || {};
  let countdown = 30;
  $('#invite-modal-body').html(`
    <p><strong>${esc(displayName(from))}</strong> приглашает вас в нумер.</p>
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
// SECTION: ROOM EXIT AND MODERATION EVENTS
function onKickedFromRoom(data) {
  if (Number(data.room_id) === Number(currentPublicRoomId)) {
    showToast('Вы были удалены из комнаты.', 'warning');
    currentPublicRoomId = null;
    if (Number(currentRoomId) === Number(data.room_id)) {
      currentRoomId = null;
      $('#room-title').text('Выберите комнату');
      $('#messages-list').empty();
      $('#load-more-btn-wrap').addClass('d-none');
      $('#online-users-list').empty();
    }
    loadRooms(true);
  }
}

function onBannedFromRoom(data) {
  if (Number(data.room_id) === Number(currentPublicRoomId)) {
    showToast('Вы забанены в комнате.', 'danger');
    currentPublicRoomId = null;
    localStorage.removeItem('lastRoomId');
    if (Number(currentRoomId) === Number(data.room_id)) {
      currentRoomId = null;
      $('#room-title').text('Выберите комнату');
      $('#messages-list').empty();
      $('#load-more-btn-wrap').addClass('d-none');
      $('#online-users-list').empty();
    }
    loadRooms(true);
  }
}

function onMutedInRoom(data) {
  if (data.room_id !== currentRoomId) return;
  const until = data.muted_until ? dayjs(data.muted_until).format('HH:mm:ss') : '';
  const reason = data.reason ? ` Причина: ${data.reason}` : '';
  showToast(`Вам выдан кляп${until ? ` до ${until}` : ''}.${reason}`, 'warning');
}

function onRoomDeleted(data) {
  if (Number(data.room_id) === Number(currentPublicRoomId)) {
    currentPublicRoomId = null;
    if (Number(currentRoomId) === Number(data.room_id)) {
      showToast('Комната была удалена.', 'warning');
      currentRoomId = null;
      $('#room-title').text('Выберите комнату');
      $('#messages-list').empty();
    }
  }
  rooms = rooms.filter(r => Number(r.id) !== Number(data.room_id));
  $(`#rooms-list .room-item[data-id="${data.room_id}"]`).remove();
}

// SECTION: FRIENDS — moved to chat-friends.js

// ════════════════════════════════════════════════
//  SETTINGS
// ════════════════════════════════════════════════
// SECTION: SETTINGS
function openSettingsModal() {
  const modal = new bootstrap.Modal(document.getElementById('settingsModal'));
  $('[name="username"]').val(CURRENT_USER.username);
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

  function updateColorPreview(pickerName, previewLightId, previewDarkId) {
    $(`[name="${pickerName}"]`).off('input.colorcheck').on('input.colorcheck', function() {
      const hex = $(this).val();
      $(`#${previewLightId}`).css('color', hex);
      $(`#${previewDarkId}`).css('color', hex);
    });
  }

  updateColorPreview('nick_color', 'nick-preview-light', 'nick-preview-dark');
  updateColorPreview('text_color', 'text-preview-light', 'text-preview-dark');

  $('#settingsForm').off('submit').on('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    fd.set('csrf_token', CSRF_TOKEN);
    fd.set('hide_last_seen', $('#hideLastSeenSetting').is(':checked') ? '1' : '0');
    fd.set('show_system_messages', $('#showSystemMessagesSetting').is(':checked') ? '1' : '0');
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

// SECTION: SIDEBAR AND ROOM MANAGEMENT
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
          if (resp.room_id) setTimeout(() => joinPublicRoom(resp.room_id), 300);
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
      html += `<div class="list-group-item"><div class="d-flex align-items-center gap-2 mb-2">${avatarMarkup(u.avatar_url, 32)}<div class="flex-1"><div>${esc(displayName(u))}</div>${role ? `<div class="small text-muted">${role}</div>` : ''}</div></div><div class="d-flex flex-wrap gap-2"><button class="btn btn-sm btn-outline-secondary room-action-btn" data-action="kick" data-id="${u.id}">Удалить</button><button class="btn btn-sm btn-outline-danger room-action-btn" data-action="ban" data-id="${u.id}">Бан</button>${canAssignRoles ? `<button class="btn btn-sm btn-outline-primary room-action-btn" data-action="set_role" data-role="local_moderator" data-id="${u.id}">Модератор</button><button class="btn btn-sm btn-outline-primary room-action-btn" data-action="set_role" data-role="member" data-id="${u.id}">Участник</button>` : ''}${canAssignLocalAdmin() ? `<button class="btn btn-sm btn-outline-warning room-action-btn" data-action="set_role" data-role="local_admin" data-id="${u.id}">Локальный админ</button>` : ''}</div></div>`;
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

// SECTION: ADMIN — moved to chat-admin.js
// ════════════════════════════════════════════════
//  HELPERS
// ════════════════════════════════════════════════
// SECTION: HELPERS
function scrollToBottom() {
  const el = document.getElementById('messages-container');
  el.scrollTop = el.scrollHeight;
  isScrolledToBottom = true;
  $('#scroll-bottom-btn').hide();
}

const _effectiveColorCache = new Map();

function effectiveColor(hex, fallback = 'inherit') {
  if (!hex || !/^#[0-9a-fA-F]{6}$/.test(hex)) return fallback;
  const theme = isDarkTheme() ? 'dark' : 'light';
  const key = hex + '|' + theme;
  if (_effectiveColorCache.has(key)) return _effectiveColorCache.get(key);
  const bg = theme === 'dark' ? '#212529' : '#f8f9fa';
  let result = hex;
  if (wcagContrast(hex, bg) < 3.0) {
    const [h, s, l] = hexToHsl(hex);
    const dark = theme === 'dark';
    let lo = dark ? l : 0, hi = dark ? 1 : l, best = dark ? 0.95 : 0.1;
    for (let i = 0; i < 20; i++) {
      const mid = (lo + hi) / 2;
      const candidate = hslToHex(h, s, mid);
      if (wcagContrast(candidate, bg) >= 3.0) { best = mid; dark ? (hi = mid) : (lo = mid); }
      else { dark ? (lo = mid) : (hi = mid); }
    }
    result = hslToHex(h, s, best);
  }
  _effectiveColorCache.set(key, result);
  return result;
}

}
