// workspace.js v3 — виды рабочего поля: таблица проектов, хосты, маркетплейс, добавление git.
// git-add: whitelist доменов читается динамически из hosts.json (hosts_manage list).
import { MARKET_ITEMS, MARKET_HOSTS, MARKET_CATEGORIES } from './marketplace-data.js';

const $ = (id) => document.getElementById(id);
const view = document.body.dataset.view || 'home';
let toastT = 0;

function api(url, options = {}) {
  return fetch(url, options).then((r) => r.json());
}

function toast(text, ok = true) {
  const el = $('mp-toast');
  if (!el) { return; }
  el.textContent = text;
  el.className = 'mp-toast ' + (ok ? 'mp-toast--ok' : 'mp-toast--err');
  el.hidden = false;
  clearTimeout(toastT);
  toastT = setTimeout(() => { el.hidden = true; }, 4200);
}

function escapeHtml(str) {
  return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function monogramHtml(name) {
  const clean = String(name || '').replace(/[^A-Za-z0-9]+/g, '');
  const ini = (clean !== '' ? clean : 'MP').slice(0, 2).toUpperCase();
  let h = 0;
  for (let i = 0; i < name.length; i++) { h = (h * 31 + name.charCodeAt(i)) >>> 0; }
  return { ini, hue: h % 360 };
}

// --- Навигация сайдбара и счётчик каталога ------------------------------------
const navAdd = $('nav-git-add');
if (navAdd) { navAdd.addEventListener('click', () => { window.location.href = '/?view=git-add'; }); }
const emptyAdd = $('empty-go-add');
if (emptyAdd) { emptyAdd.addEventListener('click', (e) => { e.preventDefault(); window.location.href = '/?view=git-add'; }); }
const marketCount = document.querySelector('a[href="/?view=market"] .ws-count');
if (marketCount) { marketCount.textContent = String(MARKET_ITEMS.length); }

// === ВИД: HOME — таблица проектов =============================================
if (view === 'home') {
  const table = document.querySelector('.ws-table');
  const pills = $('ws-pills');
  const search = $('ws-search');
  let stateFilter = '';
  const collapsedSet = new Set((() => {
    try {
      const raw = JSON.parse(localStorage.getItem('ws-groups-collapsed') || '[]');
      return Array.isArray(raw) ? raw : [];
    } catch { return []; }
  })());

  const saveCollapsed = () => {
    try { localStorage.setItem('ws-groups-collapsed', JSON.stringify([...collapsedSet])); } catch { /* приватный режим */ }
  };

  let emptyNote = null;
  if (document.querySelector('tbody.ws-tbody')) {
    emptyNote = document.createElement('div');
    emptyNote.className = 'ws-empty ws-empty-search';
    emptyNote.textContent = 'Ничего не найдено под текущий фильтр и поиск.';
    emptyNote.hidden = true;
    document.querySelector('.ws-table-wrap').appendChild(emptyNote);
  }

  const applyFilters = () => {
    if (!table) { return; }
    const q = (search ? search.value : '').trim().toLowerCase();
    let visibleTotal = 0;
    document.querySelectorAll('tbody.ws-tbody').forEach((tb) => {
      const group = tb.dataset.group;
      const collapsed = collapsedSet.has(group);
      let matchInGroup = 0;
      tb.querySelectorAll('tr.ws-row').forEach((row) => {
        const okState = stateFilter === '' || row.dataset.state === stateFilter;
        const hay = ((row.dataset.name || '') + ' ' + (row.dataset.desc || '')).toLowerCase();
        const match = okState && (q === '' || hay.includes(q));
        if (match) { matchInGroup++; visibleTotal++; }
        row.hidden = !match || collapsed;
      });
      const head = tb.querySelector('tr.ws-group');
      if (head) { head.hidden = matchInGroup === 0; }
      const arrow = head ? head.querySelector('.ws-arrow') : null;
      if (arrow) { arrow.textContent = collapsed ? '▸' : '▾'; }
    });
    if (emptyNote) { emptyNote.hidden = visibleTotal !== 0; }
  };

  const syncUrl = () => {
    const url = new URL(location.href);
    if (stateFilter !== '') { url.searchParams.set('state', stateFilter); } else { url.searchParams.delete('state'); }
    const q = (search ? search.value : '').trim();
    if (q !== '') { url.searchParams.set('q', q); } else { url.searchParams.delete('q'); }
    history.replaceState(null, '', url);
  };

  const refreshRow = async (row) => {
    const slug = row.dataset.slug;
    if (!slug) { return; }
    const r = await api('/api/project_actions.php?action=check&slug=' + encodeURIComponent(slug) + '&force=1');
    if (!r.ok) { return; }
    const map = {
      synced: ['synced', 'Синхронизирован'],
      behind: ['behind', 'Есть обновления'],
      diverged: ['behind', 'Расхождение историй'],
      ahead: ['behind', 'Локальная впереди'],
    };
    const [key, label] = map[r.data.state] || ['none', 'Нет данных'];
    row.dataset.state = key;
    const dot = row.querySelector('.ws-dot');
    if (dot) { dot.className = 'ws-dot ws-dot--' + key; }
    const lbl = row.querySelector('.ws-state');
    if (lbl) { lbl.textContent = label; }
    applyFilters();
  };

  const runAction = async (act, row) => {
    const slug = row.dataset.slug;
    const kind = act.dataset.act;
    if (kind === 'preview') {
      if (row.dataset.preview) { window.open(row.dataset.preview, '_blank', 'noopener'); }
      return;
    }
    act.disabled = true;
    try {
      if (kind === 'pull' || kind === 'push') {
        const body = kind === 'pull'
          ? { slug, mode: 'pull', remote: row.dataset.remote || 'origin' }
          : { slug, mode: 'push' };
        const r = await api('/api/sync.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
        });
        toast(r.ok ? (kind === 'pull' ? 'Pull завершён: ' + slug : 'Push завершён: ' + slug) : (r.error || 'Ошибка синхронизации'), r.ok);
        if (r.ok) { await refreshRow(row); }
      } else if (kind === 'export') {
        const r = await api('/api/project_actions.php?action=export&slug=' + encodeURIComponent(slug), { method: 'POST' });
        if (r.ok) { window.location.href = r.data.download_url; } else { toast(r.error || 'Экспорт не удался', false); }
      } else if (kind === 'delete') {
        const word = window.prompt('Удаление ' + slug + ' с диска необратимо.\nВведите DELETE-LOCAL:');
        if (word !== 'DELETE-LOCAL') { return; }
        const r = await api('/api/projects_remove.php?slug=' + encodeURIComponent(slug), {
          method: 'DELETE',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ confirm: 'DELETE-LOCAL' }),
        });
        if (r.ok) { toast('Проект удалён'); location.reload(); } else { toast(r.error || 'Удаление не удалось', false); }
      }
    } catch (e) {
      toast('Сеть недоступна: ' + e.message, false);
    } finally {
      act.disabled = false;
    }
  };

  if (pills) {
    pills.addEventListener('click', (ev) => {
      const b = ev.target.closest('.ws-pill');
      if (!b) { return; }
      stateFilter = b.dataset.state || '';
      pills.querySelectorAll('.ws-pill').forEach((p) => p.classList.toggle('ws-pill--on', p === b));
      applyFilters();
      syncUrl();
    });
  }
  if (search) {
    let deb = 0;
    search.addEventListener('input', () => {
      clearTimeout(deb);
      deb = setTimeout(() => { applyFilters(); syncUrl(); }, 150);
    });
  }
  if (table) {
    table.addEventListener('click', (ev) => {
      const act = ev.target.closest('.ws-act');
      if (act) { if (!act.disabled) { runAction(act, act.closest('tr.ws-row')); } return; }
      const gbtn = ev.target.closest('.ws-group-btn');
      if (gbtn) {
        const g = gbtn.dataset.group;
        if (collapsedSet.has(g)) { collapsedSet.delete(g); } else { collapsedSet.add(g); }
        saveCollapsed();
        applyFilters();
        return;
      }
      const row = ev.target.closest('tr.ws-row');
      if (row && !ev.target.closest('a, button, input')) { location.href = row.dataset.page; }
    });
  }
  const urlNow = new URL(location.href);
  const initState = urlNow.searchParams.get('state') || '';
  if (['synced', 'behind', 'none'].includes(initState)) {
    stateFilter = initState;
    if (pills) { pills.querySelectorAll('.ws-pill').forEach((p) => p.classList.toggle('ws-pill--on', (p.dataset.state || '') === stateFilter)); }
  }
  if (search && urlNow.searchParams.get('q')) { search.value = urlNow.searchParams.get('q'); }
  applyFilters();
}

