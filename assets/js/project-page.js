// project-page.js — устойчивая версия: guard'ы на каждый элемент,
// slug из data-slug ИЛИ из URL /hub/project/<slug>/, модуль не падает при неполной разметке.
const slug = document.body.dataset.slug || (() => {
  const m = location.pathname.match(/^\/hub\/project\/([^/]+)\/?$/);
  return m ? decodeURIComponent(m[1]) : '';
})();
const $ = (id) => document.getElementById(id);

function on(id, evt, fn) {
  const el = $(id);
  if (el) { el.addEventListener(evt, fn); }
  return el;
}

function escapeHtml(str) {
  return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

async function api(url, options = {}) {
  const res = await fetch(url, options);
  return res.json();
}

function showLog(el, text) {
  if (!el) { return; }
  el.hidden = false;
  el.textContent = text;
  el.scrollTop = el.scrollHeight;
}

// --- Баннер обновления хаба -------------------------------------------------
async function hubStatus(refresh = false) {
  const box = $('hub-update');
  const text = $('hub-update-text');
  if (!box || !text) { return; }
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
  if ($('hub-stable')) {
    $('hub-stable').hidden = !d.stable;
    $('hub-stable').textContent = '⬇ Стабильная ' + stable;
  }
  if ($('hub-edge')) {
    $('hub-edge').hidden = !d.edge;
    $('hub-edge').textContent = '⚠ Edge ' + edge + ' (риск)';
  }
}

async function hubUpdate(channel) {
  let confirmWord = '';
  if (channel === 'edge') {
    confirmWord = prompt('Нестабильная версия на ваш страх и риск. Введите EDGE для подтверждения:') || '';
    if (confirmWord !== 'EDGE') { return; }
  } else if (!window.confirm('Обновить хаб до стабильной версии?')) {
    return;
  }
  showLog($('hub-log'), 'Обновление (' + channel + ')...');
  const r = await api('/api/hub_version.php?action=update', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ channel, confirm: confirmWord }),
  });
  showLog($('hub-log'), (r.ok ? '✅ ' + (r.data.version || '') + '\n' : '❌ ' + r.error + '\n') + (r.data?.log || ''));
  if (r.ok) { hubStatus(); }
}

if ($('hub-update') && $('hub-check') && $('hub-stable') && $('hub-edge')) {
  on('hub-check', 'click', async () => {
    $('hub-update-text').textContent = 'Fetch GitVerse...';
    $('hub-update').hidden = false;
    await api('/api/hub_version.php?action=refresh&force=1', { method: 'POST' });
    hubStatus();
  });
  on('hub-stable', 'click', () => hubUpdate('stable'));
  on('hub-edge', 'click', () => hubUpdate('edge'));

  const wrap = document.querySelector('.hub-update-buttons');
  if (wrap) {
    const rollbackBtn = document.createElement('button');
    rollbackBtn.type = 'button';
    rollbackBtn.className = 'btn';
    rollbackBtn.id = 'hub-rollback';
    rollbackBtn.textContent = '↩ Откат';
    wrap.appendChild(rollbackBtn);
    rollbackBtn.addEventListener('click', async () => {
      const r = await api('/api/hub_version.php?action=backups');
      if (!r.ok || r.data.backups.length === 0) { window.alert('Точек отката нет'); return; }
      const list = r.data.backups.map((b, i) => (i + 1) + ': ' + b.branch + ' (' + b.date + ')').join('\n');
      const pick = window.prompt('Точки отката:\n' + list + '\n\nНомер для отката (пусто — отмена):');
      const idx = parseInt(pick || '', 10) - 1;
      if (!(idx >= 0 && idx < r.data.backups.length)) { return; }
      const b = r.data.backups[idx];
      if (!window.confirm('Откатить хаб к ' + b.branch + '? Текущее состояние сохранится в backup.')) { return; }
      showLog($('hub-log'), 'Откат...');
      const rr = await api('/api/hub_version.php?action=rollback', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ branch: b.branch }),
      });
      showLog($('hub-log'), (rr.ok ? '✅ Откат выполнен\n' : '❌ ' + rr.error + '\n') + (rr.data?.log || ''));
      if (rr.ok) { hubStatus(); }
    });
  }
  hubStatus(true);
} else {
  console.warn('project-page: баннер #hub-update отсутствует — обновление хаба доступно через CLI');
}

