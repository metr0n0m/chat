const CHAT_DISPLAY_DEFAULT_AVATAR_URL = '/assets/avatar-default.svg';

function avatarMarkup(url, size = 42) {
  return `<img src="${esc(url || CHAT_DISPLAY_DEFAULT_AVATAR_URL)}" width="${size}" height="${size}" alt="" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src='${CHAT_DISPLAY_DEFAULT_AVATAR_URL}'">`;
}

function visibleRoleLabel(u) {
  const currentUser = (window.ChatConfig || {}).currentUser;
  if (u && currentUser && Number(u.id) === Number(currentUser.id) && currentUser.custom_status) return String(currentUser.custom_status);
  if (u.custom_status) return String(u.custom_status);
  if (u.room_role && !['member', 'banned'].includes(u.room_role)) return roomRoleLabel(u.room_role);
  if (u.global_role && u.global_role !== 'user') return roleLabel(u.global_role);
  return '';
}

function visibleRoleClass(u) {
  if (u.room_role && !['member', 'banned'].includes(u.room_role)) return 'bg-info';
  if (u.global_role === 'platform_owner') return 'bg-dark';
  if (u.global_role === 'admin') return 'bg-danger';
  if (u.global_role === 'moderator') return 'bg-warning text-dark';
  return 'bg-secondary';
}
