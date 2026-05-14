function esc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function displayName(u) {
  if (!u) return '';
  const n = (u.nickname || '').trim();
  return n ? n : (u.username || '');
}

function isDarkTheme() {
  return document.documentElement.getAttribute('data-bs-theme') === 'dark';
}

function hexToHsl(hex) {
  let r = parseInt(hex.slice(1,3),16)/255, g = parseInt(hex.slice(3,5),16)/255, b = parseInt(hex.slice(5,7),16)/255;
  const max = Math.max(r,g,b), min = Math.min(r,g,b), d = max - min;
  let h = 0, s = 0, l = (max + min) / 2;
  if (d) {
    s = d / (1 - Math.abs(2*l - 1));
    h = max === r ? ((g-b)/d + (g<b?6:0))/6 : max === g ? ((b-r)/d + 2)/6 : ((r-g)/d + 4)/6;
  }
  return [h, s, l];
}

function hslToHex(h, s, l) {
  const f = n => { const k=(n+h*12)%12, a=s*Math.min(l,1-l); return Math.round(255*(l-a*Math.max(-1,Math.min(k-3,9-k,1)))); };
  return '#' + [f(0),f(8),f(4)].map(v=>v.toString(16).padStart(2,'0')).join('');
}

function wcagLuminance(hex) {
  return [parseInt(hex.slice(1,3),16), parseInt(hex.slice(3,5),16), parseInt(hex.slice(5,7),16)]
    .map(c => { c/=255; return c<=0.03928 ? c/12.92 : ((c+0.055)/1.055)**2.4; })
    .reduce((sum,c,i) => sum + c * [0.2126,0.7152,0.0722][i], 0);
}

function wcagContrast(h1, h2) {
  const l1 = wcagLuminance(h1), l2 = wcagLuminance(h2);
  return (Math.max(l1,l2) + 0.05) / (Math.min(l1,l2) + 0.05);
}

function roleLabel(role) {
  return {platform_owner:'Владелец', admin:'Глобальный администратор', moderator:'Глобальный модератор', user:''}[role] || role;
}

function roomRoleLabel(role) {
  return {owner:'Владелец комнаты', local_admin:'Локальный администратор', local_moderator:'Локальный модератор', member:'', banned:'Забанен'}[role] || role;
}

function systemAlert(msg, type, duration) {
  type = type || 'success';
  duration = (duration === undefined) ? 4000 : duration;
  const container = document.getElementById('system-alerts');
  if (!container) return;
  const el = document.createElement('div');
  el.className = 'alert alert-' + type + ' alert-dismissible shadow mb-2 py-2 px-3';
  el.style.cssText = 'opacity:0;transition:opacity .25s;pointer-events:auto';
  el.innerHTML = msg + '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
  container.appendChild(el);
  requestAnimationFrame(function() { el.style.opacity = '1'; });
  if (duration > 0) {
    setTimeout(function() {
      el.style.opacity = '0';
      setTimeout(function() { if (el.parentNode) el.parentNode.removeChild(el); }, 280);
    }, duration);
  }
}
