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
  const form = this;
  $('#loginError').addClass('d-none');
  if (!form.checkValidity()) {
    form.classList.add('was-validated');
    return;
  }
  form.classList.remove('was-validated');
  if (ChatAuthConfig.csrfToken) $(form).find('[name="csrf_token"]').val(ChatAuthConfig.csrfToken);
  $.post('/auth/login', $(form).serialize(), function(resp) {
    if (resp.success) location.href = resp.redirect || '/';
    else $('#loginError').text(resp.error).removeClass('d-none');
  }).fail(function(xhr) {
    const err = xhr.responseJSON?.error || 'Ошибка';
    $('#loginError').text(err).removeClass('d-none');
  });
});

$('#registerForm').on('submit', function(e) {
  e.preventDefault();
  const form = this;
  $('#registerError').addClass('d-none');

  if (!form.checkValidity()) {
    form.classList.add('was-validated');
    return;
  }
  form.classList.remove('was-validated');

  if (ChatAuthConfig.csrfToken) $(form).find('[name="csrf_token"]').val(ChatAuthConfig.csrfToken);

  $.post('/auth/register', $(form).serialize(), function(resp) {
    if (resp.pending_verification) {
      systemAlert('Регистрация прошла успешно!<br>Ссылка для активации отправлена на <strong>' + esc(resp.email || '') + '</strong>.<br>Перейдите по ней, затем войдите.', 'success', 8000);
      form.reset();
      form.classList.remove('was-validated');
    } else if (resp.success) {
      location.href = resp.redirect || '/';
    } else {
      $('#registerError').text(resp.error).removeClass('d-none');
    }
  }).fail(function(xhr) {
    const err = xhr.responseJSON?.error || 'Ошибка сервера';
    systemAlert(esc(err), 'danger', 6000);
  });
});

