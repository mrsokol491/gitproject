<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GitProject Admin</title>
    <style>
        :root { --bg: #f4f6f8; --card: #fff; --text: #1f2937; --primary: #2563eb; --success: #16a34a; --warn: #ca8a04; --danger: #dc2626; }
        body { font-family: system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 2rem; line-height: 1.5; }
        .container { max-width: 900px; margin: 0 auto; }
        .card { background: var(--card); border-radius: 8px; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h2 { margin-top: 0; font-size: 1.25rem; border-bottom: 1px solid #e5e7eb; padding-bottom: 0.5rem; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 0.75rem; border-bottom: 1px solid #e5e7eb; }
        th { font-weight: 600; color: #4b5563; }
        .badge { display: inline-block; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.875rem; font-weight: 500; }
        .badge-ready { background: #dcfce7; color: var(--success); }
        .badge-integrating { background: #fef9c3; color: var(--warn); }
        .badge-disabled { background: #f3f4f6; color: #6b7280; }
        .badge-yes { background: #dcfce7; color: var(--success); }
        .badge-no { background: #fee2e2; color: var(--danger); }
        .btn { background: var(--primary); color: #fff; border: none; padding: 0.5rem 1rem; border-radius: 4px; cursor: pointer; font-size: 0.875rem; }
        .btn:hover { opacity: 0.9; }
        .btn:disabled { background: #9ca3af; cursor: not-allowed; }
        select { padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px; margin-right: 0.5rem; }
        pre { background: #1f2937; color: #e5e7eb; padding: 1rem; border-radius: 4px; overflow-x: auto; font-size: 0.875rem; display: none; }
        .loading { opacity: 0.5; pointer-events: none; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🛠️ GitProject Workspace Hub</h1>
        
        <div class="card">
            <h2>Настроенные Git-хосты</h2>
            <table id="hosts-table">
                <thead><tr><th>ID</th><th>Домен</th><th>Статус</th><th>Реестр</th><th>Токен в .env</th></tr></thead>
                <tbody><tr><td colspan="5">Загрузка...</td></tr></tbody>
            </table>
        </div>

        <div class="card">
            <h2>Синхронизация проектов</h2>
            <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                <select id="project-select"><option value="">Загрузка проектов...</option></select>
                <button class="btn" id="sync-btn" onclick="runSync('all')">🔄 Синхронизировать все remote</button>
            </div>
            <pre id="sync-log"></pre>
        </div>
    </div>

    <script>
        async function loadHosts() {
            const res = await fetch('/api/hosts.php');
            const json = await res.json();
            const tbody = document.querySelector('#hosts-table tbody');
            tbody.innerHTML = '';
            
            if (!json.ok) { tbody.innerHTML = `<tr><td colspan="5">Ошибка: ${json.error}</td></tr>`; return; }
            
            json.data.forEach(h => {
                const statusClass = h.status === 'ready' ? 'badge-ready' : (h.status === 'integrating' ? 'badge-integrating' : 'badge-disabled');
                const configClass = h.configured ? 'badge-yes' : 'badge-no';
                const configText = h.configured ? 'Да' : 'Нет';
                
                tbody.innerHTML += `<tr>
                    <td><strong>${h.id}</strong></td>
                    <td>${h.host}</td>
                    <td><span class="badge ${statusClass}">${h.status}</span></td>
                    <td>${h.registry}</td>
                    <td><span class="badge ${configClass}">${configText}</span></td>
                </tr>`;
            });
        }

        async function loadProjects() {
            const res = await fetch('/api/projects.php');
            const json = await res.json();
            const select = document.getElementById('project-select');
            select.innerHTML = '';
            
            if (!json.ok || json.data.length === 0) {
                select.innerHTML = '<option value="">Проекты не найдены</option>';
                return;
            }
            
            json.data.forEach(p => {
                select.innerHTML += `<option value="${p}">${p}</option>`;
            });
        }

        async function runSync(mode) {
            const slug = document.getElementById('project-select').value;
            if (!slug) { alert('Выберите проект'); return; }
            
            const btn = document.getElementById('sync-btn');
            const log = document.getElementById('sync-log');
            
            btn.disabled = true;
            btn.classList.add('loading');
            btn.textContent = '⏳ Синхронизация...';
            log.style.display = 'block';
            log.textContent = 'Выполняется...';

            try {
                const res = await fetch('/api/sync.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ slug, mode })
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
                btn.textContent = '🔄 Синхронизировать все remote';
            }
        }

        // Инициализация
        loadHosts();
        loadProjects();
    </script>
</body>
</html>