// --- Кнопки проекта ----------------------------------------------------------
if (slug) {
  document.querySelectorAll('[data-op]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const op = btn.dataset.op;
      btn.disabled = true;
      showLog($('git-log'), op + '...');
      const mode = op === 'merge' ? 'all' : op;
      const remote = op === 'pull' ? (document.body.dataset.remote || 'origin') : '';
      const r = await api('/api/sync.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ slug, mode, remote }),
      });
      showLog($('git-log'), (r.ok ? '✅ Успех\n' : '❌ ' + r.error + '\n') + (r.data?.log || ''));
      btn.disabled = false;
    });
  });

  on('git-status', 'click', async () => {
    const box = $('status-box');
    if (!box) { return; }
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

  on('show-commits', 'click', async () => {
    const list = $('commits-list');
    if (!list) { return; }
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

  on('edit-desc', 'click', () => { const f = $('desc-form'); if (f) { f.hidden = !f.hidden; } });
  on('save-desc', 'click', async () => {
    const r = await api('/api/project_actions.php?action=description', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ slug, description: $('desc-input').value }),
    });
    if (r.ok) { window.location.reload(); } else { window.alert('✗ ' + r.error); }
  });

  on('export-project', 'click', async () => {
    const btn = $('export-project');
    btn.disabled = true;
    btn.textContent = '⏳ Экспорт...';
    const r = await api('/api/project_actions.php?action=export&slug=' + encodeURIComponent(slug), { method: 'POST' });
    btn.disabled = false;
    btn.textContent = '📦 Экспорт проекта (zip)';
    if (r.ok) { window.location.href = r.data.download_url; } else { window.alert('✗ ' + r.error); }
  });

  on('delete-local', 'click', async () => {
    const word = window.prompt('Удаление проекта ' + slug + ' с диска необратимо.\nВведите DELETE-LOCAL для подтверждения:');
    if (word !== 'DELETE-LOCAL') { return; }
    const r = await api('/api/projects_remove.php?slug=' + encodeURIComponent(slug), {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ confirm: 'DELETE-LOCAL' }),
    });
    if (r.ok) { window.location.href = '/'; } else { window.alert('✗ ' + r.error); }
  });

  // --- Файловый менеджер (read-only) ---
  const fmState = { path: '' };
  async function fmLoad(path) {
    if (!$('fm-box') || !$('fm-list') || !$('fm-crumbs')) { return; }
    fmState.path = path;
    const list = $('fm-list');
    const view = $('fm-view');
    $('fm-box').hidden = false;
    if ($('fm-back')) { $('fm-back').hidden = true; }
    if (view) { view.hidden = true; }
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
  on('fm-open', 'click', () => {
    const box = $('fm-box');
    if (!box) { return; }
    if (box.hidden) { fmLoad(''); } else { box.hidden = true; }
  });
  on('fm-crumbs', 'click', (ev) => {
    const c = ev.target.closest('.fm-crumb');
    if (c) { fmLoad(c.dataset.path); }
  });
  on('fm-back', 'click', () => {
    if ($('fm-view')) { $('fm-view').hidden = true; }
    if ($('fm-list')) { $('fm-list').hidden = false; }
    if ($('fm-back')) { $('fm-back').hidden = true; }
  });
  on('fm-list', 'click', async (ev) => {
    const row = ev.target.closest('.fm-row');
    if (!row) { return; }
    const path = fmState.path === '' ? row.dataset.name : fmState.path + '/' + row.dataset.name;
    if (row.dataset.dir === '1') { fmLoad(path); return; }
    const view = $('fm-view');
    if (!view) { return; }
    $('fm-list').hidden = true;
    if ($('fm-back')) { $('fm-back').hidden = false; }
    view.hidden = false;
    view.textContent = 'Загрузка…';
    const r = await api('/api/project_actions.php?action=file&slug=' + encodeURIComponent(slug) + '&path=' + encodeURIComponent(path));
    view.textContent = r.ok ? r.data.content : '✗ ' + r.error;
  });

  // --- Мета-данные ---
  on('edit-meta', 'click', async () => {
    const form = $('meta-form');
    if (!form) { return; }
    if (!form.hidden) { form.hidden = true; return; }
    const r = await api('/api/project_actions.php?action=meta&slug=' + encodeURIComponent(slug));
    if (!r.ok) { window.alert('✗ ' + r.error); return; }
    $('meta-version').value = r.data.version || '';
    $('meta-homepage').value = r.data.homepage || '';
    $('meta-license').value = r.data.license || '';
    $('meta-keywords').value = (r.data.keywords || []).join(', ');
    form.hidden = false;
  });
  on('save-meta', 'click', async () => {
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
  // --- Баннер обновления проекта (живое сравнение с сервером) ---
  const projRemote = document.body.dataset.remote || 'origin';
  async function projCheck(force) {
    const box = $('proj-update');
    const text = $('proj-update-text');
    if (!box || !text) { return; }
    box.hidden = false;
    text.textContent = force ? 'Проверка сервера (fetch)…' : 'Проверка…';
    const r = await api('/api/project_actions.php?action=check&slug=' + encodeURIComponent(slug) + (force ? '&force=1' : ''));
    if (!r.ok) { text.textContent = '✗ ' + r.error; return; }
    const d = r.data;
    const pull = $('proj-pull');
    const push = $('proj-push');
    if (d.state === 'no-remote') {
      text.innerHTML = '⚪ Remote не настроен. Добавьте: <code>bin/git-project-remote.sh ' + escapeHtml(slug) + ' --add &lt;домен&gt;</code>';
      if (pull) { pull.hidden = true; }
      if (push) { push.hidden = true; }
      return;
    }
    const L = d.local ? d.local.sha + ' · ' + d.local.date : '—';
    const S = d.server ? d.server.sha + ' · ' + d.server.date : '—';
    let state;
    if (d.state === 'synced') { state = '🟢 Синхронизировано'; }
    else if (d.state === 'behind') { state = '🟡 Сервер новее на ' + d.behind + ' коммит(ов) — доступно обновление'; }
    else if (d.state === 'ahead') { state = '🔴 Локальная новее на ' + d.ahead + ' коммит(ов) — можно опубликовать'; }
    else if (d.state === 'diverged') { state = '🔴 Истории разошлись: ↓' + d.behind + ' ↑' + d.ahead; }
    else { state = '⚪ Ветки на сервере нет'; }
    text.innerHTML = 'Локальная: <strong>' + escapeHtml(L) + '</strong> · Сервер (' + escapeHtml(d.remote) + '): <strong>' + escapeHtml(S) + '</strong><br>' + state
      + (d.fetched ? '' : ' <span class="mp-fresh">(кэш проверки ≤15 мин; кнопка — свежий fetch)</span>');
    if (pull) {
      pull.hidden = !(d.state === 'behind' || d.state === 'diverged');
      pull.textContent = '⬇ Обновить из сервера (' + d.remote + ')';
    }
    if (push) { push.hidden = !(d.state === 'ahead' || d.state === 'diverged'); }
  }
  on('proj-check', 'click', () => projCheck(true));
  on('proj-pull', 'click', async () => {
    const btn = $('proj-pull');
    btn.disabled = true;
    showLog($('proj-log'), 'Обновление из ' + projRemote + '…');
    const r = await api('/api/sync.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ slug, mode: 'pull', remote: projRemote }),
    });
    showLog($('proj-log'), (r.ok ? '✅ Обновлено\n' : '❌ ' + r.error + '\n') + (r.data?.log || ''));
    btn.disabled = false;
    projCheck(true);
  });
  on('proj-push', 'click', async () => {
    const btn = $('proj-push');
    btn.disabled = true;
    showLog($('proj-log'), 'Публикация на все remote…');
    const r = await api('/api/sync.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ slug, mode: 'push' }),
    });
    showLog($('proj-log'), (r.ok ? '✅ Опубликовано\n' : '❌ ' + r.error + '\n') + (r.data?.log || ''));
    btn.disabled = false;
    projCheck(true);
  });
  projCheck(false);
}

