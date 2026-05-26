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
