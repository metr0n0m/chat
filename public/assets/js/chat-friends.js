// ════════════════════════════════════════════════
//  FRIENDS
// ════════════════════════════════════════════════
// SECTION: FRIENDS
function loadFriends() {
  $.get('/api/friends', function(resp) {
    if (!resp.success) return;
    renderFriends(resp.friends);
  });
}

function renderFriends(friends) {
  const $list = $('#friends-list').empty();
  const q = $('#friend-search').val().toLowerCase();
  const filtered = q ? friends.filter(f => f.username.toLowerCase().includes(q)) : friends;
  filtered.forEach(f => {
    const isOnline = !!f.current_room;
    const dot = `<span class="online-dot ${isOnline?'online':'offline'}"></span>`;
    const where = isOnline ? `<span class="text-muted small ms-1">${esc(f.current_room)}</span>` : `<span class="text-muted small ms-1">${f.last_seen_at ? dayjs(f.last_seen_at).fromNow() : ''}</span>`;
    $list.append(`<div class="friend-item">${dot} <span>${esc(displayName(f))}</span>${where}</div>`);
  });
}

$('#friend-search').on('input', function() { loadFriends(); });
