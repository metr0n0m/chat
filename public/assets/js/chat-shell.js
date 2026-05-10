(function(window, $) {
  'use strict';

  const MOBILE_QUERY = '(max-width: 768px)';
  const mq = window.matchMedia(MOBILE_QUERY);

  let initialized = false;

  function isMobile() {
    return mq.matches;
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
    if (!isMobile()) return;
    const els = elements();
    els.right.removeClass('show');
    els.left.addClass('show');
    showBackdrop();
  }

  function openRight() {
    if (!isMobile()) return;
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
    if (!isMobile()) {
      closeAll();
    }
  }

  function init() {
    if (initialized) return;
    initialized = true;

    $('#toggleSidebar').on('click.chatShell', toggleLeft);
    $('#toggleUsersPanel').on('click.chatShell', toggleRight);
    $('#chat-shell-backdrop').on('click.chatShell', closeAll);

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
    openRight: openRight
  };

  $(init);
})(window, window.jQuery);
