// workspace.js — интерактив главной таблицы v0.2: pills-фильтры, поиск,
// сворачивание групп, действия строк, sync состояния в URL, авто-открытие хостов.
const $ = (id) => document.getElementById(id);
const table = document.querySelector('.ws-table');
const pills = $('ws-pills');
const search = $('ws-search');

let stateFilter = '';
let toastT = 0;
const collapsedSet = new Set((() => {
  try {
    const raw = JSON.parse(localStorage.getItem('ws-groups-collapsed') || '[]');
    return Array.isArray(raw) ? raw : [];
  } catch {
    return [];
  }
})());

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

function saveCollapsed() {
  try {
    localStorage.setItem('ws-groups-collapsed', JSON.stringify([...collapsedSet]));
  } catch {
    // приватный режим — молча пропускаем
  }
}

let emptyNote = null;
if (document.querySelector('tbody.ws-tbody')) {
  emptyNote = document.createElement('div');
  emptyNote.className = 'ws-empty ws-empty-search';
  emptyNote.textContent = 'Ничего не найдено под текущий фильтр и поиск.';
  emptyNote.hidden = true;
  document.querySelector('.ws-table-wrap').appendChild(emptyNote);
}

function applyFilters() {
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
      const okQ = q === '' || hay.includes(q);
      const match = okState && okQ;
      if (match) { matchInGroup++; visibleTotal++; }
      row.hidden = !match || collapsed;
    });
    const head = tb.querySelector('tr.ws-group');
    if (head) { head.hidden = matchInGroup === 0; }
    const arrow = head ? head.querySelector('.ws-arrow') : null;
    if (arrow) { arrow.textContent = collapsed ? '▸' : '▾'; }
  });
  if (emptyNote) { emptyNote.hidden = visibleTotal !== 0; }
}

function syncUrl() {
  const url = new URL(location.href);
  if (stateFilter !== '') { url.searchParams.set('state', stateFilter); } else { url.searchParams.delete('state'); }
  const q = (search ? search.value : '').trim();
  if (q !== '') { url.searchParams.set('q', q); } else { url.searchParams.delete('q'); }
  history.replaceState(null, '', url);
}

function toggleGroup(group) {
  if (collapsedSet.has(group)) { collapsedSet.delete(group); } else { collapsedSet.add(group); }
  saveCollapsed();
  applyFilters();
}

async function refreshRow(row) {
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
}

async function runAction(act, row) {
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
}

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
    if (act) {
      if (!act.disabled) { runAction(act, act.closest('tr.ws-row')); }
      return;
    }
    const gbtn = ev.target.closest('.ws-group-btn');
    if (gbtn) { toggleGroup(gbtn.dataset.group); return; }
    const row = ev.target.closest('tr.ws-row');
    if (row && !ev.target.closest('a, button, input')) { location.href = row.dataset.page; }
  });
}

// --- Инициализация из URL + авто-открытие хостов -------------------------------
const urlNow = new URL(location.href);
const initState = urlNow.searchParams.get('state') || '';
if (['synced', 'behind', 'none'].includes(initState)) {
  stateFilter = initState;
  if (pills) {
    pills.querySelectorAll('.ws-pill').forEach((p) => p.classList.toggle('ws-pill--on', (p.dataset.state || '') === stateFilter));
  }
}
if (search && urlNow.searchParams.get('q')) {
  search.value = urlNow.searchParams.get('q');
}
if (urlNow.searchParams.get('hosts') === '1') {
  const t = $('hosts-toggle');
  if (t) { t.click(); }
  urlNow.searchParams.delete('hosts');
  history.replaceState(null, '', urlNow);
}
applyFilters();