// project-page.js — кнопки страницы проекта и баннер обновления хаба.
const slug = document.body.dataset.slug || '';
const $ = (id) => document.getElementById(id);

function escapeHtml(str) {
  return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

async function api(url, options = {}) {
  const res = await fetch(url, options);
  return res.json();
}

function showLog(el, text) {
  el.hidden = false;
  el.textContent = text;
  el.scrollTop = el.scrollHeight;
}

// --- Баннер обновления хаба -------------------------------------------------
async function hubStatus(refresh = false) {
  const box = $('hub-update');
  const text = $('hub-update-text');
  box.hidden = false;
  if (refresh) {
    text.textContent = 'Fetch GitVerse...';
    const r = await api('/api/hub_version.php?action=refresh', { method: 'POST' });
    if (!r.ok) { text.textContent = '✗ ' + r.error; return; }
  }
  const r = await api('/api/hub_version.php?action=status');
  if (!r.ok) { text.textContent = '✗ ' + r.error; return; }
  const d = r.data;
  const stable = d.stable ? d.stable.label : '—';
  const edge = d.edge ? d.edge.label : '—';
  text.innerHTML = 'Текущая: <strong>' + escapeHtml(d.current_label) + '</strong>'
    + ' · Стабильная: <strong>' + escapeHtml(stable) + '</strong>'
    + ' · Edge: <strong>' + escapeHtml(edge) + '</strong>';
  $('hub-stable').hidden = !d.stable;
  $('hub-edge').hidden = !d.edge;
  $('hub-stable').textContent = '⬇ Стабильная ' + stable;
  $('hub-edge').textContent = '⚠ Edge ' + edge + ' (риск)';
}

async function hubUpdate(channel) {
  let confirmWord = '';
  if (channel === 'edge') {
    confirmWord = prompt('Нестабильная версия на ваш страх и риск. Введите EDGE для подтверждения:') || '';
    if (confirmWord !== 'EDGE') { return; }
  } else if (!window.confirm('Обновить хаб до стабильной версии?')) {
    return;
  }
  const log = $('hub-log');
  showLog(log, 'Обновление (' + channel + ')...');
  const r = await api('/api/hub_version.php?action=update', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ channel, confirm: confirmWord }),
  });
  showLog(log, (r.ok ? '✅ ' + (r.data.version || '') + '\n' : '❌ ' + r.error + '\n') + (r.data?.log || ''));
  if (r.ok) { hubStatus(); }
}

$('hub-check').addEventListener('click', () => hubStatus(true));
$('hub-stable').addEventListener('click', () => hubUpdate('stable'));
$('hub-edge').addEventListener('click', () => hubUpdate('edge'));
hubStatus();

// --- Кнопки проекта ----------------------------------------------------------
if (slug) {
  document.querySelectorAll('[data-op]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const op = btn.dataset.op;
      const log = $('git-log');
      btn.disabled = true;
      showLog(log, op + '...');
      const mode = op === 'merge' ? 'all' : op;
      const remote = op === 'pull' ? 'gitverse' : '';
      const r = await api('/api/sync.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ slug, mode, remote }),
      });
      showLog(log, (r.ok ? '✅ Успех\n' : '❌ ' + r.error + '\n') + (r.data?.log || ''));
      btn.disabled = false;
    });
  });

  $('show-commits').addEventListener('click', async () => {
    const list = $('commits-list');
    list.hidden = false;
    list.textContent = 'Загрузка...';
    const r = await api('/api/project_actions.php?action=log&slug=' + encodeURIComponent(slug));
    if (!r.ok) { list.textContent = '✗ ' + r.error; return; }
    list.innerHTML = r.data.commits.map((c) =>
      '<div class="commit-row"><span class="commit-sha">' + escapeHtml(c.sha) + '</span>'
      + '<span class="commit-date">' + escapeHtml(c.date) + '</span>'
      + '<span class="commit-subject">' + escapeHtml(c.subject) + '</span></div>'
    ).join('');
  });

  $('edit-desc').addEventListener('click', () => { $('desc-form').hidden = !$('desc-form').hidden; });
  $('save-desc').addEventListener('click', async () => {
    const r = await api('/api/project_actions.php?action=description', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ slug, description: $('desc-input').value }),
    });
    if (r.ok) { window.location.reload(); } else { window.alert('✗ ' + r.error); }
  });
}