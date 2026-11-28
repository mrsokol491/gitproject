// remote-badges.js — панель «Хосты проекта»: выбор целей push (мульти) и источника pull (один).
// ES-модуль без window-глобалов. Монтируется перед #proj-update / строкой сравнения / в начало контента.
// Slug: URL /hub/project/<slug>, body.dataset.slug или ?slug=.
// Дедупликация: remote с одинаковым URL (origin и именованный хост) — один чип с меткой origin.

const TTL_MS = 15 * 60 * 1000;
const MOUNT_TIMEOUT_MS = 20000;
const memory = new Map();

function currentSlug() {
    const body = document.body;
    if (body && body.dataset && body.dataset.slug) { return body.dataset.slug; }
    const m = location.pathname.match(/^\/hub\/project\/([^/]+)\/?$/);
    if (m) { return decodeURIComponent(m[1]); }
    const q = new URLSearchParams(location.search).get('slug');
    if (q) { return q; }
    const host = document.getElementById('remote-badges');
    if (host && host.dataset.slug) { return host.dataset.slug; }
    return '';
}

function leafByText(text) {
    const nodes = document.querySelectorAll('.ws-content label, .ws-content div, .ws-content span, .ws-content h2, .ws-content h3, .ws-content p');
    for (const n of nodes) {
        if (n.children.length === 0 && n.textContent && n.textContent.trim().indexOf(text) === 0) { return n; }
    }
    return null;
}

function ensureContainer() {
    const existing = document.getElementById('remote-badges');
    if (existing) { return existing; }
    let anchor = document.getElementById('proj-update');
    if (!anchor) {
        const label = leafByText('Сравнение с сервером');
        if (label) { anchor = label.closest('section, .mp-panel, .panel') || label.parentNode || label; }
    }
    const main = document.querySelector('.ws-content') || document.body;
    const host = document.createElement('div');
    host.className = 'remote-badges';
    host.id = 'remote-badges';
    if (anchor && anchor.parentNode) { anchor.parentNode.insertBefore(host, anchor); }
    else { main.insertBefore(host, main.firstChild); }
    return host;
}

function el(tag, cls, text) {
    const n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined) { n.textContent = text; }
    return n;
}

function normUrl(u) {
    if (!u) { return ''; }
    let s = String(u).trim().toLowerCase();
    s = s.replace(/^git\+/, '');
    s = s.replace(/^ssh:\/\/git@/, 'https://');
    s = s.replace(/^git@([^:]+):/, 'https://$1/');
    s = s.replace(/\.git$/, '').replace(/\/+$/, '');
    return s;
}

function dedupe(items) {
    const map = new Map();
    for (const it of items) {
        const key = normUrl(it.url) || ('remote:' + it.remote);
        if (!map.has(key)) {
            map.set(key, Object.assign({}, it, { aliases: [it.remote] }));
            continue;
        }
        const g = map.get(key);
        g.aliases.push(it.remote);
        if (g.remote === 'origin' && it.remote !== 'origin') {
            const exists = g.exists || it.exists;
            Object.assign(g, it, { aliases: g.aliases, exists: exists });
        } else {
            g.exists = g.exists || it.exists;
        }
    }
    return Array.from(map.values());
}

function humanizeComparisonLabel(items) {
    const label = leafByText('Git-сервер');
    if (!label) { return; }
    const origin = items.find((i) => i.remote === 'origin');
    if (!origin) { return; }
    const suffix = origin.domain ? ' (' + origin.domain + ')' : '';
    label.textContent = 'Git-сервер: ' + origin.name + suffix;
}

async function fetchStatuses(slug, force) {
    const cached = memory.get(slug);
    if (!force && cached && Date.now() - cached.at < TTL_MS) { return cached.items; }
    const url = '/api/project_remotes_status.php?slug=' + encodeURIComponent(slug) + (force ? '&refresh=1' : '');
    const resp = await fetch(url);
    const payload = await resp.json();
    if (!payload.ok || !Array.isArray(payload.data)) { throw new Error(payload.error || 'статусы недоступны'); }
    memory.set(slug, { at: Date.now(), items: payload.data });
    return payload.data;
}

