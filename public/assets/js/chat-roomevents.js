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
  removePublicRoomFromSidebar(data.room_id);
}

