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
      loadRooms();
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

function loadRooms() {
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
    if (!currentPublicRoomId && rooms.length > 0) {
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
    return shouldShowSystemMessages()
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
  if (m.scope === 'staff_call') {
    showToast(m.content || 'Вызов персонала.', 'warning');
    if (m.room_id !== currentRoomId || !shouldShowSystemMessages()) return;
  } else if (m.room_id !== currentRoomId) {
    return;
  }
  if (!shouldShowSystemMessages()) return;
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
  return localStorage.getItem('show_system_messages') !== '0';
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
//  НУМЕРА — открываем в отдельном окне браузера
// ════════════════════════════════════════════════

// SECTION: NUMER FLOW
function onNumerJoined(data) {
  if (data.room_id) openNumerWindow(data.room_id);
  loadRooms();
}

function onInviteSent(invitation) {
  if (!invitation) return;
  loadRooms();
}

function onInviteAccepted(data) {
  showToast('Приглашение принято: ' + displayName(data.user));
  loadRooms();
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
  if (numera.some(r => Number(r.id) === Number(data.room_id))) {
    loadRooms();
  }
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
      $('#online-users-list').empty();
    }
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
  if (Number(data.room_id) === Number(currentPublicRoomId)) {
    currentPublicRoomId = null;
    if (Number(currentRoomId) === Number(data.room_id)) {
      showToast('Комната была удалена.', 'warning');
      currentRoomId = null;
      $('#room-title').text('Выберите комнату');
      $('#messages-list').empty();
    }
  }
  loadRooms();
}

// ════════════════════════════════════════════════
//  FRIENDS
// ════════════════════════════════════════════════
// SECTION: FRIENDS
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
    $list.append(`<div class="friend-item">${dot} <span>${esc(displayName(f))}</span>${where}</div>`);
  });
}

$('#friend-search').on('input', function() { loadFriends(); });

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

