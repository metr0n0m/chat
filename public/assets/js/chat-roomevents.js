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

// ─── Mute state — silent helpers (no toasts) ─────────────────────────────────
let _muteTimer = null;

function applyMuteState(muteUntil) {
  const until = muteUntil ? dayjs(muteUntil).format('HH:mm:ss') : '';
  $('#msg-input').prop('disabled', true);
  $('#send-btn').prop('disabled', true);
  $('#mute-banner-text').text(`Кляп${until ? ` до ${until}` : ''}`);
  $('#mute-banner').removeClass('d-none');
  scheduleMuteExpiry(muteUntil);
}

function clearMuteState() {
  $('#msg-input').prop('disabled', false);
  $('#send-btn').prop('disabled', $('#msg-input').val().trim().length === 0 || !currentRoomId);
  $('#mute-banner').addClass('d-none');
  clearMuteExpiry();
}

function scheduleMuteExpiry(muteUntil) {
  clearMuteExpiry();
  if (!muteUntil) return;
  const ms = dayjs(muteUntil).diff(dayjs());
  if (ms <= 0) { clearMuteState(); return; }
  _muteTimer = setTimeout(() => clearMuteState(), ms);
}

function clearMuteExpiry() {
  if (_muteTimer) { clearTimeout(_muteTimer); _muteTimer = null; }
}

// ─── WS-handlers (live events — with toasts) ─────────────────────────────────

function onMutedInRoom(data) {
  if (data.room_id !== currentRoomId) return;
  if (Number(data.target_user_id) === Number(CURRENT_USER.id)) {
    const until = data.muted_until ? dayjs(data.muted_until).format('HH:mm:ss') : '';
    const reason = data.reason ? ` Причина: ${data.reason}` : '';
    showToast(`Вам выдан кляп${until ? ` до ${until}` : ''}.${reason}`, 'warning');
    applyMuteState(data.muted_until);
  }
}

function onUnmutedInRoom(data) {
  if (data.room_id !== currentRoomId) return;
  if (Number(data.target_user_id ?? CURRENT_USER.id) === Number(CURRENT_USER.id)) {
    showToast('Кляп снят.', 'success');
    clearMuteState();
  }
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
  removePublicRoomFromSidebar(data.room_id);
}

