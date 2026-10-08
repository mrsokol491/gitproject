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
            --bg: #0f172a;
            --card: #1e293b;
            --text: #e2e8f0;
            --muted: #94a3b8;
            --primary: #3b82f6;
            --success: #22c55e;
            --warn: #eab308;
            --danger: #ef4444;
            --border: #334155;
        }
        * { box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 2rem;
            line-height: 1.6;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem; }
        h1 { color: #60a5fa; margin: 0 0 0.25rem 0; font-size: 2rem; }
        .subtitle { color: var(--muted); margin: 0; font-size: 1rem; }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .card h2 {
            margin: 0 0 1rem 0;
            font-size: 1.25rem;
            color: #f8fafc;
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
        }
        .card-header h2 { margin: 0; border: none; padding: 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 0.75rem; border-bottom: 1px solid var(--border); }
        th { color: var(--muted); font-weight: 600; font-size: 0.875rem; }
        .badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-ready { background: rgba(34, 197, 94, 0.2); color: var(--success); }
        .badge-integrating { background: rgba(234, 179, 8, 0.2); color: var(--warn); }
        .badge-yes { background: rgba(34, 197, 94, 0.2); color: var(--success); }
        .badge-no { background: rgba(239, 68, 68, 0.2); color: var(--danger); }
        .btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
            transition: opacity 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover { opacity: 0.9; }
        .btn:disabled { background: var(--border); cursor: not-allowed; opacity: 0.6; }
        .btn-sm { padding: 0.375rem 0.75rem; font-size: 0.75rem; }
        .btn-success { background: var(--success); }
        .btn-ghost { background: transparent; border: 1px solid var(--border); color: var(--text); }
        .btn-ghost:hover { background: var(--border); }
        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
        }
        .project-card {
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1rem;
            transition: border-color 0.2s;
        }
        .project-card:hover { border-color: #60a5fa; }
        .project-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        .project-name { font-weight: 600; font-size: 1.1rem; color: #f1f5f9; }
        .project-desc { color: var(--muted); font-size: 0.875rem; margin-bottom: 1rem; min-height: 1.5em; }
        .project-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        pre.log {
            background: #0a0f1e;
            color: #a5b4fc;
            padding: 1rem;
            border-radius: 6px;
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
                container.innerHTML = json.data.map(p => `
                    <div class="project-card" data-slug="${escapeHtml(p)}">
                        <div class="project-head">
                            <span class="project-name">${escapeHtml(p)}</span>
                        </div>
                        <div class="project-desc">нет описания</div>
                        <div class="project-actions">
                            <button class="btn btn-sm" onclick="syncOne('${escapeHtml(p)}')">🔄 Синхронизировать</button>
                            <a href="/${escapeHtml(p)}/" class="btn btn-sm btn-ghost">Открыть</a>
                        </div>
                    </div>
                `).join('');
            } catch (e) {
                container.innerHTML = `<div class="empty" style="grid-column: 1/-1; color:var(--danger);">Ошибка сети: ${escapeHtml(e.message)}</div>`;
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
                    body: JSON.stringify({ slug: slug, mode: 'all' })
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
                        body: JSON.stringify({ slug: slug, mode: 'all' })
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
    </script>
</body>
</html>
