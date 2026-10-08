<?php
declare(strict_types=1);
/**
 * GitProject Workspace Hub — Главная панель управления
 * Объединяет мониторинг хостов, управление проектами и синхронизацию
 */
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GitProject Workspace Hub</title>
    <style>
        :root { --bg: #0f172a; --card: #1e293b; --text: #e2e8f0; --muted: #94a3b8; --primary: #3b82f6; --success: #22c55e; --warn: #eab308; --danger: #ef4444; --border: #334155; }
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 2rem; line-height: 1.6; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #60a5fa; margin-bottom: 0.5rem; }
        .subtitle { color: var(--muted); margin-bottom: 2rem; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 1.5rem; margin-bottom: 1.5rem; }
        .card h2 { margin-top: 0; font-size: 1.25rem; color: #f8fafc; border-bottom: 1px solid var(--border); padding-bottom: 0.75rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { text-align: left; padding: 0.75rem; border-bottom: 1px solid var(--border); }
        th { color: var(--muted); font-weight: 600; font-size: 0.875rem; }
        .badge { display: inline-block; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; }
        .badge-ready { background: rgba(34, 197, 94, 0.2); color: var(--success); }
        .badge-integrating { background: rgba(234, 179, 8, 0.2); color: var(--warn); }
        .badge-yes { background: rgba(34, 197, 94, 0.2); color: var(--success); }
        .badge-no { background: rgba(239, 68, 68, 0.2); color: var(--danger); }
        .btn { background: var(--primary); color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer; font-size: 0.875rem; font-weight: 500; transition: opacity 0.2s; }
        .btn:hover { opacity: 0.9; }
        .btn:disabled { background: var(--border); cursor: not-allowed; opacity: 0.6; }
        .btn-sm { padding: 0.375rem 0.75rem; font-size: 0.75rem; }
        .projects-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 1rem; margin-top: 1rem; }
        .project-card { background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 6px; padding: 1rem; }
        .project-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; }
        .project-name { font-weight: 600; font-size: 1rem; }
        .project-meta { font-size: 0.875rem; color: var(--muted); margin-bottom: 0.5rem; }
        .project-actions { display: flex; gap: 0.5rem; margin-top: 0.75rem; }
        pre.log { background: #0a0f1e; color: #a5b4fc; padding: 1rem; border-radius: 6px; overflow-x: auto; font-size: 0.8rem; display: none; margin-top: 1rem; border: 1px solid var(--border); }
        .loading { opacity: 0.6; pointer-events: none; }
        .status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 0.5rem; }
        .status-ready { background: var(--success); }
        .status-unknown { background: var(--muted); }
        .alert { padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1rem; }
        .alert-info { background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 GitProject Workspace Hub</h1>
        <p class="subtitle">Рабочее пространство @sokol491 — репозитории в контейнере projects/</p>
        
        <div class="card">
            <h2>📊 Статус Git-хостов</h2>
            <table id="hosts-table">
                <thead><tr><th>ID</th><th>Домен</th><th>Статус</th><th>Реестр</th><th>Токен</th></tr></thead>
                <tbody><tr><td colspan="5" style="text-align:center;">Загрузка...</td></tr></tbody>
            </table>
        </div>

        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                <h2 style="margin:0;">📁 Проекты</h2>
                <button class="btn" id="sync-all-btn" onclick="runSync('all', '')">🔄 Синхронизировать все</button>
            </div>
            <div id="projects-container" class="projects-grid">
                <div style="grid-column:1/-1;text-align:center;padding:2rem;color:var(--muted);">Загрузка проектов...</div>
            </div>
            <pre id="sync-log" class="log"></pre>
        </div>
    </div>

    <script>
        async function loadHosts() {
            try {
                const res = await fetch('/api/hosts.php');
                const json = await res.json();
                const tbody = document.querySelector('#hosts-table tbody');
                
                if (!json.ok) {
                    tbody.innerHTML = `<tr><td colspan="5" style="color:var(--danger);text-align:center;">Ошибка: ${json.error}</td></tr>`;
                    return;
                }
                
                tbody.innerHTML = json.data.map(h => {
                    const statusClass = h.status === 'ready' ? 'badge-ready' : 'badge-integrating';
                    const configClass = h.configured ? 'badge-yes' : 'badge-no';
                    return `<tr>
                        <td><strong>${h.id}</strong></td>
                        <td>${h.host}</td>
                        <td><span class="badge ${statusClass}">${h.status}</span></td>
                        <td>${h.registry}</td>
                        <td><span class="badge ${configClass}">${h.configured ? '✓' : '✗'}</span></td>
                    </tr>`;
                }).join('');
            } catch (e) {
                console.error('Hosts error:', e);
            }
        }

        async function loadProjects() {
            try {
                const res = await fetch('/api/projects.php');
                const json = await res.json();
                const container = document.getElementById('projects-container');
                
                if (!json.ok || json.data.length === 0) {
                    container.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:2rem;color:var(--muted);">Проекты не найдены</div>';
                    return;
                }
                
                container.innerHTML = json.data.map(p => `
                    <div class="project-card">
                        <div class="project-header">
                            <span class="project-name"><span class="status-dot status-unknown"></span>${p}</span>
                            <span class="badge badge-integrating">unknown</span>
                        </div>
                        <div class="project-meta">нет описания</div>
                        <div class="project-actions">
                            <button class="btn btn-sm" onclick="runSync('all', '${p}')">Синхронизировать</button>
                            <a href="/${p}/" class="btn btn-sm" style="background:var(--border);text-decoration:none;color:white;">Открыть</a>
                        </div>
                    </div>
                `).join('');
            } catch (e) {
                console.error('Projects error:', e);
            }
        }

        async function runSync(mode, slug) {
            const targetSlug = mode === 'all' ? (slug || 'all') : slug;
            const btn = mode === 'all' && !slug ? document.getElementById('sync-all-btn') : event.target;
            const log = document.getElementById('sync-log');
            
            btn.disabled = true;
            btn.classList.add('loading');
            const originalText = btn.textContent;
            btn.textContent = '⏳ Выполняется...';
            log.style.display = 'block';
            log.textContent = 'Синхронизация...';

            try {
                const res = await fetch('/api/sync.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ slug: targetSlug, mode: mode })
                });
                const json = await res.json();
                
                log.textContent = json.ok 
                    ? '✅ Успех:\n' + (json.data?.log || 'Готово')
                    : '❌ Ошибка: ' + json.error + '\n\n' + (json.data?.log || '');
            } catch (e) {
                log.textContent = '❌ Сетевая ошибка: ' + e.message;
            } finally {
                btn.disabled = false;
                btn.classList.remove('loading');
                btn.textContent = originalText;
            }
        }

        // Инициализация
        loadHosts();
        loadProjects();
    </script>
</body>
</html>