function buildPanel(slug, groups, state) {
    const panel = el('div', 'rp-panel');

    const head = el('div', 'rp-head');
    head.appendChild(el('span', 'rp-title', 'Хосты проекта'));
    const legend = el('span', 'rp-legend');
    legend.appendChild(el('span', 'rp-dot ok', ''));
    legend.appendChild(el('span', 'rp-legend-text', 'репозиторий есть'));
    legend.appendChild(el('span', 'rp-dot no', ''));
    legend.appendChild(el('span', 'rp-legend-text', 'не создан'));
    head.appendChild(legend);
    const refresh = el('button', 'rp-refresh', '⟳ Обновить статусы');
    refresh.type = 'button';
    head.appendChild(refresh);
    panel.appendChild(head);

    const pushGroup = el('div', 'rp-group');
    pushGroup.appendChild(el('div', 'rp-group-label', 'Куда отправить обновление (push, можно несколько)'));
    const pushChips = el('div', 'rp-chips');
    pushGroup.appendChild(pushChips);
    const pushFoot = el('div', 'rp-foot');
    const pushCount = el('span', 'rp-count', 'Выбрано: 0');
    const pushAll = el('button', 'rp-btn ghost', 'Все существующие');
    const pushNone = el('button', 'rp-btn ghost', 'Снять выбор');
    const pushGo = el('button', 'rp-btn push', '⬆ Отправить выбранные');
    pushAll.type = pushNone.type = pushGo.type = 'button';
    pushFoot.appendChild(pushCount);
    pushFoot.appendChild(pushAll);
    pushFoot.appendChild(pushNone);
    pushFoot.appendChild(pushGo);
    pushGroup.appendChild(pushFoot);
    panel.appendChild(pushGroup);

    const pullGroup = el('div', 'rp-group');
    pullGroup.appendChild(el('div', 'rp-group-label', 'Откуда получить обновление (pull, один источник)'));
    const pullChips = el('div', 'rp-chips');
    pullGroup.appendChild(pullChips);
    const pullFoot = el('div', 'rp-foot');
    const pullGo = el('button', 'rp-btn pull', '⬇ Получить из выбранного');
    pullGo.type = 'button';
    pullFoot.appendChild(pullGo);
    pullGroup.appendChild(pullFoot);
    panel.appendChild(pullGroup);

    const status = el('div', 'rp-status', '');
    panel.appendChild(status);
    const log = el('pre', 'rp-log');
    log.hidden = true;
    panel.appendChild(log);

    function chip(item, kind) {
        const b = el('button', 'rp-chip' + (item.exists ? '' : ' absent'));
        b.type = 'button';
        b.disabled = !item.exists;
        const aliasNote = item.aliases && item.aliases.length > 1 ? ' (alias: ' + item.aliases.join(', ') + ')' : '';
        b.title = item.exists ? item.url + aliasNote : 'Репозиторий не создан на хосте — push/pull недоступны';
        b.appendChild(el('span', 'rp-dot ' + (item.exists ? 'ok' : 'no'), ''));
        b.appendChild(el('span', 'rp-chip-name', item.name));
        if (item.domain) { b.appendChild(el('span', 'rp-chip-domain', item.domain)); }
        if (item.aliases && item.aliases.indexOf('origin') !== -1) {
            b.appendChild(el('span', 'rp-chip-alias', 'origin'));
        }
        if (kind === 'push') {
            b.setAttribute('role', 'checkbox');
            b.setAttribute('aria-checked', state.push.has(item.remote) ? 'true' : 'false');
            if (state.push.has(item.remote)) { b.classList.add('on-push'); }
            b.addEventListener('click', () => {
                if (state.busy) { return; }
                if (state.push.has(item.remote)) { state.push.delete(item.remote); }
                else { state.push.add(item.remote); }
                sync();
            });
        } else {
            b.setAttribute('role', 'radio');
            b.setAttribute('aria-checked', state.pull === item.remote ? 'true' : 'false');
            if (state.pull === item.remote) { b.classList.add('on-pull'); }
            b.addEventListener('click', () => {
                if (state.busy) { return; }
                state.pull = state.pull === item.remote ? null : item.remote;
                sync();
            });
        }
        return b;
    }

    function sync() {
        pushChips.textContent = '';
        pullChips.textContent = '';
        groups.forEach((i) => pushChips.appendChild(chip(i, 'push')));
        groups.forEach((i) => pullChips.appendChild(chip(i, 'pull')));
        pushCount.textContent = 'Выбрано: ' + state.push.size;
        pushGo.disabled = state.busy || state.push.size === 0;
        pullGo.disabled = state.busy || !state.pull;
        pushAll.disabled = state.busy;
        pushNone.disabled = state.busy;
        refresh.disabled = state.busy;
    }

    async function run(action, remotes) {
        state.busy = true;
        sync();
        status.textContent = action === 'push' ? 'Отправка…' : 'Получение…';
        status.className = 'rp-status busy';
        log.hidden = false;
        log.textContent = '';
        try {
            const resp = await fetch('/api/project_sync_selected.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ slug: slug, action: action, remotes: remotes })
            });
            const payload = await resp.json();
            const text = (payload.data && payload.data.log) || payload.error || 'пустой ответ';
            log.textContent = text;
            log.scrollTop = log.scrollHeight;
            if (payload.ok) {
                status.textContent = action === 'push' ? '✅ Отправлено на ' + remotes.length + ' хост(ов)' : '✅ Получено из ' + remotes[0];
                status.className = 'rp-status ok';
            } else {
                status.textContent = '⚠ ' + (payload.error || 'Ошибка операции');
                status.className = 'rp-status warn';
            }
        } catch (e) {
            status.textContent = '⚪ сеть: ' + e.message;
            status.className = 'rp-status warn';
        }
        state.busy = false;
        sync();
    }

    pushAll.addEventListener('click', () => {
        groups.forEach((i) => { if (i.exists) { state.push.add(i.remote); } });
        sync();
    });
    pushNone.addEventListener('click', () => { state.push.clear(); sync(); });
    pushGo.addEventListener('click', () => run('push', Array.from(state.push)));
    pullGo.addEventListener('click', () => run('pull', [state.pull]));
    refresh.addEventListener('click', async () => {
        state.busy = true;
        sync();
        status.textContent = 'Проверка хостов…';
        status.className = 'rp-status busy';
        try {
            const fresh = dedupe(await fetchStatuses(slug, true));
            groups.length = 0;
            fresh.forEach((i) => groups.push(i));
            Array.from(state.push).forEach((r) => {
                const it = groups.find((x) => x.remote === r);
                if (!it || !it.exists) { state.push.delete(r); }
            });
            if (state.pull) {
                const it = groups.find((x) => x.remote === state.pull);
                if (!it || !it.exists) { state.pull = null; }
            }
            status.textContent = 'Статусы обновлены';
            status.className = 'rp-status ok';
        } catch (e) {
            status.textContent = '⚪ ' + e.message;
            status.className = 'rp-status warn';
        }
        state.busy = false;
        sync();
    });

    sync();
    return panel;
}

