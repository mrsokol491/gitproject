// project-page.js — страница проекта и карточка Hub в сайдбаре.
// Карточка Hub: текстом только текущая версия; кнопки stable/edge рисуются из status
// и дополнительно скрываются в JS, если их метка равна текущей или метке другой кнопки.
// Сравнение с сервером: один живой блок внутри панели информации.
// Взаимоисключение вывода: статус <-> лог операций, коммиты <-> файловый менеджер.
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

function hide(id) {
  const el = $(id);
  if (el) { el.hidden = true; }
}

// --- Карточка Hub в сайдбаре ---------------------------------------------------
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
  // Edge имеет смысл только как обновление: не совпадает с текущей и не совпадает со stable.
  const edgeIsCurrent = Boolean(d.edge) && d.edge.label === d.current_label;
  const edgeEqualsStable = Boolean(d.stable && d.edge) && (
    d.edge_equals_stable === true || d.stable.sha === d.edge.sha || d.stable.label === d.edge.label
  );
  // Уже на стабильной: current_label === stable или is_stable_descendant
  const isAlreadyStable = d.current_label === stable || d.is_stable_descendant === true;
  
  text.innerHTML = 'Текущая: <strong>' + escapeHtml(d.current_label) + '</strong>';
  const stableBtn = $('hub-stable');
  if (stableBtn) {
    stableBtn.hidden = !d.stable || isAlreadyStable;
    stableBtn.textContent = '⬇ Стабильная ' + stable;
    stableBtn.title = isAlreadyStable
      ? 'Файлы уже соответствуют стабильной версии ' + stable
      : (d.is_downgrade
        ? 'Файлы будут откатаны к стабильной ' + stable + ' (локально впереди ' + (d.ahead_of_stable || 0) + ' коммитов, они сохранятся в backup)'
        : (edgeEqualsStable
          ? 'Стабильная и edge совпадают (' + stable + ') — обновлений вне релиза нет'
          : 'Обновить хаб до стабильной версии ' + stable));
  }
  const edgeBtn = $('hub-edge');
  if (edgeBtn) {
    edgeBtn.hidden = !d.edge || edgeIsCurrent || edgeEqualsStable;
    edgeBtn.textContent = '⚠ Edge ' + edge + ' (риск)';
    edgeBtn.title = 'Нестабильная сборка ' + edge + ' — обновление на свой страх и риск';
  }
  const checkBtn = $('hub-check');
  if (checkBtn) { checkBtn.title = 'Проверить обновления хаба на GitVerse (свежий fetch)'; }
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
    rollbackBtn.className = 'btn ws-hub-btn';
    rollbackBtn.id = 'hub-rollback';
    rollbackBtn.textContent = '↩ Откат';
    rollbackBtn.title = 'Откатить хаб к одной из сохранённых backup-веток (текущее состояние сохранится)';
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
}

// --- Кнопки страницы проекта ---------------------------------------------------
if (slug) {
  document.querySelectorAll('[data-op]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const op = btn.dataset.op;
      btn.disabled = true;
      hide('status-box');
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
    hide('git-log');
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
    hide('fm-box');
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
    const viewEl = $('fm-view');
    $('fm-box').hidden = false;
    if ($('fm-back')) { $('fm-back').hidden = true; }
    if (viewEl) { viewEl.hidden = true; }
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
    if (box.hidden) { hide('commits-list'); fmLoad(''); } else { box.hidden = true; }
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
    const viewEl = $('fm-view');
    if (!viewEl) { return; }
    $('fm-list').hidden = true;
    if ($('fm-back')) { $('fm-back').hidden = false; }
    viewEl.hidden = false;
    viewEl.textContent = 'Загрузка…';
    const r = await api('/api/project_actions.php?action=file&slug=' + encodeURIComponent(slug) + '&path=' + encodeURIComponent(path));
    viewEl.textContent = r.ok ? r.data.content : '✗ ' + r.error;
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

  // --- Единый живой блок сравнения с сервером ---
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
      text.innerHTML = '<div class="sync-status"><span class="sync-icon sync-none">⚪ Remote не настроен</span></div>'
        + '<p class="mp-stats-note">Добавьте: <code>bin/git-project-remote.sh ' + escapeHtml(slug) + ' --add &lt;домен&gt;</code></p>';
      if (pull) { pull.hidden = true; }
      if (push) { push.hidden = true; }
      return;
    }
    const L = d.local ? d.local.sha + ' · ' + d.local.date : '—';
    const S = d.server ? d.server.sha + ' · ' + d.server.date : '—';
    let cls = 'none';
    let label = '⚪ Ветки на сервере нет';
    if (d.state === 'synced') { cls = 'ok'; label = '✅ Синхронизировано'; }
    else if (d.state === 'behind') { cls = 'warn'; label = '⚠️ Сервер новее на ' + d.behind + ' коммит(ов) — доступно обновление'; }
    else if (d.state === 'ahead') { cls = 'warn'; label = '⚠️ Локальная новее на ' + d.ahead + ' коммит(ов) — можно опубликовать'; }
    else if (d.state === 'diverged') { cls = 'warn'; label = '⚠️ Истории разошлись: ↓' + d.behind + ' ↑' + d.ahead; }
    text.innerHTML = '<div class="version-row">'
      + '<div class="version-item"><span class="info-label">Локальная' + (d.branch ? ' (' + escapeHtml(d.branch) + ')' : '') + '</span>'
      + '<span class="info-value version-tag">' + escapeHtml(L) + '</span></div>'
      + '<div class="version-item"><span class="info-label">Git-сервер' + (d.remote ? ' (' + escapeHtml(d.remote) + ')' : '') + '</span>'
      + '<span class="info-value version-tag">' + escapeHtml(S) + '</span></div>'
      + '</div>'
      + '<div class="sync-status"><span class="sync-icon sync-' + cls + '">' + label + '</span></div>'
      + (d.fetched ? '' : '<p class="mp-stats-note">Кэш проверки ≤15 мин; кнопка «Проверить сервер» делает свежий fetch.</p>');
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