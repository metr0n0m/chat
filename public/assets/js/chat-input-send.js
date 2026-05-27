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