export async function renderRemoteBadges(slug, container) {
    if (!container || !slug) { return; }
    container.textContent = '';
    container.appendChild(el('span', 'remote-badge loading', '… проверка хостов'));
    try {
        const raw = await fetchStatuses(slug, false);
        container.textContent = '';
        if (raw.length === 0) {
            container.appendChild(el('span', 'remote-badge missing', '⚪ remote не настроены'));
            return;
        }
        const groups = dedupe(raw);
        const state = { push: new Set(), pull: null, busy: false };
        const originGroup = groups.find((g) => g.aliases.indexOf('origin') !== -1 && g.exists);
        if (originGroup) { state.pull = originGroup.remote; }
        container.appendChild(buildPanel(slug, groups, state));
        humanizeComparisonLabel(raw);
    } catch (e) {
        container.textContent = '';
        container.appendChild(el('span', 'remote-badge missing', '⚪ ' + e.message));
    }
}

export function initRemoteBadges(container) {
    const attempt = () => {
        const slug = (container && container.dataset && container.dataset.slug) || currentSlug();
        if (!slug) { return false; }
        const host = container || ensureContainer();
        if (!host) { return false; }
        if (host.dataset.renderedSlug === slug) { return true; }
        host.dataset.renderedSlug = slug;
        renderRemoteBadges(slug, host);
        return true;
    };
    if (attempt()) { return; }
    const obs = new MutationObserver(() => { if (attempt()) { obs.disconnect(); } });
    obs.observe(document.body, { childList: true, subtree: true });
    setTimeout(() => obs.disconnect(), MOUNT_TIMEOUT_MS);
}