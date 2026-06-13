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
    loadOwnerOverview();
    const el = document.getElementById('ownerModal');
    if (el) bootstrap.Modal.getOrCreateInstance(el).show();
  });

  // Tab switch
  $('#adminTabs a[data-bs-toggle="tab"]').on('shown.bs.tab', function() {
    const tab = $(this).attr('href');
    if (tab === '#adminDash')     loadAdminDash();
    if (tab === '#adminUsers')    loadAdminUsers();
    if (tab === '#adminRooms')    loadAdminRooms();
    if (tab === '#adminBans')     loadAdminBans();
    if (tab === '#adminSanctions') loadSanctions();
  });

  $('#sanctionsSubTabs a[data-bs-toggle="tab"]').on('shown.bs.tab', function() {
    const tab = $(this).attr('href');
    if (tab === '#sancEvents')    loadSanctionEvents();
    if (tab === '#sancShadow')    loadSanctionShadow();
    if (tab === '#sancStopwords') loadSanctionStopwords();
    if (tab === '#sancIntel')     loadSanctionIntel();
  });

  $('#ownerTabs a[data-bs-toggle="tab"]').on('shown.bs.tab', function() {
    const tab = $(this).attr('href');
    if (tab === '#ownerOverview')  loadOwnerOverview();
    if (tab === '#ownerNumera')    loadAdminNumera();
    if (tab === '#ownerWhispers')  loadOwnerWhisperSessions();
    if (tab === '#ownerSettings')  loadAdminSettings();
  });

  $('#admin-user-search-btn').on('click', loadAdminUsers);
  $('#whisper-search-btn').on('click', function() { loadOwnerWhisperSessions(); });

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

