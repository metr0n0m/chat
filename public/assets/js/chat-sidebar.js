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