// SECTION: ADMIN
function initAdmin() {
  if (!CURRENT_USER || !['platform_owner', 'admin'].includes(CURRENT_USER.global_role)) return;

  $('#admin-btn').on('click', function(e) {
    e.preventDefault();
    loadAdminDash();
    new bootstrap.Modal(document.getElementById('adminModal')).show();
  });

  $('#owner-btn').on('click', function(e) {
    e.preventDefault();
    const el = document.getElementById('ownerModal');
    if (el) bootstrap.Modal.getOrCreateInstance(el).show();
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
    const categoryOptions = resp.room_category_options || [];
    let html = '<table class="table table-sm"><thead><tr><th>ID</th><th>Название</th><th>Категория</th><th>Участников</th><th>Сообщений</th><th>Владелец</th><th>Дней</th><th></th></tr></thead><tbody>';
    resp.rooms.forEach(r => {
      const cat = r.room_category || 'user';
      const categoryControl = categoryOptions.length
        ? `<div class="d-inline-flex align-items-center flex-nowrap admin-room-category-control">
            <select class="form-select form-select-sm room-category-select" data-id="${r.id}" data-original="${esc(cat)}" style="min-width:112px;max-width:150px">
              ${categoryOptions.map(opt => `<option value="${esc(opt)}" ${opt === cat ? 'selected' : ''}>${catLabel[opt] || opt}</option>`).join('')}
            </select>
          </div>`
        : `<span class="badge bg-${catColor[cat]||'secondary'}">${catLabel[cat]||cat}</span>`;
      const delBtn = cat !== 'permanent'
        ? `<button class="btn btn-sm btn-danger room-del-btn" data-id="${r.id}" title="Удалить"><i class="fa fa-trash"></i></button>`
        : `<button class="btn btn-sm btn-outline-secondary" disabled title="Постоянную комнату нельзя удалить"><i class="fa fa-trash"></i></button>`;
      html += `<tr>
        <td>${r.id}</td>
        <td>${esc(r.name)}</td>
        <td>${categoryControl}</td>
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

$(document).off('change.adminRoomsCategory', '.room-category-select').on('change.adminRoomsCategory', '.room-category-select', function() {
  const $select = $(this);
  if ($select.prop('disabled')) return;

  const category = $select.val();
  if (String(category) === String($select.data('original'))) return;

  const id = $select.data('id');
  $select.prop('disabled', true).addClass('opacity-75');

  $.post(`/api/admin/rooms/${id}/category`, {
    csrf_token: CSRF_TOKEN,
    category
  }, function(resp) {
    const success = !!resp.success;
    showToast(success ? 'Категория обновлена.' : (resp.error || 'Не удалось обновить категорию.'), success ? 'success' : 'danger');
    loadAdminRooms();
  }, 'json').fail(function(xhr) {
    showToast(xhr.responseJSON?.error || 'Не удалось обновить категорию.', 'danger');
    loadAdminRooms();
  });
});

function loadAdminNumera() {
  const fmtDuration = (min) => {
    if (min < 60) return `${min} мин`;
    const h = Math.floor(min / 60), m = min % 60;
    return `${h}ч ${m}м`;
  };
  const closeReasonLabel = (reason) => {
    if (!reason) return '—';
    if (reason === 'last_left') return 'Все вышли';
    if (reason === 'idle')      return 'Простой';
    if (reason === 'admin')     return 'Админ';
    return esc(reason);
  };
  const buildParticipantsCell = (participants, memberCount) => {
    const count = Number(memberCount) || 0;
    if (!count && !participants) return '0';
    return `<span class="numer-part-btn" data-parts="${esc(participants||'')}"
      style="cursor:pointer;border-bottom:1px dotted currentColor">${count}</span>`;
  };

  $.when(
    $.get('/api/admin/numera'),
    $.get('/api/admin/numera/archive')
  ).then(function([activeResp], [archiveResp]) {
    const active   = (activeResp.success  && activeResp.numera)  ? activeResp.numera  : [];
    const archived = (archiveResp.success && archiveResp.numera) ? archiveResp.numera : [];

    if (!active.length && !archived.length) {
      $('#admin-numera-table').html('<div class="text-muted p-2">Нумеров нет.</div>');
      return;
    }

    let html = `<table class="table table-sm">
      <thead><tr>
        <th>ID</th><th>Создан</th><th>Владелец</th><th>Участники</th><th>Сообщений</th>
        <th>Статус</th><th>Закрыт</th><th>Причина</th><th></th>
      </tr></thead><tbody>`;

    active.forEach(r => {
      const started = r.created_at && dayjs(r.created_at).isValid() ? formatChatDateTime(r.created_at) : '—';
      const status = Number(r.member_count) > 0
        ? `<span class="badge bg-success">Активен</span> ${fmtDuration(Number(r.minutes_running) || 0)}`
        : `<span class="badge bg-warning text-dark">Завис</span> ${fmtDuration(Number(r.minutes_running) || 0)}`;
      html += `<tr>
        <td>${r.id}</td>
        <td>${started}</td>
        <td>${esc(r.owner_username||'—')}</td>
        <td>${buildParticipantsCell(r.participants, r.member_count)}</td>
        <td>${Number(r.message_count)||0}</td>
        <td>${status}</td>
        <td>—</td><td>—</td>
        <td class="text-nowrap">
          <button class="btn btn-sm btn-outline-info numer-history-btn me-1" data-id="${r.id}" title="История"><i class="fa fa-clock-rotate-left"></i></button>
          <button class="btn btn-sm btn-outline-danger numer-close-btn" data-id="${r.id}" title="Закрыть нумер"><i class="fa fa-xmark"></i></button>
        </td></tr>`;
    });

    archived.forEach(r => {
      const started  = r.created_at && dayjs(r.created_at).isValid() ? formatChatDateTime(r.created_at) : '—';
      const closedAt = r.closed_at  && dayjs(r.closed_at).isValid()  ? formatChatDateTime(r.closed_at)  : '—';
      html += `<tr class="text-muted">
        <td>${r.id}</td>
        <td>${started}</td>
        <td>${esc(r.owner_username||'—')}</td>
        <td>${buildParticipantsCell(r.participants, r.member_count)}</td>
        <td>${Number(r.message_count)||0}</td>
        <td><span class="badge bg-secondary">Закрыт</span></td>
        <td>${closedAt}</td>
        <td>${closeReasonLabel(r.close_reason)}</td>
        <td class="text-nowrap">
          <button class="btn btn-sm btn-outline-info numer-history-btn me-1" data-id="${r.id}" title="История"><i class="fa fa-clock-rotate-left"></i></button>
          <button class="btn btn-sm btn-outline-secondary numer-clear-archive-btn" data-id="${r.id}" title="Удалить переписку из архива"><i class="fa fa-trash"></i></button>
        </td></tr>`;
    });

    html += '</tbody></table>';
    $('#admin-numera-table').html(html);

    document.querySelectorAll('#admin-numera-table .numer-part-btn').forEach(el => {
      const existing = bootstrap.Popover.getInstance(el);
      if (existing) existing.dispose();

      const parts = el.getAttribute('data-parts') || '';
      let content = '—';
      if (parts) {
        content = parts.split(', ').map(entry => {
          const sep = entry.indexOf(':');
          if (sep < 1) return `<span>${esc(entry.trim())}</span>`;
          const uid  = parseInt(entry.substring(0, sep).trim(), 10);
          const name = entry.substring(sep + 1).trim();
          if (!uid)  return `<span>${esc(name)}</span>`;
          return `<a href="#" class="numer-user-link d-block" data-uid="${uid}" data-uname="${esc(name)}">${esc(name)}</a>`;
        }).join('');
      }

      new bootstrap.Popover(el, {
        container: 'body',
        trigger:   'click',
        html:      true,
        sanitize:  false,
        title:     'Участники',
        content,
      });
    });
  });
}

function loadAdminWhispers() {
  const from = $('#whisper-filter-from').val();
  const to   = $('#whisper-filter-to').val();
  $.get('/api/admin/whispers', {from_username:from, to_username:to}, function(resp) {
    if (!resp.success) return;
    let html = '<table class="table table-sm"><thead><tr><th>ID</th><th>Комната</th><th>От</th><th>Кому</th><th>Время</th><th>Текст</th></tr></thead><tbody>';
    resp.whispers.forEach(w => {
      html += `<tr><td>${w.id}</td><td>${esc(w.room_name)}</td><td>${esc(w.from_username)}</td><td>${esc(w.to_username)}</td><td>${formatChatDateTime(w.created_at)}</td><td>${w.content}</td></tr>`;
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
      const mutedUntil = b.muted_until && dayjs(b.muted_until).isValid() ? formatChatDateTime(b.muted_until) : '—';
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

$('#admin-numera-table').on('click', '.numer-close-btn', function() {
  const id = $(this).data('id');
  if (!confirm('Принудительно закрыть нумер #' + id + '?')) return;
  $.post(`/api/admin/numera/${id}/close`, {csrf_token: csrfToken}, function(resp) {
    if (resp.success) { showToast('Нумер #' + id + ' закрыт.'); loadAdminNumera(); }
    else showToast(resp.error || 'Ошибка', 'danger');
  });
});

$('#admin-numera-table').on('click', '.numer-clear-archive-btn', function() {
  const id = $(this).data('id');
  if (!confirm('Удалить переписку нумера #' + id + ' из архива? Сообщения будут скрыты, запись останется.')) return;
  $.post(`/api/admin/numera/${id}/clear-archive`, {csrf_token: CSRF_TOKEN}, function(resp) {
    if (resp.success) { showToast('Переписка нумера #' + id + ' удалена из архива.', 'success'); loadAdminNumera(); }
    else showToast(resp.error || 'Ошибка', 'danger');
  }, 'json');
});

$(document).on('click', function(e) {
  if ($(e.target).closest('.popover, .numer-part-btn').length) return;
  document.querySelectorAll('#admin-numera-table .numer-part-btn').forEach(el => {
    const pop = bootstrap.Popover.getInstance(el);
    if (pop) pop.hide();
  });
});

$(document).on('click', '.numer-user-link', function(e) {
  e.preventDefault();
  const uid   = parseInt($(this).data('uid'), 10);
  const uname = String($(this).data('uname') || '');
  if (!uid) return;
  document.querySelectorAll('#admin-numera-table .numer-part-btn').forEach(el => {
    const pop = bootstrap.Popover.getInstance(el);
    if (pop) pop.hide();
  });
  openUserInfo(uid, uname);
});

function openRoomHistory(roomId, roomName) {
  $.get(`/api/admin/rooms/${roomId}/messages`, function(resp) {
    if (!resp.success) { showToast('Не удалось загрузить историю.', 'danger'); return; }
    let rows = '';
    if (!resp.messages || !resp.messages.length) {
      rows = '<tr><td colspan="3" class="text-muted">Сообщений нет.</td></tr>';
    } else {
      resp.messages.forEach(m => {
        rows += `<tr><td style="white-space:nowrap">${formatChatDateTime(m.created_at)}</td><td>${esc(m.username||'—')}</td><td>${esc(m.content||'')}</td></tr>`;
      });
    }
    $('#admin-rooms-table').html(`
      <button class="btn btn-sm btn-outline-secondary mb-2" id="admin-rooms-back-btn"><i class="fa fa-arrow-left me-1"></i>Назад</button>
      <div class="fw-semibold mb-2">${esc(roomName)} — история</div>
      <div style="max-height:400px;overflow-y:auto">
        <table class="table table-sm table-striped"><thead><tr><th>Время</th><th>От</th><th>Сообщение</th></tr></thead><tbody>${rows}</tbody></table>
      </div>`);
  });
}

function openNumerHistory(numerId) {
  $.get(`/api/admin/numera/${numerId}/messages`, function(resp) {
    if (!resp.success) { showToast('Не удалось загрузить историю.', 'danger'); return; }
    let rows = '';
    if (!resp.messages || !resp.messages.length) {
      rows = '<tr><td colspan="3" class="text-muted">Сообщений нет.</td></tr>';
    } else {
      resp.messages.forEach(m => {
        rows += `<tr><td style="white-space:nowrap">${m.created_at && dayjs(m.created_at).isValid() ? formatChatDateTime(m.created_at) : '—'}</td><td>${esc(m.username||'—')}</td><td>${esc(m.content||'')}</td></tr>`;
      });
    }
    $('#admin-numera-table').html(`
      <button class="btn btn-sm btn-outline-secondary mb-2" id="admin-numera-back-btn"><i class="fa fa-arrow-left me-1"></i>Назад</button>
      <div class="fw-semibold mb-2">Нумер #${numerId} — история</div>
      <div style="max-height:400px;overflow-y:auto">
        <table class="table table-sm table-striped"><thead><tr><th>Время</th><th>От</th><th>Сообщение</th></tr></thead><tbody>${rows}</tbody></table>
      </div>`);
  });
}

$(document).on('click', '#admin-rooms-back-btn', loadAdminRooms);
$(document).on('click', '#admin-numera-back-btn', loadAdminNumera);

function loadAdminSettings() {
  $.get('/api/admin/system-settings', function(resp) {
    if (!resp.success) return;
    const s = resp.settings;
    $('[name="datetime_format"]').val(s.datetime_format || '');
    $('[name="time_format"]').val(s.time_format || '');
    $('[name="system_message_color_light"]').val(s.system_message_color_light || '#7a6a4a');
    $('[name="system_message_color_dark"]').val(s.system_message_color_dark || '#DEC8A4');
    $('#sys-msg-color-preview-light').css('color', s.system_message_color_light || '#7a6a4a');
    $('#sys-msg-color-preview-dark').css('color', s.system_message_color_dark || '#DEC8A4');
    $('[name="system_theme"]').val(s.system_theme || 'auto');
    $('#admin-reg-enabled').prop('checked', s.registration_enabled === '1');
    $('#admin-maint-mode').prop('checked', s.maintenance_mode === '1');
    $('[name="maintenance_message"]').val(s.maintenance_message || '');
  });
}

$('#adminSettingsForm').on('submit', function(e) {
  e.preventDefault();
  const data = {
    csrf_token: CSRF_TOKEN,
    datetime_format: $('[name="datetime_format"]').val(),
    time_format: $('[name="time_format"]').val(),
    system_message_color_light: $('[name="system_message_color_light"]').val(),
    system_message_color_dark:  $('[name="system_message_color_dark"]').val(),
    system_theme: $('[name="system_theme"]').val(),
    registration_enabled: $('#admin-reg-enabled').is(':checked') ? '1' : '0',
    maintenance_mode: $('#admin-maint-mode').is(':checked') ? '1' : '0',
    maintenance_message: $('[name="maintenance_message"]').val(),
  };
  $.post('/api/admin/system-settings', data, function(resp) {
    if (resp.success) {
      $('#admin-settings-success').removeClass('d-none');
      $('#admin-settings-error').addClass('d-none');
      document.documentElement.style.setProperty('--sys-msg-color-light', data.system_message_color_light);
      document.documentElement.style.setProperty('--sys-msg-color-dark',  data.system_message_color_dark);
      setTimeout(() => $('#admin-settings-success').addClass('d-none'), 3000);
    } else {
      $('#admin-settings-error').text(resp.error || 'Ошибка').removeClass('d-none');
    }
  }, 'json').fail(function(xhr) {
    $('#admin-settings-error').text(xhr.responseJSON?.error || 'Не удалось сохранить.').removeClass('d-none');
  });
});

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

function showToast(msg, type) {
  type = type || 'info';
  const colors = {success:'#198754',danger:'#dc3545',warning:'#ffc107',info:'#0dcaf0'};
  const $t = $(`<div style="position:fixed;top:16px;right:16px;z-index:9999;padding:10px 18px;border-radius:8px;background:${colors[type]||colors.info};color:${type==='warning'?'#000':'#fff'};box-shadow:0 4px 12px rgba(0,0,0,.2);max-width:300px">${esc(msg)}</div>`);
  $('body').append($t);
  setTimeout(() => $t.fadeOut(400, function(){ $(this).remove(); }), 3500);
}

}