// --- Хосты и API-ключи (главная страница) ---
let hostCsrf = '';
async function hostsLoad() {
  const tbody = document.querySelector('#hosts-table tbody');
  if (!tbody) { return; }
  tbody.innerHTML = '<tr><td colspan="6" class="hosts-empty">Загрузка…</td></tr>';
  const r = await api('/api/hosts_manage.php?action=list');
  if (!r.ok) {
    tbody.innerHTML = '<tr><td colspan="6" class="hosts-empty">✗ ' + escapeHtml(r.error) + '</td></tr>';
    return;
  }
  hostCsrf = r.data.csrf || '';
  tbody.innerHTML = r.data.hosts.map((h) =>
    '<tr>'
    + '<td><strong>' + escapeHtml(h.id) + '</strong></td>'
    + '<td>' + escapeHtml(h.host) + '</td>'
    + '<td><span class="' + (h.status === 'ready' ? 'mp-cloned' : 'mp-arch') + '">' + escapeHtml(h.status) + '</span></td>'
    + '<td>' + escapeHtml(h.registry) + '</td>'
    + '<td>' + (h.configured ? '<span class="mp-cloned">✓ настроен</span>' : '<span class="mp-arch">✗ нет</span>') + '</td>'
    + '<td class="hosts-actions">'
    + '<button type="button" class="btn host-edit" data-id="' + escapeHtml(h.id) + '" data-domain="' + escapeHtml(h.host) + '" data-prefix="' + escapeHtml(h.prefix) + '">🔑 Ключ</button> '
    + '<button type="button" class="btn btn--danger host-del" data-id="' + escapeHtml(h.id) + '">✕</button>'
    + '</td></tr>'
  ).join('');
}

