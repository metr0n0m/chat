dayjs.locale('ru');
dayjs.extend(dayjs_plugin_relativeTime);

// SECTION: BOOTSTRAP
const ChatConfig = window.ChatConfig || window.CHAT_BOOTSTRAP || {};
const CSRF_TOKEN = ChatConfig.csrfToken;
const CURRENT_USER = ChatConfig.currentUser;
const CHAT_TIME_FORMAT     = ChatConfig.timeFormat;
const CHAT_DATETIME_FORMAT = ChatConfig.datetimeFormat;

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
    case 'new_message':
      if (typeof onNewMessage === 'function') onNewMessage(data.message);
      break;
    case 'message_deleted':
      if (typeof onMessageDeleted === 'function') onMessageDeleted(data);
      break;
    case 'system_message':
      if (typeof onSystemMessage === 'function') onSystemMessage(data.message);
      break;
    case 'whisper_sent':
      if (typeof onWhisperMessage === 'function') onWhisperMessage(data.message, true);
      break;
    case 'whisper_received':
      if (typeof onWhisperMessage === 'function') onWhisperMessage(data.message, false);
      break;
    case 'invite_received':
      if (typeof onInviteReceived === 'function') onInviteReceived(data.invitation);
      break;
    case 'invite_sent':
      if (typeof onInviteSent === 'function') onInviteSent(data.invitation);
      break;
    case 'invite_accepted':
      if (typeof onInviteAccepted === 'function') onInviteAccepted(data);
      break;
    case 'invite_declined':
      if (typeof onInviteDeclined === 'function') onInviteDeclined(data);
      break;
    case 'invite_expired':
      if (typeof onInviteExpired === 'function') onInviteExpired(data);
      break;
    case 'numer_joined':
      if (typeof onNumerJoined === 'function') onNumerJoined(data);
      break;
    case 'room_counts':
      Object.entries(data.counts || {}).forEach(([id, count]) => {
        onlineCountsByRoom.set(Number(id), Number(count));
        updateRoomBadge(id);
      });
      break;
    case 'room_count_changed':       onRoomCountChanged(data); break;
    case 'numer_destroyed':
      removeNumerFromSidebar(data.room_id);
      if (
        $('#ownerModal').hasClass('show')
        && $('#ownerNumera').hasClass('active')
        && typeof loadAdminNumera === 'function'
      ) {
        loadAdminNumera();
      }
      break;
    case 'kicked_from_room':
      if (typeof onKickedFromRoom === 'function') onKickedFromRoom(data);
      break;
    case 'banned_from_room':
      if (typeof onBannedFromRoom === 'function') onBannedFromRoom(data);
      break;
    case 'muted_in_room':
      if (typeof onMutedInRoom === 'function') onMutedInRoom(data);
      break;
    case 'room_deleted':
      if (typeof onRoomDeleted === 'function') onRoomDeleted(data);
      break;
    case 'room_updated':
      if (data.data && data.data.name !== undefined) {
        $(`.room-item[data-id="${data.room_id}"] .room-name`).text(esc(data.data.name));
        const r = rooms.find(r => Number(r.id) === Number(data.room_id));
        if (r) r.name = data.data.name;
      }
      if (data.data && data.data.role !== undefined && data.data.target_user_id !== undefined) {
        if (typeof updateOnlineUser === 'function') updateOnlineUser(data.data.target_user_id, {room_role: data.data.role});
      }
      if (data.data && data.data.muted === true && data.data.target_user_id !== undefined) {
        if (typeof updateOnlineUser === 'function')
          updateOnlineUser(data.data.target_user_id, {muted_until: data.data.muted_until ?? null});
        if ($('#userInfoModal').hasClass('show') && Number(infoUserId) === Number(data.data.target_user_id))
          renderUserInfoModButtons(data.data.target_user_id);
      }
      if (data.data && data.data.unmuted === true && data.data.target_user_id !== undefined) {
        if (typeof updateOnlineUser === 'function')
          updateOnlineUser(data.data.target_user_id, {muted_until: null});
        if ($('#userInfoModal').hasClass('show') && Number(infoUserId) === Number(data.data.target_user_id))
          renderUserInfoModButtons(data.data.target_user_id);
      }
      break;
    case 'unmuted_in_room':
      if (typeof onUnmutedInRoom === 'function') onUnmutedInRoom(data);
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
    oldestMessageId = msgs[0].id;

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

    if (msgs.length >= 50) {
      $('#load-more-btn-wrap').removeClass('d-none');
    } else {
      $('#load-more-btn-wrap').addClass('d-none');
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
  if (typeof renderOnlineList === 'function') renderOnlineList(data.online || []);
  onlineCountsByRoom.set(Number(data.room_id), (data.online || []).length);
  updateRoomBadge(data.room_id);
  // Silently restore mute state for self on room join / reconnect
  const selfEntry = (data.online || []).find(u => Number(u.id) === Number(CURRENT_USER.id));
  if (selfEntry && selfEntry.muted_until && dayjs(selfEntry.muted_until).isAfter(dayjs())) {
    if (typeof applyMuteState === 'function') applyMuteState(selfEntry.muted_until);
  } else {
    if (typeof clearMuteState === 'function') clearMuteState();
  }

  const canManage = ['platform_owner', 'admin'].includes(CURRENT_USER.global_role) || ['owner', 'local_admin', 'local_moderator'].includes(data.my_role);
  $('#room-manage-btn').toggleClass('d-none', !canManage);
  $('#send-btn').prop('disabled', $('#msg-input').val().trim().length === 0);
}

