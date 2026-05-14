const ChatAuthConfig = window.ChatConfig || {};

window.addEventListener('pageshow', function(e) {
  if (!e.persisted) return;
  fetch('/api/csrf', { credentials: 'same-origin' })
    .then(r => r.json())
    .then(d => {
      if (!d.token) return;
      ChatAuthConfig.csrfToken = d.token;
      document.querySelectorAll('[name="csrf_token"]').forEach(el => { el.value = d.token; });
    })
    .catch(() => {});
});

$('#loginForm').on('submit', function(e) {
  e.preventDefault();
  $('#loginError').addClass('d-none');
  if (ChatAuthConfig.csrfToken) $(this).find('[name="csrf_token"]').val(ChatAuthConfig.csrfToken);
  $.post('/auth/login', $(this).serialize(), function(resp) {
    if (resp.success) location.href = resp.redirect || '/';
    else $('#loginError').text(resp.error).removeClass('d-none');
  }).fail(function(xhr) {
    const err = xhr.responseJSON?.error || '׀ׁˆ׀¸׀±׀÷׀°';
    $('#loginError').text(err).removeClass('d-none');
  });
});

$('#registerForm').on('submit', function(e) {
  e.preventDefault();
  $('#registerError').addClass('d-none');
  if (ChatAuthConfig.csrfToken) $(this).find('[name="csrf_token"]').val(ChatAuthConfig.csrfToken);
  $.post('/auth/register', $(this).serialize(), function(resp) {
    if (resp.success) location.href = resp.redirect || '/';
    else $('#registerError').text(resp.error).removeClass('d-none');
  }).fail(function(xhr) {
    const err = xhr.responseJSON?.error || '׀ׁˆ׀¸׀±׀÷׀°';
    $('#registerError').text(err).removeClass('d-none');
  });
});
