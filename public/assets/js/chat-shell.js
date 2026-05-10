(function(window, $) {
  'use strict';

  const MOBILE_QUERY = '(max-width: 768px)';
  const mq = window.matchMedia(MOBILE_QUERY);

  let initialized = false;

  function isMobile() {
    return mq.matches;
  }

  function isVisible($el) {
    const el = $el[0];
    if (!el) return false;

    const style = window.getComputedStyle(el);
    const rect = el.getBoundingClientRect();

    return style.display !== 'none'
      && style.visibility !== 'hidden'
      && rect.width > 0
      && rect.height > 0;
  }

  function canUseLeftDrawer() {
    return isVisible($('#toggleSidebar'));
  }

  function canUseRightDrawer() {
    return isVisible($('#toggleUsersPanel'));
  }

  function elements() {
    return {
      left: $('#sidebar-left'),
      right: $('#panel-right'),
      backdrop: $('#chat-shell-backdrop')
    };
  }

  function closeAll() {
    const els = elements();
    els.left.removeClass('show');
    els.right.removeClass('show');
    els.backdrop.removeClass('show');
  }

  function showBackdrop() {
    $('#chat-shell-backdrop').addClass('show');
  }

  function openLeft() {
    if (!canUseLeftDrawer()) {
      closeAll();
      return;
    }

    const els = elements();
    els.right.removeClass('show');
    els.left.addClass('show');
    showBackdrop();
  }

  function openRight() {
    if (!canUseRightDrawer()) {
      closeAll();
      return;
    }

    const els = elements();
    els.left.removeClass('show');
    els.right.addClass('show');
    showBackdrop();
  }

  function toggleLeft() {
    if ($('#sidebar-left').hasClass('show')) {
      closeAll();
      return;
    }
    openLeft();
  }

  function toggleRight() {
    if ($('#panel-right').hasClass('show')) {
      closeAll();
      return;
    }
    openRight();
  }

  function handleViewportChange() {
    if (!canUseLeftDrawer() && !canUseRightDrawer()) {
      closeAll();
    }
  }

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, function(ch) {
      return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[ch];
    });
  }

  function displayName(user) {
    return user?.nickname || user?.username || '';
  }

  function renderMobileUsersRail(users) {
    const list = Array.isArray(users) ? users : [];
    const $count = $('#mobile-users-rail-count');
    const $users = $('#mobile-users-rail-list');

    if (!$count.length || !$users.length) return;

    $count.text(list.length);
    $users.empty();

    list.forEach(function(user) {
      const name = displayName(user);
      const avatar = user?.avatar_url || '/assets/avatar-default.svg';

      $users.append(
        `<button type="button" class="mobile-users-rail-user" title="${esc(name)}" aria-label="${esc(name)}">
          <img src="${esc(avatar)}" alt="">
        </button>`
      );
    });
  }

  function init() {
    if (initialized) return;
    initialized = true;

    $('#toggleSidebar').on('click.chatShell', function(e) {
      e.preventDefault();
      toggleLeft();
    });
    $('#toggleUsersPanel').on('click.chatShell', function(e) {
      e.preventDefault();
      toggleRight();
    });
    $('#chat-shell-backdrop').on('click.chatShell', closeAll);
    $('#mobile-users-rail').on('click.chatShell', function(e) {
      e.preventDefault();
      openRight();
    });

    $(document).on('keydown.chatShell', function(e) {
      if (e.key === 'Escape') {
        closeAll();
      }
    });

    if (typeof mq.addEventListener === 'function') {
      mq.addEventListener('change', handleViewportChange);
    } else if (typeof mq.addListener === 'function') {
      mq.addListener(handleViewportChange);
    }
  }

  window.ChatShell = {
    init: init,
    closeAll: closeAll,
    openLeft: openLeft,
    openRight: openRight,
    renderMobileUsersRail: renderMobileUsersRail
  };

  $(init);
})(window, window.jQuery);