// === ВИД: HOSTS ================================================================
if (view === 'hosts') {
  let hostCsrf = '';
  const hostsLoad = async () => {
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
  };

  const hostFormOpen = (prefill) => {
    const form = $('host-form');
    if (!form) { return; }
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
  };

  const addOpen = $('host-add-open');
  if (addOpen) { addOpen.addEventListener('click', () => hostFormOpen(null)); }
  const cancel = $('hf-cancel');
  if (cancel) { cancel.addEventListener('click', () => { $('host-form').hidden = true; }); }
  const htable = $('hosts-table');
  if (htable) {
    htable.addEventListener('click', (ev) => {
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
  }
  const hform = $('host-form');
  if (hform) {
    hform.addEventListener('submit', (e) => {
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
          hform.hidden = true;
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
  hostsLoad();
}

// === ВИД: MARKET ===============================================================
if (view === 'market') {
  const grid = $('mp-grid');
  const hero = $('mp-hero');
  const readme = $('mp-readme');
  const filters = $('mp-filters');
  let current = null;

  const catSel = $('mp-cat');
  const hostSel = $('mp-host');
  if (catSel) {
    Object.entries(MARKET_CATEGORIES).forEach(([id, label]) => {
      const o = document.createElement('option');
      o.value = id;
      o.textContent = label;
      catSel.appendChild(o);
    });
  }
  if (hostSel) {
    Object.entries(MARKET_HOSTS).forEach(([id, h]) => {
      const o = document.createElement('option');
      o.value = id;
      o.textContent = h.name;
      hostSel.appendChild(o);
    });
  }

  const renderGrid = () => {
    if (!grid) { return; }
    const q = ($('mp-search')?.value || '').trim().toLowerCase();
    const cat = catSel?.value || '';
    const host = hostSel?.value || '';
    const sort = $('mp-sort')?.value || 'name';
    let items = MARKET_ITEMS.filter((it) => {
      if (cat && it.category !== cat) { return false; }
      if (host && it.host !== host) { return false; }
      if (q) {
        const hay = (it.owner + ' ' + it.slug + ' ' + (it.note || '') + ' ' + (it.tags || []).join(' ')).toLowerCase();
        if (!hay.includes(q)) { return false; }
      }
      return true;
    });
    if (sort === 'name') { items = items.slice().sort((a, b) => a.slug.localeCompare(b.slug)); }
    if (sort === 'category') { items = items.slice().sort((a, b) => a.category.localeCompare(b.category) || a.slug.localeCompare(b.slug)); }
    const empty = $('mp-empty');
    if (empty) { empty.hidden = items.length !== 0; }
    grid.innerHTML = items.map((it) => {
      const m = monogramHtml(it.slug);
      const h = MARKET_HOSTS[it.host] || { name: it.host };
      return '<button type="button" class="mp-card" data-idx="' + MARKET_ITEMS.indexOf(it) + '">'
        + '<div class="mp-card-head">'
        + '<span class="mp-avatar" style="display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;background:hsl(' + m.hue + ',45%,32%)">' + escapeHtml(m.ini) + '</span>'
        + '<span class="mp-card-names"><span class="mp-card-name">' + escapeHtml(it.slug) + '</span><span class="mp-card-owner">' + escapeHtml(it.owner) + '</span></span>'
        + '<span class="mp-host mp-host--' + escapeHtml(it.host) + '">' + escapeHtml(h.name) + '</span>'
        + '</div>'
        + '<p class="mp-card-desc">' + escapeHtml(it.note || '') + '</p>'
        + '<div class="mp-card-tags"><span class="mp-cat">' + escapeHtml(MARKET_CATEGORIES[it.category] || it.category) + '</span>'
        + (it.tags || []).map((t) => '<span class="mp-lang">#' + escapeHtml(t) + '</span>').join('')
        + '</div>'
        + '<div class="mp-card-foot"><span class="mp-fresh">README и статистика — по клику</span></div>'
        + '</button>';
    }).join('');
  };

  const openDetail = async (it) => {
    current = it;
    if (grid) { grid.hidden = true; }
    if (filters) { filters.hidden = true; }
    if ($('mp-empty')) { $('mp-empty').hidden = true; }
    hero.hidden = false;
    readme.hidden = false;
    readme.innerHTML = '<p class="mp-empty-hint">Загрузка README…</p>';
    const m = monogramHtml(it.slug);
    const mono = $('mp-monogram');
    mono.textContent = m.ini;
    mono.style.background = 'hsl(' + m.hue + ',45%,32%)';
    mono.style.color = '#fff';
    mono.style.display = 'flex';
    mono.style.alignItems = 'center';
    mono.style.justifyContent = 'center';
    mono.style.fontWeight = '700';
    const h = MARKET_HOSTS[it.host] || { name: it.host, repo: '#' };
    $('mp-badges').innerHTML = '<span class="mp-host mp-host--' + escapeHtml(it.host) + '">' + escapeHtml(h.name) + '</span>'
      + '<span class="mp-cat">' + escapeHtml(MARKET_CATEGORIES[it.category] || it.category) + '</span>'
      + (it.tags || []).map((t) => '<span class="mp-lang">#' + escapeHtml(t) + '</span>').join('');
    $('mp-desc').textContent = it.note || '';
    $('mp-note').hidden = true;
    $('mp-stats').innerHTML = '<span class="mp-fresh">Загрузка статистики…</span>';
    $('mp-open').href = h.repo.replace('%o', encodeURIComponent(it.owner)).replace('%s', encodeURIComponent(it.slug));
    $('mp-clone').dataset.owner = it.owner;
    $('mp-clone').dataset.slug = it.slug;
    $('mp-clone').dataset.domain = h.domain || it.host;
    const r = await api('/api/market_readme.php?host=' + encodeURIComponent(it.host) + '&owner=' + encodeURIComponent(it.owner) + '&slug=' + encodeURIComponent(it.slug));
    if (!r.ok) {
      readme.innerHTML = '<p class="mp-warn">✗ ' + escapeHtml(r.error) + '</p>';
      $('mp-stats').innerHTML = '';
      return;
    }
    const d = r.data;
    $('mp-stats').innerHTML = (d.stars !== null ? '<span class="mp-stat">⭐ ' + d.stars + '</span>' : '')
      + (d.forks !== null ? '<span class="mp-stat">⑂ ' + d.forks + '</span>' : '')
      + (d.language ? '<span class="mp-lang">' + escapeHtml(d.language) + '</span>' : '')
      + (d.license ? '<span class="mp-lang">' + escapeHtml(d.license) + '</span>' : '');
    const warn = $('mp-warn');
    if (d.warning) { warn.hidden = false; warn.textContent = '⚠ ' + d.warning; } else { warn.hidden = true; }
    readme.innerHTML = d.html;
  };

  const closeDetail = () => {
    current = null;
    hero.hidden = true;
    readme.hidden = true;
    if (grid) { grid.hidden = false; }
    if (filters) { filters.hidden = false; }
    renderGrid();
  };

  if (grid) {
    grid.addEventListener('click', (ev) => {
      const card = ev.target.closest('.mp-card');
      if (card) { openDetail(MARKET_ITEMS[Number(card.dataset.idx)]); }
    });
  }
  const back = $('mp-back');
  if (back) { back.addEventListener('click', closeDetail); }
  if (filters) {
    filters.addEventListener('input', renderGrid);
    filters.addEventListener('change', renderGrid);
  }
  const cloneBtn = $('mp-clone');
  if (cloneBtn) {
    cloneBtn.addEventListener('click', async () => {
      const log = $('mp-clone-log');
      log.hidden = false;
      log.textContent = 'Клонирование ' + cloneBtn.dataset.owner + '/' + cloneBtn.dataset.slug + '…';
      cloneBtn.disabled = true;
      const r = await api('/api/clone.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          host: cloneBtn.dataset.domain,
          owner: cloneBtn.dataset.owner,
          slug: cloneBtn.dataset.slug,
          local_name: cloneBtn.dataset.slug,
        }),
      }).catch((e) => ({ ok: false, error: e.message }));
      cloneBtn.disabled = false;
      if (r.ok) {
        log.textContent = '✅ Клонировано в projects/' + cloneBtn.dataset.slug + '\n' + (r.data?.log || '');
        toast('Проект клонирован: ' + cloneBtn.dataset.slug);
      } else {
        log.textContent = '✗ ' + (r.error || 'Ошибка') + '\n\nВручную:\ngit clone ' + (MARKET_HOSTS[current?.host]?.repo || '').replace('%o', current?.owner || '').replace('%s', current?.slug || '') + '.git ~/gitproject/projects/' + (current?.slug || '');
        toast(r.error || 'Не удалось клонировать', false);
      }
    });
  }
  renderGrid();
}

// === ВИД: GIT-ADD ==============================================================
if (view === 'git-add') {
  // Домены берём из hosts.json через API: новый хост появляется здесь без правок кода.
  const hostDomains = {};
  const CLONE_TEMPLATES = { gitflic: 'https://gitflic.ru/project/%o/%s.git' };
  const cloneTemplate = (id, domain, owner, slug) =>
    (CLONE_TEMPLATES[id] || ('https://' + domain + '/%o/%s.git')).replace('%o', owner).replace('%s', slug);

  const loadHostDomains = async () => {
    const r = await api('/api/hosts_manage.php?action=list');
    if (r.ok) {
      r.data.hosts.forEach((h) => { hostDomains[h.host] = h.id; });
    }
  };

  const parseRepoUrl = (u) => {
    try {
      const url = new URL(u);
      if (url.protocol !== 'https:') { return null; }
      const domain = url.hostname.toLowerCase();
      const id = hostDomains[domain];
      if (!id) { return null; }
      let parts = url.pathname.replace(/\.git$/, '').split('/').filter(Boolean);
      if (id === 'gitflic' && parts[0] === 'project') { parts = parts.slice(1); }
      if (parts.length < 2) { return null; }
      const owner = parts[parts.length - 2];
      const slug = parts[parts.length - 1];
      if (!/^[A-Za-z0-9][A-Za-z0-9.-]{0,38}$/.test(owner)) { return null; }
      if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/.test(slug)) { return null; }
      return { domain, owner, slug, id };
    } catch { return null; }
  };

  const urlInput = $('ga-url');
  const nameInput = $('ga-name');
  const preview = $('ga-preview');
  const updatePreview = () => {
    const p = parseRepoUrl(urlInput.value.trim());
    preview.textContent = nameInput.value.trim() || (p ? p.slug : '…');
  };
  if (urlInput) { urlInput.addEventListener('input', updatePreview); }
  if (nameInput) { nameInput.addEventListener('input', updatePreview); }
  const form = $('git-add-form');
  if (form) {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const log = $('ga-log');
      const btn = $('ga-submit');
      const p = parseRepoUrl(urlInput.value.trim());
      log.hidden = false;
      if (!p) {
        log.textContent = '✗ Не распознан URL.';
        return;
      }
      const local = nameInput.value.trim() || p.slug;
      btn.disabled = true;
      log.textContent = 'Клонирование ' + p.owner + '/' + p.slug + ' → projects/' + local + '/ …';
      const r = await api('/api/clone.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ host: p.domain, owner: p.owner, slug: p.slug, local_name: local }),
      }).catch((err) => ({ ok: false, error: err.message }));
      btn.disabled = false;
      if (r.ok) {
        log.textContent = '✅ Готово: projects/' + local + '/\n' + (r.data?.log || '');
        toast('Проект клонирован: ' + local);
        setTimeout(() => { window.location.href = '/?view=home'; }, 1200);
      } else {
        log.textContent = '✗ ' + (r.error || 'Ошибка клонирования') + '\n' + (r.data?.log || '')
          + '\n\nВручную:\ngit clone ' + cloneTemplate(p.id, p.domain, p.owner, p.slug) + ' ~/gitproject/projects/' + local;
        toast(r.error || 'Не удалось клонировать', false);
      }
    });
  }
  await loadHostDomains();
  updatePreview();
}
// === Кнопка "Создать проект" ===
const btnCreate = document.getElementById('nav-create-project');
if (btnCreate) {
    btnCreate.addEventListener('click', () => {
        const slug = prompt('Название проекта (латиница, цифры, - и _, не начинать с -):');
        if (!slug) return;
        
        fetch('/api/project_create.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({slug: slug.toLowerCase().trim()})
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                alert('✅ Проект создан: projects/' + data.data.slug);
                location.reload();
            } else {
                alert('✗ ' + (data.error || 'Ошибка'));
            }
        })
        .catch(err => alert('✗ Сеть: ' + err.message));
    });
}