function loadOwnerOverview() {
  $.get('/api/admin/owner-overview', function(resp) {
    if (!resp.success) return;
    const s = resp.stats;
    const html = ''
      + '<h6 class="text-muted mt-2 mb-2">Пользователи</h6>'
      + '<div class="row g-3">'
      + statCard('Всего', s.users_total, 'fa-users', 'primary')
      + statCard('Активность за час', s.users_active_1h, 'fa-circle', 'success')
      + '</div>'
      + '<h6 class="text-muted mt-3 mb-2">Комнаты</h6>'
      + '<div class="row g-3">'
      + statCard('Всего комнат', s.rooms_total, 'fa-door-open', 'secondary')
      + statCard('Публичных активных', s.rooms_public, 'fa-door-open', 'info')
      + statCard('Нумеров активных', s.numera_active, 'fa-lock', 'warning')
      + '</div>'
      + '<h6 class="text-muted mt-3 mb-2">Шёпот</h6>'
      + '<div class="row g-3">'
      + statCard('Whisper сообщений', s.whisper_messages, 'fa-comment', 'primary')
      + statCard('Уникальных пар', s.whisper_pairs, 'fa-comments', 'info')
      + '</div>'
      + '<h6 class="text-muted mt-3 mb-2">Система</h6>'
      + '<div class="row g-3">'
      + statCard('Сообщений всего', s.messages_total, 'fa-envelope', 'secondary')
      + statCard('Сообщений за 24ч', s.messages_24h, 'fa-clock', 'success')
      + statCard('Активных банов', s.active_bans, 'fa-ban', 'danger')
      + '</div>';
    $('#ownerOverview-stats').html(html);
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

function loadOwnerWhisperSessions(page) {
  page = page || 1;
  const params = {page};
  const df = $('#session-filter-date-from').val();
  const dt = $('#session-filter-date-to').val();
  if (df) params.date_from = df;
  if (dt) params.date_to   = dt;

  $.get('/api/admin/whispers/sessions', params, function(resp) {
    if (!resp.success) return;
    let html = '';
    if (!resp.sessions.length) {
      html = '<div class="text-muted p-2">Сессий не найдено.</div>';
    } else {
      html = '<table class="table table-sm table-hover"><thead><tr>'
           + '<th>Инициатор</th><th>Собеседник</th><th>Начало</th><th>Конец</th><th>Сообщ.</th><th></th>'
           + '</tr></thead><tbody>';
      resp.sessions.forEach(function(s) {
        const other = s.initiator.id === s.user1.id ? s.user2 : s.user1;
        const label = s.initiator.username + ' → ' + other.username;
        html += '<tr>'
          + '<td>' + esc(s.initiator.username) + '</td>'
          + '<td>' + esc(other.username) + '</td>'
          + '<td class="text-nowrap">' + formatChatDateTime(s.started_at) + '</td>'
          + '<td class="text-nowrap">' + formatChatDateTime(s.ended_at) + '</td>'
          + '<td>' + s.count + '</td>'
          + '<td><button class="btn btn-sm btn-outline-info owner-session-open-btn"'
          + ' data-token="' + esc(s.session_token) + '"'
          + ' data-label="' + esc(label) + '"'
          + ' data-started="' + esc(s.started_at) + '"'
          + ' data-ended="' + esc(s.ended_at) + '"'
          + ' data-count="' + s.count + '"'
          + ' title="Открыть переписку"><i class="fa fa-eye"></i></button></td>'
          + '</tr>';
      });
      html += '</tbody></table>';
      if (resp.pages > 1) {
        html += '<div class="d-flex gap-1 mt-2 flex-wrap">';
        for (let p = 1; p <= resp.pages; p++) {
          html += '<button class="btn btn-sm owner-sessions-page-btn '
               + (p === resp.page ? 'btn-primary' : 'btn-outline-secondary')
               + '" data-page="' + p + '">' + p + '</button>';
        }
        html += '</div>';
      }
    }
    $('#owner-sessions-list').html(html).show();
    $('#owner-session-detail').addClass('d-none').empty();
  }, 'json');
}

function loadOwnerSessionDetail(token, label, startedAt, endedAt, count) {
  $.get('/api/admin/whispers/sessions/' + token, function(resp) {
    if (!resp.success) return;
    let html = '<div class="mb-2 d-flex align-items-center gap-2">'
      + '<button class="btn btn-sm btn-outline-secondary" id="owner-session-back-btn">'
      + '<i class="fa fa-arrow-left me-1"></i>Назад</button>'
      + '<span><strong>' + esc(label) + '</strong>'
      + ' <span class="text-muted">'
      + formatChatDateTime(startedAt) + ' – ' + formatChatDateTime(endedAt)
      + ' · ' + count + ' сообщений</span></span></div>';
    html += '<div style="overflow-x:auto"><table class="table table-sm"><tbody>';
    resp.messages.forEach(function(msg) {
      html += '<tr>'
        + '<td class="text-muted text-nowrap" style="width:90px">' + formatChatDateTime(msg.created_at) + '</td>'
        + '<td class="text-nowrap" style="width:120px"><strong>' + esc(msg.sender_username) + '</strong></td>'
        + '<td>' + esc(msg.content) + '</td>'
        + '</tr>';
    });
    html += '</tbody></table></div>';
    $('#owner-sessions-list').hide();
    $('#owner-session-detail').html(html).removeClass('d-none');
  }, 'json');
}

$(document).on('click', '#owner-session-back-btn', function() {
  $('#owner-session-detail').addClass('d-none').empty();
  $('#owner-sessions-list').show();
});

$(document).on('click', '.owner-session-open-btn', function() {
  loadOwnerSessionDetail(
    $(this).data('token'),
    $(this).data('label'),
    $(this).data('started'),
    $(this).data('ended'),
    $(this).data('count')
  );
});

$(document).on('click', '.owner-sessions-page-btn', function() {
  loadOwnerWhisperSessions($(this).data('page'));
});

function loadAdminBans() {
  $.get('/api/admin/bans', function(resp) {
    if (!resp.success) { $('#admin-bans-table').html('<div class="text-muted">Нет данных.</div>'); return; }
    if (!resp.bans || !resp.bans.length) {
      $('#admin-bans-table').html('<div class="text-muted p-2">Заблокированных нет.</div>');
      return;
    }
    let html = '<table class="table table-sm"><thead><tr>'
             + '<th>Пользователь</th><th>Тип</th><th>Комната</th><th>До / кляп</th><th></th>'
             + '</tr></thead><tbody>';
    resp.bans.forEach(b => {
      const scope = b.ban_scope || '';
      let typeLabel, roomLabel, untilLabel, actionBtn;

      if (scope === 'global') {
        typeLabel  = '<span class="badge bg-danger">global ban</span>';
        roomLabel  = '—';
        untilLabel = b.banned_until && dayjs(b.banned_until).isValid()
                     ? formatChatDateTime(b.banned_until) : 'навсегда';
        actionBtn  = `<button class="btn btn-xs btn-sm btn-outline-success admin-global-unban-btn" data-user="${b.user_id}" title="Снять global ban"><i class="fa fa-unlock"></i></button>`;

      } else if (scope === 'room') {
        typeLabel  = '<span class="badge bg-warning text-dark">room ban</span>';
        roomLabel  = esc(b.room_name || '—');
        untilLabel = '—';
        actionBtn  = `<button class="btn btn-xs btn-sm btn-outline-success admin-unban-btn" data-room="${b.room_id}" data-user="${b.user_id}" title="Разбанить"><i class="fa fa-unlock"></i></button>`;

      } else if (scope === 'mute') {
        typeLabel  = '<span class="badge bg-secondary">кляп</span>';
        roomLabel  = esc(b.room_name || '—');
        untilLabel = b.muted_until && dayjs(b.muted_until).isValid()
                     ? formatChatDateTime(b.muted_until) : '—';
        actionBtn  = `<button class="btn btn-xs btn-sm btn-outline-warning admin-unmute-btn" data-room="${b.room_id}" data-user="${b.user_id}" title="Снять кляп"><i class="fa fa-comment"></i></button>`;

      } else {
        console.warn('[admin bans] unknown ban_scope', b);
        typeLabel  = '<span class="badge bg-secondary">unknown</span>';
        roomLabel  = esc(b.room_name || '—');
        untilLabel = '—';
        actionBtn  = '';
      }

      html += `<tr><td>${esc(b.username)}</td><td>${typeLabel}</td><td>${roomLabel}</td><td>${untilLabel}</td><td>${actionBtn}</td></tr>`;
    });
    html += '</tbody></table>';
    $('#admin-bans-table').html(html);
  });
}

// Room ban → /api/admin/rooms/{roomId}/unban/{userId}
$('#admin-bans-table').on('click', '.admin-unban-btn', function() {
  const roomId = $(this).data('room'), userId = $(this).data('user');
  $.post(`/api/admin/rooms/${roomId}/unban/${userId}`, {csrf_token: CSRF_TOKEN}, function(resp) {
    if (resp.success) { showToast('Разбанен.', 'success'); loadAdminBans(); }
    else showToast(resp.error || 'Ошибка.', 'danger');
  }, 'json');
});

// Global ban → /api/admin/users/{userId} + is_banned:0
$('#admin-bans-table').on('click', '.admin-global-unban-btn', function() {
  const userId = $(this).data('user');
  $.post(`/api/admin/users/${userId}`, {csrf_token: CSRF_TOKEN, is_banned: 0}, function(resp) {
    if (resp.success) { showToast('Глобальный бан снят.', 'success'); loadAdminBans(); loadAdminUsers(); }
    else showToast(resp.error || 'Ошибка.', 'danger');
  }, 'json');
});

// Mute → /api/admin/rooms/{roomId}/unmute/{userId}
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
  $.post(`/api/admin/numera/${id}/close`, {csrf_token: CSRF_TOKEN}, function(resp) {
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
//  SANCTIONS ENGINE (S5)
// ════════════════════════════════════════════════
const SANC_ACT_LABEL = {
  mute: 'кляп', unmute: 'снят кляп',
  ban_room: 'бан в комнате', unban_room: 'разбан в комнате',
  ban_global: 'глобальный бан', unban_global: 'глобальный разбан',
  kick: 'кик', restriction_expired: 'истекло',
};
const SANC_TRIGGER_LABEL = {
  bruteforce: 'перебор паролей', stopword: 'стоп-слово',
  flood: 'флуд', spoof: 'подмена сессии',
};
function sancActLabel(a)     { return SANC_ACT_LABEL[a] || a; }
function sancTriggerLabel(t) { return t ? (SANC_TRIGGER_LABEL[t] || t) : '—'; }

function loadSanctions() {
  $.get('/api/admin/sanctions/stats', function(resp) {
    if (!resp.success) return;
    const s = resp.stats, a = s.autonomy || {};
    $('#sanctions-summary').html(
      statCard('Активных мьютов', s.active_restrictions.mute, 'fa-comment-slash', 'secondary')
      + statCard('Банов в комнатах', s.active_restrictions.ban_room, 'fa-door-closed', 'warning')
      + statCard('Глобальных банов', s.active_restrictions.ban_global, 'fa-ban', 'danger')
      + statCard('Тень за 30д', s.shadow_30d, 'fa-eye', 'info')
    );
    // Режим автонома (элементы есть только у владельца платформы)
    const live = a.mode === 'live', paused = a.autonomy_state === 'paused';
    $('#sanc-mode-badge')
      .removeClass('bg-secondary bg-success bg-danger')
      .addClass(live ? 'bg-danger' : 'bg-secondary')
      .text(live ? 'БОЕВОЙ' : 'тень');
    $('#sanc-state-badge').toggleClass('d-none', !live)
      .removeClass('bg-success bg-warning')
      .addClass(paused ? 'bg-warning' : 'bg-success')
      .text(paused ? 'на паузе' : 'активен');
    $('#sanc-live-btn').toggleClass('d-none', live);
    $('#sanc-shadow-btn').toggleClass('d-none', !live);
    $('#sanc-resume-btn').toggleClass('d-none', !(live && paused));
    $('#sanc-mode-hint').text(live
      ? 'Система применяет санкции автоматически. Снять автономный бессрочный бан может только владелец платформы.'
      : 'Система только наблюдает и записывает, что сделала бы. Реальные баны не выдаются.');
    loadSanctionEvents();
  });
}

function sancRows(rows, cols) {
  if (!rows || !rows.length) return '<div class="text-muted p-2">Записей нет.</div>';
  let h = '<table class="table table-sm"><thead><tr>' + cols.map(c => `<th>${c[0]}</th>`).join('') + '</tr></thead><tbody>';
  rows.forEach(r => { h += '<tr>' + cols.map(c => `<td>${c[1](r)}</td>`).join('') + '</tr>'; });
  return h + '</tbody></table>';
}

function loadSanctionEvents() {
  $.get('/api/admin/sanctions/events', function(resp) {
    if (!resp.success) return;
    $('#sanc-events-table').html(sancRows(resp.events, [
      ['Время', r => r.created_at ? formatChatDateTime(r.created_at) : '—'],
      ['Действие', r => esc(sancActLabel(r.act))],
      ['Кто', r => r.origin === 'system' ? '<span class="badge bg-info">система</span>' : esc(r.actor_username || '—')],
      ['Кого', r => esc(r.target_username || '—')],
      ['Комната', r => esc(r.room_name || '—')],
      ['Триггер', r => esc(sancTriggerLabel(r.trigger_code))],
      ['Срок', r => esc(r.duration_type || '—')],
      ['Причина', r => esc(r.reason || '—')],
    ]));
  });
}

function loadSanctionShadow() {
  $.get('/api/admin/sanctions/shadow', function(resp) {
    if (!resp.success) return;
    $('#sanc-shadow-table').html(sancRows(resp.shadow, [
      ['Время', r => r.created_at ? formatChatDateTime(r.created_at) : '—'],
      ['Триггер', r => esc(sancTriggerLabel(r.trigger_code))],
      ['Цель', r => esc(r.target_username || r.target_ip || '—')],
      ['Комната', r => esc(r.room_name || '—')],
      ['Выдало бы', r => esc(sancActLabel(r.would_sanction))],
      ['Срок', r => esc(r.would_duration || '—')],
      ['Детали', r => esc(r.details || '—')],
    ]));
  }).fail(() => $('#sanc-shadow-table').html('<div class="text-muted p-2">Недоступно.</div>'));
}

function loadSanctionStopwords() {
  $.get('/api/admin/sanctions/stopwords', function(resp) {
    if (!resp.success) return;
    $('#sanc-stopwords-table').html(sancRows(resp.stop_words, [
      ['Слово', r => esc(r.pattern)],
      ['Срок санкции', r => esc(r.duration)],
      ['Добавлено', r => r.created_at ? formatChatDateTime(r.created_at) : '—'],
      ['', r => `<button class="btn btn-sm btn-outline-danger sanc-sw-del" data-id="${r.id}"><i class="fa fa-trash"></i></button>`],
    ]));
  });
}

function loadSanctionIntel() {
  $.get('/api/admin/sanctions/ip-intel', function(resp) {
    if (!resp.success) return;
    if (!resp.alerts || !resp.alerts.length) {
      $('#sanc-intel-table').html('<div class="text-muted p-2">Подозрительных совпадений по IP нет.</div>');
      return;
    }
    $('#sanc-intel-table').html(sancRows(resp.alerts, [
      ['IP', a => esc(a.ip)],
      ['Забанены', a => a.banned.map(esc).join(', ')],
      ['Активны с того же IP', a => '<span class="text-warning">' + a.others.map(esc).join(', ') + '</span>'],
    ]) + '<div class="text-muted small p-1">Сигнал для ручного разбора. Автоматический бан по совпадению IP не выполняется.</div>');
  });
}

// — управление режимом (владелец платформы) —
function sancSetMode(value, extra) {
  $.post('/api/admin/sanctions/config', Object.assign({csrf_token: CSRF_TOKEN, key: 'mode', value}, extra || {}), function(resp) {
    if (resp.success) { showToast('Режим обновлён.', 'success'); loadSanctions(); }
  }, 'json').fail(function(xhr) {
    const err = xhr.responseJSON?.error || 'Ошибка.';
    if (xhr.status === 409 && confirm(err + '\n\nВключить боевой режим?')) {
      sancSetMode('live', {confirm: 1});
    } else {
      showToast(err, 'danger');
    }
  });
}
$(document).on('click', '#sanc-live-btn',   () => sancSetMode('live'));
$(document).on('click', '#sanc-shadow-btn', () => sancSetMode('shadow'));
$(document).on('click', '#sanc-resume-btn', function() {
  $.post('/api/admin/sanctions/resume', {csrf_token: CSRF_TOKEN}, function(resp) {
    if (resp.success) { showToast('Автоном возобновлён.', 'success'); loadSanctions(); }
  }, 'json').fail(xhr => showToast(xhr.responseJSON?.error || 'Ошибка.', 'danger'));
});

// — стоп-слова —
$(document).on('click', '#sanc-sw-add-btn', function() {
  const pattern = $('#sanc-sw-input').val().trim();
  const duration = $('#sanc-sw-duration').val();
  if (pattern.length < 2) { showToast('Слишком короткое слово.', 'warning'); return; }
  $.post('/api/admin/sanctions/stopwords', {csrf_token: CSRF_TOKEN, pattern, duration}, function(resp) {
    if (resp.success) { $('#sanc-sw-input').val(''); loadSanctionStopwords(); }
  }, 'json').fail(xhr => showToast(xhr.responseJSON?.error || 'Ошибка.', 'danger'));
});
$(document).on('click', '.sanc-sw-del', function() {
  const id = $(this).data('id');
  $.ajax({
    url: `/api/admin/sanctions/stopwords/${id}`,
    method: 'DELETE',
    headers: {'X-CSRF-Token': CSRF_TOKEN},
    success: () => loadSanctionStopwords(),
    error: xhr => showToast(xhr.responseJSON?.error || 'Ошибка.', 'danger'),
  });
});

