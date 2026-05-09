const ChatAuthConfig = window.ChatConfig || {};

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
