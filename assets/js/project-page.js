// project-page.js — кнопки страницы проекта и баннер обновления хаба.
// Консолидированная версия v0.0.3: базовые кнопки + автопроверка (троттлинг 24ч)
// + откат к backup-веткам + экспорт zip + удаление локально.
// Требования к разметке: баннер #hub-update на ОБЕИХ экранах (главная и проект),
// data-slug на <body>, кнопки #export-project и #delete-local на странице проекта.
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

$('hub-check').addEventListener('click', async () => {
  const box = $('hub-update');
  $('hub-update-text').textContent = 'Fetch GitVerse...';
  box.hidden = false;
  await api('/api/hub_version.php?action=refresh&force=1', { method: 'POST' });
  hubStatus();
});
$('hub-stable').addEventListener('click', () => hubUpdate('stable'));
$('hub-edge').addEventListener('click', () => hubUpdate('edge'));

// --- Откат ---
const rollbackBtn = document.createElement('button');
rollbackBtn.type = 'button';
rollbackBtn.className = 'btn';
rollbackBtn.id = 'hub-rollback';
rollbackBtn.textContent = '↩ Откат';
document.querySelector('.hub-update-buttons').appendChild(rollbackBtn);

rollbackBtn.addEventListener('click', async () => {
  const r = await api('/api/hub_version.php?action=backups');
  if (!r.ok || r.data.backups.length === 0) { window.alert('Точек отката нет'); return; }
  const list = r.data.backups.map((b, i) => (i + 1) + ': ' + b.branch + ' (' + b.date + ')').join('\n');
  const pick = window.prompt('Точки отката:\n' + list + '\n\nНомер для отката (пусто — отмена):');
  const idx = parseInt(pick || '', 10) - 1;
  if (!(idx >= 0 && idx < r.data.backups.length)) { return; }
  const b = r.data.backups[idx];
  if (!window.confirm('Откатить хаб к ' + b.branch + '? Текущее состояние сохранится в backup.')) { return; }
  const log = $('hub-log');
  showLog(log, 'Откат...');
  const rr = await api('/api/hub_version.php?action=rollback', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ branch: b.branch }),
  });
  showLog(log, (rr.ok ? '✅ Откат выполнен\n' : '❌ ' + rr.error + '\n') + (rr.data?.log || ''));
  if (rr.ok) { hubStatus(); }
});