function hostFormOpen(prefill) {
  const form = $('host-form');
  form.hidden = false;
  $('hf-id').value = prefill?.id || '';
  $('hf-id').disabled = Boolean(prefill);
  $('hf-domain').value = prefill?.domain || '';
  $('hf-prefix').value = prefill?.prefix || '';
  $('hf-user').value = '';
  $('hf-token').value = '';
  $('hf-token').placeholder = prefill ? 'API-токен (пусто = не менять)' : 'API-токен';
  $('hf-msg').textContent = '';
  $('hf-domain').focus();
}

const hostsToggle = $('hosts-toggle');
if (hostsToggle) {
  hostsToggle.addEventListener('click', () => {
    const box = $('hosts-box');
    box.hidden = !box.hidden;
    if (!box.hidden) { hostsLoad(); }
  });
  $('host-add-open').addEventListener('click', () => hostFormOpen(null));
  $('hf-cancel').addEventListener('click', () => { $('host-form').hidden = true; });
  $('hosts-table').addEventListener('click', (ev) => {
    const edit = ev.target.closest('.host-edit');
    if (edit) {
      hostFormOpen({ id: edit.dataset.id, domain: edit.dataset.domain, prefix: edit.dataset.prefix });
      return;
    }
    const del = ev.target.closest('.host-del');
    if (del) {
      const id = del.dataset.id;
      const code = window.prompt('Удаление хоста ' + id + ' сотрёт его ключи из .env.\nВведите DELETE-' + id.toUpperCase() + ':');
      if (code !== 'DELETE-' + id.toUpperCase()) { return; }
      api('/api/hosts_manage.php?action=delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf: hostCsrf, host_id: id, confirm: code }),
      }).then((r) => {
        toast(r.ok ? r.data.message : r.error, r.ok);
        if (r.ok) { hostsLoad(); }
      });
    }
  });
  $('host-form').addEventListener('submit', (e) => {
    e.preventDefault();
    const msg = $('hf-msg');
    const btn = $('hf-save');
    btn.disabled = true;
    msg.textContent = 'Сохранение…';
    msg.className = 'mp-msg';
    api('/api/hosts_manage.php?action=add', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        csrf: hostCsrf,
        host_id: $('hf-id').value.trim(),
        domain: $('hf-domain').value.trim(),
        prefix: $('hf-prefix').value.trim().toUpperCase(),
        registry: $('hf-registry').value,
        scope: $('hf-scope').value,
        user: $('hf-user').value.trim(),
        token: $('hf-token').value,
      }),
    }).then((r) => {
      btn.disabled = false;
      if (r.ok) {
        msg.textContent = '✅ ' + r.data.message;
        msg.className = 'mp-msg mp-msg--ok';
        $('host-form').hidden = true;
        hostsLoad();
        toast(r.data.message);
      } else {
        msg.textContent = '✗ ' + r.error;
        msg.className = 'mp-msg mp-msg--err';
        toast(r.error, false);
      }
    });
  });
}