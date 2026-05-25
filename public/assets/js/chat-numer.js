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