function onUserJoined(data) {
  if (data.room_id !== currentRoomId) return;
  if (typeof addToOnlineList === 'function') addToOnlineList(data.user);
  onlineCountsByRoom.set(Number(data.room_id), (onlineCountsByRoom.get(Number(data.room_id)) || 0) + 1);
  updateRoomBadge(data.room_id);
}

function onUserLeft(data) {
  if (data.room_id !== currentRoomId) return;
  if (typeof removeFromOnlineList === 'function') removeFromOnlineList(data.user_id);
  const cur = onlineCountsByRoom.get(Number(data.room_id)) || 0;
  onlineCountsByRoom.set(Number(data.room_id), Math.max(0, cur - 1));
  updateRoomBadge(data.room_id);
}

function removeNumerFromSidebar(roomId) {
  numera = numera.filter(r => Number(r.id) !== Number(roomId));
  $(`#numera-list .room-item[data-id="${roomId}"]`).remove();
}

function removePublicRoomFromSidebar(roomId) {
  rooms = rooms.filter(r => Number(r.id) !== Number(roomId));
  $(`#rooms-list .room-item[data-id="${roomId}"]`).remove();
}



// SECTION: MESSAGES — moved to chat-messages.js
// SECTION: INPUT AND SEND + WHISPER — moved to chat-input-send.js
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

    $actions.append(`<button type="button" class="btn btn-sm btn-outline-secondary user-info-action-btn" data-action="mention">Обратиться</button>`);
    $actions.append(`<button type="button" class="btn btn-sm btn-outline-secondary user-info-action-btn" data-action="whisper">Шёпот</button>`);
    if (!isSelf) {
      $actions.append(`<button type="button" class="btn btn-sm btn-outline-secondary user-info-action-btn" data-action="invite">В нумер</button>`);
    }

    renderUserInfoModButtons(uid);
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
    case 'room-mute':
      openMuteModal(uid);
      break;
    case 'room-unmute':
      executeRoomAction('unmute', uid, 'Снять кляп с пользователя?');
      break;
    case 'ban-global':
      executeGlobalBan(uid);
      break;
  }
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

// ─── User info modal — moderation buttons (single source of truth) ────────────
function renderUserInfoModButtons(userId) {
  const target = currentOnlineUsers.find(u => Number(u.id) === Number(userId));
  const isMuted = target && target.muted_until && dayjs(target.muted_until).isAfter(dayjs());
  const $actions = $('#user-info-actions');
  $actions.find('[data-action="room-kick"],[data-action="room-ban"],' +
    '[data-action="room-mute"],[data-action="room-unmute"],[data-action="ban-global"]').remove();
  const isSelf    = Number(userId) === Number(CURRENT_USER.id);
  const canMod    = canModerateCurrentRoom() && !isSelf;
  const canGlobal = ['platform_owner', 'admin', 'moderator'].includes(CURRENT_USER.global_role) && !isSelf;
  if (canMod) {
    $actions.append(`<button type="button" class="btn btn-sm btn-outline-warning user-info-action-btn" data-action="room-kick">Удалить из комнаты</button>`);
    $actions.append(`<button type="button" class="btn btn-sm btn-outline-danger user-info-action-btn" data-action="room-ban">Бан в комнате</button>`);
    $actions.append(isMuted
      ? `<button type="button" class="btn btn-sm btn-outline-secondary user-info-action-btn" data-action="room-unmute">Снять кляп</button>`
      : `<button type="button" class="btn btn-sm btn-outline-danger user-info-action-btn" data-action="room-mute">Кляп</button>`);
  }
  if (canGlobal) {
    $actions.append(`<button type="button" class="btn btn-sm btn-danger user-info-action-btn" data-action="ban-global">Глобальный бан</button>`);
  }
}

// ─── Mute modal ───────────────────────────────────────────────────────────────
let _muteTargetUserId = null;

function openMuteModal(userId) {
  _muteTargetUserId = userId;
  $('#mute-duration').val('');
  $('#mute-reason').val('');
  $('#mute-error').addClass('d-none').text('');
  $('#mute-submit-btn').prop('disabled', true);
  showModalAbove(document.getElementById('muteModal'));
}

// Registered once at module load — not inside openMuteModal
$(document).on('change input', '#mute-duration, #mute-reason', function() {
  const dur = $('#mute-duration').val();
  const reason = $('#mute-reason').val().trim();
  $('#mute-submit-btn').prop('disabled', !dur || !reason);
});

$(document).on('click', '#mute-submit-btn', function() {
  const minutes = parseInt($('#mute-duration').val(), 10);
  const reason = $('#mute-reason').val().trim();
  if (!minutes || !reason) return;
  executeRoomAction('mute', _muteTargetUserId, null, {minutes, reason});
  bootstrap.Modal.getInstance(document.getElementById('muteModal'))?.hide();
});

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


// SECTION: NUMER FLOW — moved to chat-numer.js

function onRoomCountChanged(data) {
  onlineCountsByRoom.set(Number(data.room_id), Number(data.count));
  updateRoomBadge(data.room_id);
}

// SECTION: ROOM EXIT AND MODERATION EVENTS — moved to chat-roomevents.js

// SECTION: FRIENDS — moved to chat-friends.js

// SECTION: SETTINGS — moved to chat-settings.js

// SECTION: SIDEBAR — moved to chat-sidebar.js
// SECTION: ADMIN — moved to chat-admin.js
// SECTION: HELPERS — moved to script level below

}

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
