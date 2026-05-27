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

