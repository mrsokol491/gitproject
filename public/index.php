<?php
declare(strict_types=1);
// Главная панель управления GitProject Workspace Hub
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GitProject Workspace Hub</title>
    <style>
        :root { --bg: #f4f6f8; --card: #fff; --text: #1f2937; --primary: #2563eb; --success: #16a34a; --warn: #ca8a04; --danger: #dc2626; }
        body { font-family: system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 2rem; line-height: 1.5; }
        .container { max-width: 1000px; margin: 0 auto; }
        .card { background: var(--card); border-radius: 8px; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h1 { margin-top: 0; font-size: 1.5rem; border-bottom: 1px solid #e5e7eb; padding-bottom: 0.5rem; }
        h2 { margin-top: 0; font-size: 1.25rem; color: #4b5563; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { text-align: left; padding: 0.75rem; border-bottom: 1px solid #e5e7eb; }
        th { font-weight: 600; color: #4b5563; background: #f9fafb; }
        .badge { display: inline-block; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.875rem; font-weight: 500; }
        .badge-ready { background: #dcfce7; color: var(--success); }
        .badge-integrating { background: #fef9c3; color: var(--warn); }
        .badge-yes { background: #dcfce7; color: var(--success); }
        .badge-no { background: #fee2e2; color: var(--danger); }
        .btn { background: var(--primary); color: #fff; border: none; padding: 0.5rem 1rem; border-radius: 4px; cursor: pointer; font-size: 0.875rem; text-decoration: none; display: inline-block; }
        .btn:hover { opacity: 0.9; }
        .btn:disabled { background: #9ca3af; cursor: not-allowed; }
        .btn-sm { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
        pre { background: #1f2937; color: #e5e7eb; padding: 1rem; border-radius: 4px; overflow-x: auto; font-size: 0.875rem; display: none; margin-top: 1rem; }
        .loading { opacity: 0.5; pointer-events: none; }
        .actions { display: flex; gap: 0.5rem; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🛠️ GitProject Workspace Hub</h1>
        
        <div class="card">
            <h2>Статус Git-хостов</h2>
            <table id="hosts-table">
                <thead><tr><th>ID</th><th>Домен</th><th>Статус</th><th>Реестр</th><th>Токен в .env</th></tr></thead>
                <tbody><tr><td colspan="5">Загрузка...</td></tr></tbody>
            </table>
        </div>

        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h2 style="margin: 0;">Проекты</h2>
                <button class="btn" id="sync-all-btn" onclick="runSync('all', '')">🔄 Синхронизировать все проекты</button>
            </div>
            <table id="projects-table">
                <thead><tr><th>Проект</th><th>Действия</th></tr></thead>
                <tbody><tr><td colspan="2">Загрузка...</td></tr></tbody>
            </table>
            <pre id="sync-log"></pre>
        </div>
    </div>

    <script>
        async function loadHosts() {
            try {
                const res = await fetch('/api/hosts.php');
                const json = await res.json();
                const tbody = document.querySelector('#hosts-table tbody');
                tbody.innerHTML = '';
                
                if (!json.ok) { tbody.innerHTML = `<tr><td colspan="5">Ошибка: ${json.error}</td></tr>`; return; }
                
                json.data.forEach(h => {
                    const statusClass = h.status === 'ready' ? 'badge-ready' : 'badge-integrating';
                    const configClass = h.configured ? 'badge-yes' : 'badge-no';
                    tbody.innerHTML += `<tr>
                        <td><strong>${h.id}</strong></td>
                        <td>${h.host}</td>
                        <td><span class="badge ${statusClass}">${h.status}</span></td>
                        <td>${h.registry}</td>
                        <td><span class="badge ${configClass}">${h.configured ? 'Да' : 'Нет'}</span></td>
                    </tr>`;
                });
            } catch (e) { console.error(e); }
        }

        async function loadProjects() {
            try {
                const res = await fetch('/api/projects.php');
                const json = await res.json();
                const tbody = document.querySelector('#projects-table tbody');
                tbody.innerHTML = '';
                
                if (!json.ok || json.data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="2">Проекты не найдены в ~/gitproject/projects/</td></tr>';
                    return;
                }
                
                json.data.forEach(p => {
                    tbody.innerHTML += `<tr>
                        <td><strong>${p}</strong></td>
                        <td class="actions">
                            <button class="btn btn-sm" onclick="runSync('all', '${p}')">Синхронизировать</button>
                        </td>
                    </tr>`;
                });
            } catch (e) { console.error(e); }
        }

        async function runSync(mode, slug) {
            const targetSlug = mode === 'all' ? (slug || document.querySelector('#projects-table tbody tr td strong')?.textContent) : slug;
            if (!targetSlug && mode !== 'all') { alert('Ошибка'); return; }
            
            const btn = mode === 'all' && !slug ? document.getElementById('sync-all-btn') : event.target;
            const log = document.getElementById('sync-log');
            
            btn.disabled = true;
            btn.classList.add('loading');
            const originalText = btn.textContent;
            btn.textContent = '⏳ Выполняется...';
            log.style.display = 'block';
            log.textContent = 'Выполняется синхронизация...';

            try {
                const res = await fetch('/api/sync.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ slug: targetSlug, mode: mode })
                });
                const json = await res.json();
                
                if (json.ok) {
                    log.textContent = '✅ Успех:\n' + (json.data.log || 'Нет вывода');
                } else {
                    log.textContent = '❌ Ошибка: ' + json.error + '\n\nЛог:\n' + (json.data?.log || 'Нет вывода');
                }
            } catch (e) {
                log.textContent = '❌ Сетевая ошибка: ' + e.message;
            } finally {
                btn.disabled = false;
                btn.classList.remove('loading');
                btn.textContent = originalText;
            }
        }

        // Инициализация при загрузке
        loadHosts();
        loadProjects();
    </script>
</body>
</html>
