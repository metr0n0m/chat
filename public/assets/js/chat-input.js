function normalizeWhisperContent(content, username) {
  let text = String(content || '').trim();
  if (!text) return '';

  const target = String(username || '').trim();
  if (target) {
    const escTarget = target.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    text = text.replace(new RegExp(`^@p\\+${escTarget}\\s+`, 'iu'), '');
    text = text.replace(new RegExp(`^${escTarget},\\s*`, 'iu'), '');
  }

  text = text.replace(/^@p\+\S+\s+/iu, '').trim();
  return text;
}

function wrapSelection(el, marker) {
  const start = el.selectionStart;
  const end   = el.selectionEnd;
  const val   = el.value;
  const sel   = val.slice(start, end);
  el.value = val.slice(0, start) + marker + sel + marker + val.slice(end);
  el.selectionStart = start + marker.length;
  el.selectionEnd   = end + marker.length;
  el.focus();
}