// Автопроверка при входе: сервер сам решает, нужен ли fetch (троттлинг 24ч).
hubStatus(true);

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

  $('export-project').addEventListener('click', async () => {
    const btn = $('export-project');
    btn.disabled = true;
    btn.textContent = '⏳ Экспорт...';
    const r = await api('/api/project_actions.php?action=export&slug=' + encodeURIComponent(slug), { method: 'POST' });
    btn.disabled = false;
    btn.textContent = '📦 Экспорт проекта (zip)';
    if (r.ok) {
      window.location.href = r.data.download_url;
    } else {
      window.alert('✗ ' + r.error);
    }
  });

  $('delete-local').addEventListener('click', async () => {
    const word = window.prompt('Удаление проекта ' + slug + ' с диска необратимо.\nВведите DELETE-LOCAL для подтверждения:');
    if (word !== 'DELETE-LOCAL') { return; }
    const r = await api('/api/projects_remove.php?slug=' + encodeURIComponent(slug), {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ confirm: 'DELETE-LOCAL' }),
    });
    if (r.ok) { window.location.href = '/'; } else { window.alert('✗ ' + r.error); }
  });
    // --- Статус репозитория ---
  $('git-status').addEventListener('click', async () => {
    const box = $('status-box');
    box.hidden = false;
    box.textContent = 'Загрузка…';
    const r = await api('/api/project_actions.php?action=status&slug=' + encodeURIComponent(slug));
    if (!r.ok) { box.textContent = '✗ ' + r.error; return; }
    const d = r.data;
    let html = '<div class="status-row"><span>Ветка</span><strong>' + escapeHtml(d.branch || 'detached HEAD') + '</strong></div>';
    html += '<div class="status-row"><span>Незакоммиченных</span><strong>' + d.dirty_count + '</strong></div>';
    d.remotes.forEach((rm) => {
      html += '<div class="status-row"><span>' + escapeHtml(rm.name) + '</span><strong>'
        + (rm.behind === null ? 'ветки нет' : '↓' + rm.behind + ' ↑' + rm.ahead) + '</strong></div>';
    });
    if (d.dirty.length) { html += '<pre class="hub-log">' + escapeHtml(d.dirty.join('\n')) + '</pre>'; }
    box.innerHTML = html;
  });

  // --- Файловый менеджер (read-only) ---
  const fmState = { path: '' };
  async function fmLoad(path) {
    fmState.path = path;
    const list = $('fm-list');
    const view = $('fm-view');
    $('fm-box').hidden = false;
    $('fm-back').hidden = true;
    view.hidden = true;
    list.hidden = false;
    list.textContent = 'Загрузка…';
    const r = await api('/api/project_actions.php?action=files&slug=' + encodeURIComponent(slug) + '&path=' + encodeURIComponent(path));
    if (!r.ok) { list.textContent = '✗ ' + r.error; return; }
    const segs = path === '' ? [] : path.split('/');
    let acc = '';
    let crumbs = '<button type="button" class="fm-crumb" data-path="">' + escapeHtml(slug) + '</button>';
    segs.forEach((s) => {
      acc = acc === '' ? s : acc + '/' + s;
      crumbs += ' / <button type="button" class="fm-crumb" data-path="' + escapeHtml(acc) + '">' + escapeHtml(s) + '</button>';
    });
    $('fm-crumbs').innerHTML = crumbs;
    list.innerHTML = r.data.entries.map((e) =>
      '<button type="button" class="fm-row" data-name="' + escapeHtml(e.name) + '" data-dir="' + (e.dir ? '1' : '0') + '">'
      + (e.dir ? '📁' : '📄') + ' ' + escapeHtml(e.name)
      + (e.dir ? '' : ' <span class="fm-size">' + e.size + ' B</span>') + '</button>'
    ).join('') || '<p class="fm-empty">Каталог пуст</p>';
  }
  $('fm-open').addEventListener('click', () => {
    if ($('fm-box').hidden) { fmLoad(''); } else { $('fm-box').hidden = true; }
  });
  $('fm-crumbs').addEventListener('click', (ev) => {
    const c = ev.target.closest('.fm-crumb');
    if (c) { fmLoad(c.dataset.path); }
  });
  $('fm-back').addEventListener('click', () => {
    $('fm-view').hidden = true;
    $('fm-list').hidden = false;
    $('fm-back').hidden = true;
  });
  $('fm-list').addEventListener('click', async (ev) => {
    const row = ev.target.closest('.fm-row');
    if (!row) { return; }
    const path = fmState.path === '' ? row.dataset.name : fmState.path + '/' + row.dataset.name;
    if (row.dataset.dir === '1') { fmLoad(path); return; }
    const view = $('fm-view');
    $('fm-list').hidden = true;
    $('fm-back').hidden = false;
    view.hidden = false;
    view.textContent = 'Загрузка…';
    const r = await api('/api/project_actions.php?action=file&slug=' + encodeURIComponent(slug) + '&path=' + encodeURIComponent(path));
    view.textContent = r.ok ? r.data.content : '✗ ' + r.error;
  });

  // --- Мета-данные ---
  $('edit-meta').addEventListener('click', async () => {
    const form = $('meta-form');
    if (!form.hidden) { form.hidden = true; return; }
    const r = await api('/api/project_actions.php?action=meta&slug=' + encodeURIComponent(slug));
    if (!r.ok) { window.alert('✗ ' + r.error); return; }
    $('meta-version').value = r.data.version || '';
    $('meta-homepage').value = r.data.homepage || '';
    $('meta-license').value = r.data.license || '';
    $('meta-keywords').value = (r.data.keywords || []).join(', ');
    form.hidden = false;
  });
  $('save-meta').addEventListener('click', async () => {
    const r = await api('/api/project_actions.php?action=metadata&slug=' + encodeURIComponent(slug), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        version: $('meta-version').value,
        homepage: $('meta-homepage').value,
        license: $('meta-license').value,
        keywords: $('meta-keywords').value,
      }),
    });
    if (r.ok) {
      $('meta-form').hidden = true;
      window.alert('✅ Сохранено в ' + r.data.file);
    } else {
      window.alert('✗ ' + r.error);
    }
  });
}