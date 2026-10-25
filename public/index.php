<?php
declare(strict_types=1);
/**
 * GitProject Workspace Hub — Главная панель управления
 * Единая точка входа: мониторинг хостов, проекты, синхронизация
 */
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GitProject Workspace Hub</title>
    <style>
        :root {
            --bg: #0d0b1a;
            --bg-gradient: linear-gradient(135deg, #0d0b1a 0%, #1a1333 50%, #0f172a 100%);
            --card: #1a1528;
            --card-hover: #221d35;
            --text: #e8e4f3;
            --muted: #9b8fb8;
            --primary: #7c3aed;
            --primary-light: #a78bfa;
            --primary-glow: rgba(124, 58, 237, 0.3);
            --success: #10b981;
            --warn: #f59e0b;
            --danger: #ef4444;
            --border: #2d2640;
            --border-hover: #7c3aed;
        }
        * { box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-gradient);
            background-attachment: fixed;
            color: var(--text);
            margin: 0;
            padding: 2rem;
            line-height: 1.6;
            min-height: 100vh;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem; }
        h1 { 
            background: linear-gradient(135deg, #a78bfa, #7c3aed, #6d28d9);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 0.25rem 0;
            font-size: 2rem;
            font-weight: 700;
        }
        .subtitle { color: var(--muted); margin: 0; font-size: 1rem; }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 20px rgba(124, 58, 237, 0.08);
            transition: all 0.3s ease;
        }
        .card:hover {
            box-shadow: 0 6px 30px rgba(124, 58, 237, 0.15);
        }
        .card h2 {
            margin: 0 0 1rem 0;
            font-size: 1.25rem;
            color: #f3e8ff;
            border-bottom: 1px solid var(--border);
            padding-bottom: 0.75rem;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            border-bottom: 1px solid var(--border);
            padding-bottom: 0.75rem;
            background: linear-gradient(90deg, rgba(124, 58, 237, 0.05), transparent);
        }
        .card-header h2 { margin: 0; border: none; padding: 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 0.75rem; border-bottom: 1px solid var(--border); }
        th { color: var(--primary-light); font-weight: 600; font-size: 0.875rem; }
        .badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-ready { background: rgba(16, 185, 129, 0.2); color: var(--success); }
        .badge-integrating { background: rgba(245, 158, 11, 0.2); color: var(--warn); }
        .badge-yes { background: rgba(16, 185, 129, 0.2); color: var(--success); }
        .badge-no { background: rgba(239, 68, 68, 0.2); color: var(--danger); }
        .btn {
            background: linear-gradient(135deg, #7c3aed, #6d28d9);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-block;
            box-shadow: 0 2px 8px rgba(124, 58, 237, 0.3);
        }
        .btn:hover { 
            opacity: 0.95;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.4);
            transform: translateY(-1px);
        }
        .btn:disabled { 
            background: var(--border); 
            cursor: not-allowed; 
            opacity: 0.6;
            box-shadow: none;
            transform: none;
        }
        .btn-sm { padding: 0.375rem 0.75rem; font-size: 0.75rem; }
        .btn-success { 
            background: linear-gradient(135deg, #10b981, #059669);
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }
        .btn-ghost { 
            background: transparent; 
            border: 1px solid var(--border); 
            color: var(--text);
            box-shadow: none;
        }
        .btn-ghost:hover { 
            background: rgba(124, 58, 237, 0.1);
            border-color: var(--primary-light);
        }
        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
        }
        .project-card {
            background: rgba(124, 58, 237, 0.05);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 1rem;
            transition: all 0.3s ease;
        }
        .project-card:hover { 
            border-color: var(--primary-light);
            background: rgba(124, 58, 237, 0.08);
            box-shadow: 0 4px 15px rgba(124, 58, 237, 0.15);
        }
        .project-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        .project-name { font-weight: 600; font-size: 1.1rem; color: #f3e8ff; }
        .project-desc { color: var(--muted); font-size: 0.875rem; margin-bottom: 1rem; min-height: 1.5em; }
        .project-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        pre.log {
            background: #120e24;
            color: #c4b5fd;
            padding: 1rem;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 0.8rem;
            font-family: 'Courier New', monospace;
            margin-top: 1rem;
            border: 1px solid var(--border);
            max-height: 400px;
            white-space: pre-wrap;
            word-break: break-word;
            display: none;
        }
        .loading { opacity: 0.6; pointer-events: none; }
        .empty { text-align: center; padding: 2rem; color: var(--muted); }
        .sync-progress {
            margin-top: 0.5rem;
            font-size: 0.875rem;
            color: var(--muted);
        }
        .hub-info { display: flex; flex-direction: column; gap: 0.5rem; }
        .hub-row { display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; }
        .hub-label { color: var(--muted); font-size: 0.875rem; }
        .hub-value { color: var(--text); font-weight: 500; font-family: monospace; }
        .hub-updates { color: var(--warn); font-weight: 600; }
        .hub-uptodate { color: var(--success); font-weight: 600; }
        .hub-ahead { color: var(--primary-light); font-size: 0.8rem; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div>
                <h1>🚀 GitProject Workspace Hub</h1>
                <p class="subtitle">Рабочее пространство @sokol491 — репозитории в контейнере projects/</p>
            </div>
        </header>

        <!-- Статус хостов -->
        <div class="card">
            <h2>📊 Статус Git-хостов</h2>
            <table id="hosts-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Домен</th>
                        <th>Статус</th>
                        <th>Реестр</th>
                        <th>Токен настроен</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="5" class="empty">Загрузка...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Статус Hub -->
        <div class="card" id="hub-status-card">
            <div class="card-header">
                <h2>🔧 GitProject Hub</h2>
                <button class="btn btn-sm" id="hub-check-btn" onclick="checkHubStatus()">🔍 Проверить обновления</button>
            </div>
            <div id="hub-status-content">
                <div class="empty">Нажмите "Проверить обновления" для проверки</div>
            </div>
            <pre id="hub-log" class="log"></pre>
        </div>

        <!-- Проекты -->
        <div class="card">
            <div class="card-header">
                <h2>📁 Проекты</h2>
                <button class="btn btn-success" id="sync-all-btn" onclick="syncAll()">
                    🔄 Синхронизировать все
                </button>
            </div>
            <div id="projects-container" class="projects-grid">
                <div class="empty" style="grid-column: 1/-1;">Загрузка проектов...</div>
            </div>
            <div id="sync-progress" class="sync-progress"></div>
            <pre id="sync-log" class="log"></pre>
        </div>
    </div>

    <script>
        // Загрузка списка хостов
        async function loadHosts() {
            const tbody = document.querySelector('#hosts-table tbody');
            try {
                const res = await fetch('/api/hosts.php');
                const json = await res.json();
                if (!json.ok) {
                    tbody.innerHTML = `<tr><td colspan="5" class="empty" style="color:var(--danger);">Ошибка: ${json.error}</td></tr>`;
                    return;
                }
                tbody.innerHTML = json.data.map(h => {
                    const statusClass = h.status === 'ready' ? 'badge-ready' : 'badge-integrating';
                    const configClass = h.configured ? 'badge-yes' : 'badge-no';
                    return `<tr>
                        <td><strong>${escapeHtml(h.id)}</strong></td>
                        <td>${escapeHtml(h.host)}</td>
                        <td><span class="badge ${statusClass}">${escapeHtml(h.status)}</span></td>
                        <td>${escapeHtml(h.registry)}</td>
                        <td><span class="badge ${configClass}">${h.configured ? '✓' : '✗'}</span></td>
                    </tr>`;
                }).join('');
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="5" class="empty" style="color:var(--danger);">Ошибка сети: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        // Загрузка списка проектов
        async function loadProjects() {
            const container = document.getElementById('projects-container');
            try {
                const res = await fetch('/api/projects.php');
                const json = await res.json();
                if (!json.ok || !json.data || json.data.length === 0) {
                    container.innerHTML = '<div class="empty" style="grid-column: 1/-1;">Проекты не найдены в projects/</div>';
                    return;
                }
                container.innerHTML = json.data.map(p => {
                    const dirtyBadge = p.dirty
                        ? '<span class="badge badge-integrating" style="margin-left:0.5rem">!</span>'
                        : '';
                    const branchInfo = p.branch
                        ? '<span class="badge badge-ready" style="margin-left:0.5rem;font-size:0.7rem">' + escapeHtml(p.branch) + '</span>'
                        : '';
                    const desc = p.description && p.description !== '' ? escapeHtml(p.description) : 'нет описания';
                    const remotes = p.remotes || {};
                    const remoteNames = Object.keys(remotes);
                    const showPull = remoteNames.length > 0;
                    const pullButtons = showPull
                        ? remoteNames.map(r =>
                            '<button class="btn btn-sm btn-ghost" onclick="syncPull(\'' + escapeHtml(p.slug) + '\', \'' + escapeHtml(r) + '\')" title="Pull из ' + escapeHtml(r) + '">⬇ ' + escapeHtml(r) + '</button>'
                          ).join('')
                        : '';
                    return '<div class="project-card" data-slug="' + escapeHtml(p.slug) + '">' +
                        '<div class="project-head">' +
                            '<span class="project-name">' + escapeHtml(p.name) + dirtyBadge + branchInfo + '</span>' +
                        '</div>' +
                        '<div class="project-desc">' + desc + '</div>' +
                        '<div class="project-actions">' +
                            '<button class="btn btn-sm" onclick="syncOne(\'' + escapeHtml(p.slug) + '\')">🔄 Синхронизировать</button>' +
                            (showPull ? '<button class="btn btn-sm btn-ghost" onclick="syncPullAll(\'' + escapeHtml(p.slug) + '\')" title="Pull из всех remote">⬇ Все</button>' : '') +
                            (pullButtons ? '<div style="display:flex;gap:0.3rem;flex-wrap:wrap;margin-top:0.3rem">' + pullButtons + '</div>' : '') +
                            '<a href="/' + escapeHtml(p.slug) + '/" class="btn btn-sm btn-ghost">Открыть</a>' +
                        '</div>' +
                    '</div>';
                }).join('');
            } catch (e) {
                container.innerHTML = '<div class="empty" style="grid-column: 1/-1; color:var(--danger);">Ошибка сети: ' + escapeHtml(e.message) + '</div>';
            }
        }

        // Синхронизация одного проекта
        async function syncOne(slug) {
            const card = document.querySelector(`.project-card[data-slug="${CSS.escape(slug)}"]`);
            const btn = card ? card.querySelector('button') : null;
            const log = document.getElementById('sync-log');
            const progress = document.getElementById('sync-progress');

            if (btn) {
                btn.disabled = true;
                btn.classList.add('loading');
                btn.textContent = '⏳ Выполняется...';
            }
            log.style.display = 'block';
            log.textContent = `Синхронизация проекта ${slug}...`;
            progress.textContent = '';

            try {
                const res = await fetch('/api/sync.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ slug: slug, mode: 'push' })
                });
                const json = await res.json();
                log.textContent = json.ok
                    ? `✅ Успех (${slug}):\n${json.data?.log || 'Готово'}`
                    : `❌ Ошибка (${slug}): ${json.error}\n\n${json.data?.log || ''}`;
            } catch (e) {
                log.textContent = `❌ Сетевая ошибка: ${e.message}`;
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.classList.remove('loading');
                    btn.textContent = '🔄 Синхронизировать';
                }
            }
        }

        // Синхронизация ВСЕХ проектов последовательно
        async function syncAll() {
            const cards = document.querySelectorAll('.project-card');
            if (cards.length === 0) {
                alert('Нет проектов для синхронизации');
                return;
            }

            const btn = document.getElementById('sync-all-btn');
            const log = document.getElementById('sync-log');
            const progress = document.getElementById('sync-progress');

            btn.disabled = true;
            btn.classList.add('loading');
            btn.textContent = '⏳ Синхронизация...';
            log.style.display = 'block';
            log.textContent = '';
            progress.textContent = `Обработка 0 из ${cards.length}...`;

            const slugs = Array.from(cards).map(c => c.dataset.slug);
            let successCount = 0;
            let failCount = 0;

            for (let i = 0; i < slugs.length; i++) {
                const slug = slugs[i];
                progress.textContent = `Обработка ${i + 1} из ${slugs.length}: ${slug}...`;
                log.textContent += `\n--- [${i + 1}/${slugs.length}] ${slug} ---\n`;

                try {
                    const res = await fetch('/api/sync.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ slug: slug, mode: 'push' })
                    });
                    const json = await res.json();
                    if (json.ok) {
                        successCount++;
                        log.textContent += `✅ Успех\n${json.data?.log || ''}\n`;
                    } else {
                        failCount++;
                        log.textContent += `❌ Ошибка: ${json.error}\n${json.data?.log || ''}\n`;
                    }
                } catch (e) {
                    failCount++;
                    log.textContent += `❌ Сетевая ошибка: ${e.message}\n`;
                }

                // Прокрутка лога вниз
                log.scrollTop = log.scrollHeight;
            }

            progress.textContent = `Завершено: успешно ${successCount}, ошибок ${failCount} из ${slugs.length}`;
            btn.disabled = false;
            btn.classList.remove('loading');
            btn.textContent = '🔄 Синхронизировать все';
        }

        // --- Hub update functions ---------------------------------------------------

        async function checkHubStatus() {
            const content = document.getElementById('hub-status-content');
            const btn = document.getElementById('hub-check-btn');
            btn.disabled = true;
            btn.textContent = '⏳ Проверка...';
            content.innerHTML = '<div class="empty">Загрузка...</div>';

            try {
                const res = await fetch('/api/hub_update.php?action=status');
                const json = await res.json();
                if (!json.ok) {
                    content.innerHTML = '<div class="empty" style="color:var(--danger);">Ошибка: ' + escapeHtml(json.error) + '</div>';
                    return;
                }

                const d = json.data;
                let html = '<div class="hub-info">';
                html += '<div class="hub-row">';
                html += '<span class="hub-label">Ветка:</span>';
                html += '<span class="hub-value">' + escapeHtml(d.current_branch || 'unknown') + '</span>';
                html += '</div>';
                html += '<div class="hub-row">';
                html += '<span class="hub-label">Коммит:</span>';
                html += '<span class="hub-value">' + escapeHtml(d.current_commit || 'unknown') + '</span>';
                html += '</div>';

                if (d.has_updates) {
                    html += '<div class="hub-row">';
                    html += '<span class="hub-label">Доступно обновлений:</span>';
                    html += '<span class="hub-updates">⬆ ' + d.behind + ' коммитов, ' + d.changed_files + ' файлов</span>';
                    html += '</div>';
                    if (d.log && d.log.length > 0) {
                        html += '<div class="hub-row">';
                        html += '<span class="hub-label">Последние коммиты:</span>';
                        html += '</div>';
                        html += '<div style="margin-left:1rem">';
                        d.log.forEach(function(msg) {
                            html += '<div style="color:var(--muted);font-size:0.8rem;font-family:monospace;">' + escapeHtml(msg) + '</div>';
                        });
                        html += '</div>';
                    }
                    html += '<div class="hub-row" style="margin-top:0.5rem">';
                    html += '<button class="btn btn-sm" onclick="applyHubUpdate()">⬇ Применить обновления</button>';
                    html += '</div>';
                } else {
                    html += '<div class="hub-row">';
                    html += '<span class="hub-uptodate">✓ Актуальная версия</span>';
                    if (d.ahead > 0) {
                        html += '<span class="hub-ahead">(' + d.ahead + ' коммит(ов) впереди origin)</span>';
                    }
                    html += '</div>';
                }

                html += '</div>';
                content.innerHTML = html;
            } catch (e) {
                content.innerHTML = '<div class="empty" style="color:var(--danger);">Ошибка сети: ' + escapeHtml(e.message) + '</div>';
            } finally {
                btn.disabled = false;
                btn.textContent = '🔍 Проверить обновления';
            }
        }

        async function applyHubUpdate() {
            const content = document.getElementById('hub-status-content');
            const btn = document.getElementById('hub-check-btn');
            const log = document.getElementById('hub-log');
            btn.disabled = true;
            btn.textContent = '⏳ Обновление...';
            log.style.display = 'block';
            log.textContent = 'Применение обновлений...';
            content.innerHTML = '<div class="empty">Обновление...</div>';

            try {
                const res = await fetch('/api/hub_update.php?action=pull', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({})
                });
                const json = await res.json();
                if (json.ok) {
                    content.innerHTML = '<div class="hub-info"><div class="hub-row"><span class="hub-uptodate">✓ Hub обновлён</span></div></div>';
                    log.textContent = json.data.log.join('\n');
                } else {
                    content.innerHTML = '<div class="empty" style="color:var(--danger);">Ошибка: ' + escapeHtml(json.error) + '</div>';
                    log.textContent = json.data?.log?.join('\n') || json.error;
                }
            } catch (e) {
                content.innerHTML = '<div class="empty" style="color:var(--danger);">Ошибка сети: ' + escapeHtml(e.message) + '</div>';
                log.textContent = 'Ошибка сети: ' + e.message;
            } finally {
                btn.disabled = false;
                btn.textContent = '🔍 Проверить обновления';
            }
        }

        // --- Pull functions ---------------------------------------------------------

        async function syncPull(slug, remote) {
            const card = document.querySelector('.project-card[data-slug="' + CSS.escape(slug) + '"]');
            const btn = card ? card.querySelector('button[title*="' + remote + '"]') : null;
            const log = document.getElementById('sync-log');
            const progress = document.getElementById('sync-progress');

            if (btn) {
                btn.disabled = true;
                btn.classList.add('loading');
                btn.textContent = '⏳ Pull...';
            }
            log.style.display = 'block';
            log.textContent = 'Pull из ' + remote + ': ' + slug + '...';
            progress.textContent = '';

            try {
                const res = await fetch('/api/sync.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ slug: slug, mode: 'pull', remote: remote })
                });
                const json = await res.json();
                log.textContent = json.ok
                    ? '✅ Pull (' + slug + ', ' + remote + '):\n' + (json.data?.log || 'Готово')
                    : '❌ Pull ошибка (' + slug + '): ' + json.error + '\n\n' + (json.data?.log || '');
            } catch (e) {
                log.textContent = '❌ Сетевая ошибка: ' + e.message;
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.classList.remove('loading');
                    btn.textContent = '⬇ ' + remote;
                }
            }
        }

        async function syncPullAll(slug) {
            // Pull from origin first, then offer per-remote pull
            await syncPull(slug, 'origin');
        }

        // Экранирование HTML
        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // Инициализация при загрузке
        loadHosts();
        loadProjects();
        checkHubStatus();
    </script>
</body>
</html>
