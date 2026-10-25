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
        .btn-clone {
            background: linear-gradient(135deg, #10b981, #059669);
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }
        .btn-clone:hover {
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
        }
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

        /* Таблица миграции */
        #migrate-versions-table { width:100%;border-collapse:collapse;font-size:0.85rem; }
        #migrate-versions-table th { background:rgba(124,58,237,0.15);padding:0.5rem;text-align:left;color:var(--primary-light); }
        #migrate-versions-table td { padding:0.5rem;border-bottom:1px solid var(--border); }
        #migrate-versions-table tr:hover { background:rgba(124,58,237,0.05); }
        #migrate-versions-table .state-fresh { color:var(--success); }
        #migrate-versions-table .state-behind { color:var(--warn); }
        #migrate-versions-table .state-diverged { color:var(--danger); }
        #migrate-versions-table .state-empty { color:var(--muted); }
        #migrate-versions-table input[type="radio"] { accent-color: var(--primary); }

        /* Модальное окно */
        .modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1000; }
        .modal-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); }
        .modal-content {
            position: relative;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.5rem;
            margin: 3rem auto;
            max-width: 550px;
            max-height: calc(100vh - 6rem);
            overflow-y: auto;
            box-shadow: 0 8px 40px rgba(0,0,0,0.4);
        }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 1.25rem; padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border);
        }
        .modal-header h2 { margin: 0; font-size: 1.25rem; color: #f3e8ff; }
        .modal-close {
            background: none; border: none; color: var(--muted); font-size: 1.25rem;
            cursor: pointer; padding: 0.25rem 0.5rem;
        }
        .modal-close:hover { color: var(--text); }
        .form-group { margin-bottom: 1rem; }
        .form-group label {
            display: block; margin-bottom: 0.4rem; color: var(--muted);
            font-size: 0.875rem; font-weight: 500;
        }
        .form-group label small { color: var(--border-hover); font-weight: 400; }
        .form-group input, .form-group select {
            width: 100%; padding: 0.6rem 0.75rem;
            background: #120e24; border: 1px solid var(--border);
            border-radius: 8px; color: var(--text); font-size: 0.9rem;
            transition: border-color 0.2s;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none; border-color: var(--primary-light);
        }
        .modal-actions {
            display: flex; gap: 0.75rem; margin-top: 1.25rem;
        }
        .modal-actions .btn { flex: 1; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div>
                <h1>🚀 GitProject Workspace Hub</h1>
                <p class="subtitle">Рабочее пространство @sokol491 — репозитории в контейнере projects/</p>
            </div>
            <button class="btn btn-clone" onclick="openCloneModal()">➕ Клонировать проект</button>
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
                <div style="display:flex;gap:0.5rem;">
                    <button class="btn btn-sm" id="hub-check-btn" onclick="checkHubStatus()">🔍 Проверить обновления</button>
                    <button class="btn btn-sm btn-success" id="hub-push-btn" onclick="hubMultiPush()">📤 Мульти-push</button>
                </div>
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

    <!-- Модальное окно клонирования -->
    <div id="clone-modal" class="modal" style="display:none;">
        <div class="modal-overlay" onclick="closeCloneModal()"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h2>➕ Клонировать проект</h2>
                <button class="modal-close" onclick="closeCloneModal()">✕</button>
            </div>
            <form id="clone-form" onsubmit="doClone(event)">
                <div class="form-group">
                    <label for="clone-host">Хост</label>
                    <select id="clone-host" required>
                        <option value="">Выберите хост...</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="clone-owner">Владелец (owner)</label>
                    <input type="text" id="clone-owner" placeholder="sokol491" required>
                </div>
                <div class="form-group">
                    <label for="clone-slug">Имя проекта (slug)</label>
                    <input type="text" id="clone-slug" placeholder="my-project" required>
                </div>
                <div class="form-group">
                    <label for="clone-local">Локальное имя <small>(опционально, по умолчанию = slug)</small></label>
                    <input type="text" id="clone-local" placeholder="my-project">
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-clone" id="clone-submit-btn">Клонировать</button>
                    <button type="button" class="btn btn-ghost" onclick="closeCloneModal()">Отмена</button>
                </div>
            </form>
            <div id="clone-result" style="display:none;">
                <pre id="clone-log" class="log"></pre>
            </div>
        </div>
    </div>

    <!-- Модальное окно удаления -->
    <div id="delete-modal" class="modal" style="display:none;">
        <div class="modal-overlay" onclick="closeDeleteModal()"></div>
        <div class="modal-content" style="border-color:var(--danger);">
            <div class="modal-header">
                <h2 style="color:var(--danger);">🗑 Удалить проект</h2>
                <button class="modal-close" onclick="closeDeleteModal()">✕</button>
            </div>
            <div id="delete-info" style="margin-bottom:1rem;padding:0.75rem;background:#1a0e0e;border-radius:8px;font-size:0.9rem;">
            </div>
            <div id="delete-options" style="display:flex;flex-direction:column;gap:0.5rem;">
            </div>
            <div id="delete-confirm-section" style="display:none;margin-top:1rem;">
                <div class="form-group">
                    <label for="delete-confirm-code">Код подтверждения</label>
                    <input type="text" id="delete-confirm-code" placeholder="Введите код..." style="font-family:monospace;">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn" style="background:linear-gradient(135deg,#ef4444,#dc2626);" id="delete-execute-btn" onclick="executeDelete()">Удалить</button>
                    <button type="button" class="btn btn-ghost" onclick="closeDeleteModal()">Отмена</button>
                </div>
            </div>
            <div id="delete-result" style="display:none;">
                <pre id="delete-log" class="log"></pre>
            </div>
        </div>
    </div>

    <!-- Модальное окно миграции -->
    <div id="migrate-modal" class="modal" style="display:none;">
        <div class="modal-overlay" onclick="closeMigrateModal()"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h2>🔀 Миграция проекта</h2>
                <button class="modal-close" onclick="closeMigrateModal()">✕</button>
            </div>
            <div id="migrate-info" style="margin-bottom:1rem;padding:0.75rem;background:rgba(124,58,237,0.1);border-radius:8px;font-size:0.9rem;">
                <strong>Проект:</strong> <span id="migrate-slug-name"></span>
            </div>
            <div id="migrate-table-section">
                <div id="migrate-loading" style="text-align:center;padding:1rem;color:var(--muted);">Загрузка таблицы версий...</div>
                <div id="migrate-table-container" style="display:none;">
                    <table id="migrate-versions-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Версия</th>
                                <th>SHA</th>
                                <th>Дата</th>
                                <th>Состояние</th>
                                <th>Выбор</th>
                            </tr>
                        </thead>
                        <tbody id="migrate-versions-body"></tbody>
                    </table>
                </div>
            </div>
            <div id="migrate-actions" style="display:none;margin-top:1rem;">
                <div style="display:flex;gap:1rem;margin-bottom:1rem;">
                    <div class="form-group" style="flex:1;">
                        <label for="migrate-source">Откуда</label>
                        <select id="migrate-source">
                            <option value="">Выберите...</option>
                        </select>
                    </div>
                    <div style="display:flex;align-items:center;padding-top:0.75rem;color:var(--primary-light);font-size:1.2rem;">➜</div>
                    <div class="form-group" style="flex:1;">
                        <label for="migrate-target">Куда</label>
                        <select id="migrate-target">
                            <option value="">Выберите...</option>
                        </select>
                    </div>
                </div>
                <div class="modal-actions">
                    <button class="btn" id="migrate-execute-btn" onclick="executeMigrate()">🔀 Мигрировать</button>
                    <button class="btn btn-ghost" onclick="closeMigrateModal()">Отмена</button>
                </div>
            </div>
            <div id="migrate-result" style="display:none;">
                <pre id="migrate-log" class="log"></pre>
            </div>
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
                hostsData = json.data;
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
                            '<button class="btn btn-sm btn-ghost" onclick="loadRemotes(\'' + escapeHtml(p.slug) + '\')" style="margin-left:auto;font-size:0.7rem">🔗 Remote</button>' +
                        '</div>' +
                        '<div class="project-desc">' + desc + '</div>' +
                        '<div class="project-actions">' +
                            '<button class="btn btn-sm" onclick="syncOne(\'' + escapeHtml(p.slug) + '\')">🔄 Синхронизировать</button>' +
                            '<button class="btn btn-sm btn-ghost" onclick="openMigrateModal(\'' + escapeHtml(p.slug) + '\')" title="Миграция между хостами">🔀 Миграция</button>' +
                            (showPull ? '<button class="btn btn-sm btn-ghost" onclick="syncPullAll(\'' + escapeHtml(p.slug) + '\')" title="Pull из всех remote">⬇ Все</button>' : '') +
                            (pullButtons ? '<div style="display:flex;gap:0.3rem;flex-wrap:wrap;margin-top:0.3rem">' + pullButtons + '</div>' : '') +
                            '<a href="/' + escapeHtml(p.slug) + '/" class="btn btn-sm btn-ghost">Открыть</a>' +
                        '</div>' +
                        '<div style="margin-top:0.75rem;display:flex;gap:0.5rem;justify-content:flex-end;">' +
                            '<button class="btn btn-sm" style="background:linear-gradient(135deg,#ef4444,#dc2626);box-shadow:0 2px 8px rgba(239,68,68,0.3);" onclick="showDeleteModal(\'' + escapeHtml(p.slug) + '\')">🗑 Удалить</button>' +
                        '</div>' +
                        '<div id="remotes-section-' + escapeHtml(p.slug) + '" style="margin-top:0.75rem"></div>' +
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

        // --- Clone modal functions ------------------------------------------------

        let hostsData = [];

        function openCloneModal() {
            document.getElementById('clone-modal').style.display = 'block';
            document.getElementById('clone-form').style.display = 'block';
            document.getElementById('clone-result').style.display = 'none';
            document.getElementById('clone-form').reset();
            document.getElementById('clone-local').placeholder = '';
            document.getElementById('clone-submit-btn').disabled = false;
            document.getElementById('clone-submit-btn').textContent = 'Клонировать';

            // Заполнить dropdown хостов из уже загруженных данных
            const sel = document.getElementById('clone-host');
            sel.innerHTML = '<option value="">Выберите хост...</option>';
            hostsData.forEach(h => {
                if (h.status === 'ready') {
                    const opt = document.createElement('option');
                    opt.value = h.host;
                    opt.textContent = h.id + ' — ' + h.host;
                    sel.appendChild(opt);
                }
            });
        }

        // --- Remote management functions ------------------------------------------

        async function loadRemotes(slug) {
            const section = document.getElementById('remotes-section-' + slug);
            if (!section) return;

            // Если уже загружено — переключить видимость
            if (section.dataset.loaded === 'true') {
                section.dataset.expanded = section.dataset.expanded === 'true' ? 'false' : 'true';
                section.style.display = section.dataset.expanded === 'true' ? 'block' : 'none';
                return;
            }

            section.dataset.loaded = 'true';
            section.style.display = 'block';
            section.dataset.expanded = 'true';
            section.innerHTML = '<div style="font-size:0.8rem;color:var(--muted);">Загрузка...</div>';

            try {
                const res = await fetch('/api/projects_remotes.php?slug=' + encodeURIComponent(slug));
                const json = await res.json();

                if (!json.ok) {
                    section.innerHTML = '<div style="font-size:0.8rem;color:var(--danger);">Ошибка: ' + escapeHtml(json.error) + '</div>';
                    return;
                }

                const data = json.data;
                const remotes = data.remotes || [];

                if (remotes.length === 0) {
                    section.innerHTML =
                        '<div style="font-size:0.8rem;color:var(--muted);margin-bottom:0.5rem;">Нет настроенных remote</div>' +
                        '<button class="btn btn-sm btn-ghost" onclick="openAddRemoteModal(\'' + slug + '\')">➕ Добавить remote</button>';
                    return;
                }

                let html = '<div style="font-size:0.8rem;color:var(--muted);margin-bottom:0.5rem;">Remote (' + remotes.length + '):</div>';
                html += '<div style="display:flex;flex-direction:column;gap:0.3rem;">';

                remotes.forEach(r => {
                    const statusColor = r.status === 'fresh' ? 'var(--success)' : (r.status === 'empty' ? 'var(--warn)' : 'var(--warn)');
                    html += '<div style="display:flex;justify-content:space-between;align-items:center;padding:0.35rem 0.5rem;background:#120e24;border-radius:6px;font-size:0.78rem;">' +
                        '<div style="display:flex;align-items:center;gap:0.5rem;">' +
                            '<strong style="color:#f3e8ff;">' + escapeHtml(r.name) + '</strong>' +
                            '<span style="color:' + statusColor + ';font-size:0.7rem;">' + escapeHtml(r.status_label) + '</span>' +
                            (r.sha7 !== '—' ? '<span style="color:var(--muted);font-family:monospace;font-size:0.7rem;">' + escapeHtml(r.sha7) + '</span>' : '') +
                        '</div>' +
                        '<div style="display:flex;gap:0.3rem;">' +
                            (r.name !== 'origin' ? '<button class="btn btn-sm btn-ghost" style="padding:0.2rem 0.4rem;font-size:0.65rem;" onclick="removeRemote(\'' + slug + '\', \'' + escapeHtml(r.name) + '\')">✕</button>' : '') +
                        '</div>' +
                    '</div>';
                });

                html += '</div>';
                html += '<button class="btn btn-sm btn-ghost" style="margin-top:0.5rem;font-size:0.7rem;" onclick="openAddRemoteModal(\'' + slug + '\')">➕ Добавить remote</button>';

                section.innerHTML = html;
            } catch (e) {
                section.innerHTML = '<div style="font-size:0.8rem;color:var(--danger);">Ошибка сети: ' + escapeHtml(e.message) + '</div>';
            }
        }

        async function openAddRemoteModal(slug) {
            document.getElementById('clone-modal').style.display = 'block';
            document.getElementById('clone-form').style.display = 'block';
            document.getElementById('clone-result').style.display = 'none';

            // Переключить форму на добавление remote
            const form = document.getElementById('clone-form');
            form.onsubmit = function(e) { doAddRemote(e, slug); };

            document.getElementById('clone-modal').querySelector('h2').textContent = '➕ Добавить remote';
            document.getElementById('clone-submit-btn').textContent = 'Добавить';

            // Заполнить dropdown хостов
            const sel = document.getElementById('clone-host');
            sel.innerHTML = '<option value="">Выберите хост...</option>';
            hostsData.forEach(h => {
                if (h.status === 'ready') {
                    const opt = document.createElement('option');
                    opt.value = h.host;
                    opt.textContent = h.id + ' — ' + h.host;
                    sel.appendChild(opt);
                }
            });

            // Скрыть поле slug, заменить на домен
            const ownerInput = document.getElementById('clone-owner');
            const slugInput = document.getElementById('clone-slug');
            const localInput = document.getElementById('clone-local');

            ownerInput.closest('.form-group').style.display = 'none';
            slugInput.closest('.form-group').style.display = 'none';
            localInput.closest('.form-group').style.display = 'none';

            // Добавить поле домена
            let domainGroup = document.getElementById('clone-domain-group');
            if (!domainGroup) {
                domainGroup = document.createElement('div');
                domainGroup.id = 'clone-domain-group';
                domainGroup.className = 'form-group';
                domainGroup.innerHTML = '<label for="clone-domain">Домен хоста</label><input type="text" id="clone-domain" placeholder="gitverse.ru" required>';
                form.insertBefore(domainGroup, form.querySelector('.modal-actions'));
            }
            domainGroup.style.display = 'block';
            document.getElementById('clone-domain').value = '';
        }

        async function doAddRemote(e, slug) {
            e.preventDefault();

            const domain = document.getElementById('clone-domain').value.trim();
            const owner = document.getElementById('clone-owner').value.trim();

            const btn = document.getElementById('clone-submit-btn');
            btn.disabled = true;
            btn.textContent = '⏳ Добавление...';

            try {
                const res = await fetch('/api/projects_remotes_add.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ slug: slug, domain: domain, owner: owner })
                });
                const json = await res.json();

                document.getElementById('clone-form').style.display = 'none';
                document.getElementById('clone-result').style.display = 'block';
                const logEl = document.getElementById('clone-log');
                logEl.style.display = 'block';

                if (json.ok) {
                    logEl.innerHTML = '<span style="color:var(--success)">✅ Remote добавлен!</span>\n\n' +
                        'Remote: ' + json.data.remote + '\n' +
                        'URL: ' + json.data.url + '\n' +
                        '\n--- Fetch ---\n' + (json.data.fetch_log || 'Готово');
                    // Перезагрузить remote
                    loadRemotes(slug);
                } else {
                    logEl.innerHTML = '<span style="color:var(--danger)">❌ Ошибка</span>\n\n' +
                        (json.error || 'Неизвестная ошибка') +
                        '\n\n--- Лог ---\n' + (json.data?.log || '');
                }
            } catch (err) {
                document.getElementById('clone-form').style.display = 'none';
                document.getElementById('clone-result').style.display = 'block';
                document.getElementById('clone-log').style.display = 'block';
                document.getElementById('clone-log').innerHTML = '<span style="color:var(--danger)">❌ Сетевая ошибка</span>\n\n' + err.message;
            }
        }

        async function removeRemote(slug, remote) {
            const expected = 'DELETE-' + remote.toUpperCase();
            const confirm = prompt('Введите ' + expected + ' для подтверждения:');
            if (confirm !== expected) {
                if (confirm !== null) alert('Подтверждение не совпадает');
                return;
            }

            try {
                const res = await fetch('/api/projects_remotes_remove.php?slug=' + encodeURIComponent(slug) + '&remote=' + encodeURIComponent(remote), {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ confirm: expected })
                });
                const json = await res.json();

                if (json.ok) {
                    alert(json.data.message);
                    loadRemotes(slug);
                } else {
                    alert('❌ ' + (json.error || 'Ошибка'));
                }
            } catch (err) {
                alert('❌ Сетевая ошибка: ' + err.message);
            }
        }

        function closeCloneModal() {
            document.getElementById('clone-modal').style.display = 'none';
            // Сбросить форму клонирования
            const form = document.getElementById('clone-form');
            form.onsubmit = doClone;

            const ownerInput = document.getElementById('clone-owner');
            const slugInput = document.getElementById('clone-slug');
            const localInput = document.getElementById('clone-local');
            ownerInput.closest('.form-group').style.display = 'block';
            slugInput.closest('.form-group').style.display = 'block';
            localInput.closest('.form-group').style.display = 'block';

            const domainGroup = document.getElementById('clone-domain-group');
            if (domainGroup) domainGroup.style.display = 'none';

            document.getElementById('clone-modal').querySelector('h2').textContent = '➕ Клонировать проект';
            document.getElementById('clone-submit-btn').textContent = 'Клонировать';
            document.getElementById('clone-form').reset();
            document.getElementById('clone-local').placeholder = '';
        }

        // --- Delete project functions -----------------------------------------------

        let deleteSlug = '';
        let deleteConfirmCode = '';

        async function showDeleteModal(slug) {
            deleteSlug = slug;
            const modal = document.getElementById('delete-modal');
            modal.style.display = 'block';

            // Сбросить состояние
            document.getElementById('delete-info').style.display = 'block';
            document.getElementById('delete-options').style.display = 'flex';
            document.getElementById('delete-confirm-section').style.display = 'none';
            document.getElementById('delete-result').style.display = 'none';
            document.getElementById('delete-confirm-code').value = '';

            // Показать информацию о проекте
            document.getElementById('delete-info').innerHTML =
                '<strong style="color:var(--danger);">⚠ Внимание!</strong> Будет удалён проект <strong>' + escapeHtml(slug) + '</strong>. Это действие нельзя отменить.';

            // Загрузить remote для этого проекта
            const optionsDiv = document.getElementById('delete-options');
            optionsDiv.innerHTML = '<div style="color:var(--muted);font-size:0.85rem;">Загрузка...</div>';

            try {
                const res = await fetch('/api/projects_remotes.php?slug=' + encodeURIComponent(slug));
                const json = await res.json();

                let html = '';
                html += '<button class="btn" style="background:linear-gradient(135deg,#f59e0b,#d97706);width:100%;text-align:left;" onclick="selectDeleteOption(\'DELETE-LOCAL\', \'Только локально (сохранить на всех хостах)\')">📁 Только локально</button>';

                if (json.ok && json.data && json.data.remotes && json.data.remotes.length > 0) {
                    json.data.remotes.forEach(r => {
                        if (r.name !== 'origin') {
                            html += '<button class="btn btn-ghost" style="width:100%;text-align:left;" onclick="selectDeleteOption(\'DELETE-' + r.name.toUpperCase() + '\', \'С хоста ' + escapeHtml(r.name) + ' (' + escapeHtml(r.host) + ')\')">🌐 С хоста: ' + escapeHtml(r.name) + '</button>';
                        }
                    });
                }

                html += '<button class="btn" style="background:linear-gradient(135deg,#ef4444,#dc2626);width:100%;text-align:left;" onclick="selectDeleteOption(\'DELETE-ALL\', \'Со всех хостов и локально\')">💣 Со всех хостов и локально</button>';

                optionsDiv.innerHTML = html;
            } catch (e) {
                optionsDiv.innerHTML = '<div style="color:var(--danger);font-size:0.85rem;">Ошибка: ' + escapeHtml(e.message) + '</div>';
            }
        }

        function selectDeleteOption(code, label) {
            deleteConfirmCode = code;
            document.getElementById('delete-options').style.display = 'none';
            document.getElementById('delete-confirm-section').style.display = 'block';
            document.getElementById('delete-confirm-code').placeholder = code;
            document.getElementById('delete-confirm-code').value = '';
            document.getElementById('delete-confirm-code').focus();
        }

        async function executeDelete() {
            const code = document.getElementById('delete-confirm-code').value.trim();
            if (code !== deleteConfirmCode) {
                alert('Код подтверждения не совпадает. Ожидается: ' + deleteConfirmCode);
                return;
            }

            const btn = document.getElementById('delete-execute-btn');
            btn.disabled = true;
            btn.textContent = '⏳ Удаление...';

            document.getElementById('delete-confirm-section').style.display = 'none';
            document.getElementById('delete-result').style.display = 'block';
            const logEl = document.getElementById('delete-log');
            logEl.style.display = 'block';
            logEl.textContent = 'Удаление...';

            try {
                const url = '/api/projects_remove.php?slug=' + encodeURIComponent(deleteSlug);
                const res = await fetch(url, {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ confirm: code })
                });
                const json = await res.json();

                if (json.ok) {
                    logEl.innerHTML = '<span style="color:var(--success);">✅ ' + escapeHtml(json.data.message) + '</span>';
                    if (json.data.note) {
                        logEl.innerHTML += '\n\n⚠ ' + escapeHtml(json.data.note);
                    }
                    // Перезагрузить список проектов
                    loadProjects();
                } else {
                    logEl.innerHTML = '<span style="color:var(--danger);">❌ ' + escapeHtml(json.error) + '</span>';
                }
            } catch (err) {
                logEl.innerHTML = '<span style="color:var(--danger);">❌ Сетевая ошибка</span>\n\n' + err.message;
            }
        }

        function closeDeleteModal() {
            document.getElementById('delete-modal').style.display = 'none';
            deleteSlug = '';
            deleteConfirmCode = '';
        }

        // --- Hub multi-push functions -----------------------------------------------

        async function hubMultiPush() {
            const btn = document.getElementById('hub-push-btn');
            const log = document.getElementById('hub-log');
            const content = document.getElementById('hub-status-content');

            btn.disabled = true;
            btn.textContent = '⏳ Пуш...';
            log.style.display = 'block';
            log.textContent = 'Мульти-push хаба на все remote...';
            content.innerHTML = '<div class="empty">Выполняется мульти-push...</div>';

            try {
                const res = await fetch('/api/hub_multi_push.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({})
                });
                const json = await res.json();
                if (json.ok) {
                    content.innerHTML = '<div class="hub-info"><div class="hub-row"><span class="hub-uptodate">✅ Мульти-push завершён</span></div></div>';
                    log.textContent = json.data?.log || 'Готово';
                } else {
                    content.innerHTML = '<div class="empty" style="color:var(--danger);">Ошибка: ' + escapeHtml(json.error) + '</div>';
                    log.textContent = json.data?.log?.join('\n') || json.error;
                }
            } catch (e) {
                content.innerHTML = '<div class="empty" style="color:var(--danger);">Ошибка сети: ' + escapeHtml(e.message) + '</div>';
                log.textContent = 'Ошибка сети: ' + e.message;
            } finally {
                btn.disabled = false;
                btn.textContent = '📤 Мульти-push';
            }
        }

        // --- Migrate functions ------------------------------------------------------

        let migrateSlug = '';
        let migrateVersions = [];
        let migrateSource = '';
        let migrateTarget = '';

        async function openMigrateModal(slug) {
            migrateSlug = slug;
            migrateSource = '';
            migrateTarget = '';
            migrateVersions = [];

            const modal = document.getElementById('migrate-modal');
            modal.style.display = 'block';

            // Сброс
            document.getElementById('migrate-slug-name').textContent = slug;
            document.getElementById('migrate-loading').style.display = 'block';
            document.getElementById('migrate-table-container').style.display = 'none';
            document.getElementById('migrate-actions').style.display = 'none';
            document.getElementById('migrate-result').style.display = 'none';

            // Загрузить таблицу версий
            try {
                const res = await fetch('/api/projects_migrate.php?slug=' + encodeURIComponent(slug));
                const json = await res.json();

                if (!json.ok) {
                    document.getElementById('migrate-loading').innerHTML = '<span style="color:var(--danger);">Ошибка: ' + escapeHtml(json.error) + '</span>';
                    return;
                }

                migrateVersions = json.data.versions || [];

                if (migrateVersions.length === 0) {
                    document.getElementById('migrate-loading').innerHTML = '<span style="color:var(--warn);">Нет данных для миграции (нужен хотя бы один remote)</span>';
                    return;
                }

                // Показать таблицу
                document.getElementById('migrate-loading').style.display = 'none';
                document.getElementById('migrate-table-container').style.display = 'block';

                const tbody = document.getElementById('migrate-versions-body');
                tbody.innerHTML = migrateVersions.map((v, i) => {
                    let stateClass = 'state-empty';
                    let stateIcon = '⚪';
                    if (v.state.includes('свежий') || v.state.includes('fresh')) {
                        stateClass = 'state-fresh';
                        stateIcon = '🟢';
                    } else if (v.state.includes('отстаёт') || v.state.includes('behind')) {
                        stateClass = 'state-behind';
                        stateIcon = '🟡';
                    } else if (v.state.includes('расходится') || v.state.includes('diverged')) {
                        stateClass = 'state-diverged';
                        stateIcon = '🔴';
                    }

                    return '<tr>' +
                        '<td>' + v.num + '</td>' +
                        '<td><strong>' + escapeHtml(v.name) + '</strong></td>' +
                        '<td style="font-family:monospace;font-size:0.8rem;">' + escapeHtml(v.sha.substring(0, 7)) + '</td>' +
                        '<td style="font-size:0.8rem;">' + escapeHtml(v.date) + '</td>' +
                        '<td><span class="' + stateClass + '">' + stateIcon + ' ' + escapeHtml(v.state) + '</span></td>' +
                        '<td><input type="radio" name="migrate-choice" value="' + v.num + '" onchange="onMigrateChoice(this)"></td>' +
                        '</tr>';
                }).join('');

                // Заполнить селекты
                const sourceSel = document.getElementById('migrate-source');
                const targetSel = document.getElementById('migrate-target');
                sourceSel.innerHTML = '<option value="">Выберите...</option>';
                targetSel.innerHTML = '<option value="">Выберите...</option>';

                migrateVersions.forEach((v, i) => {
                    const opt1 = document.createElement('option');
                    opt1.value = v.name;
                    opt1.textContent = v.num + ': ' + v.name;
                    sourceSel.appendChild(opt1);

                    const opt2 = document.createElement('option');
                    opt2.value = v.name;
                    opt2.textContent = v.num + ': ' + v.name;
                    targetSel.appendChild(opt2);
                });

                document.getElementById('migrate-actions').style.display = 'block';
            } catch (e) {
                document.getElementById('migrate-loading').innerHTML = '<span style="color:var(--danger);">Ошибка сети: ' + escapeHtml(e.message) + '</span>';
            }
        }

        function onMigrateChoice(radio) {
            // Определяем, для source или target выбрано
            const name = radio.name; // "migrate-choice"
            const val = radio.value;
            const num = parseInt(val);

            // Находим версию в массиве
            const v = migrateVersions.find(ver => ver.num === num);
            if (!v) return;

            // Если source не выбран — ставим в source
            if (!migrateSource) {
                migrateSource = v.name;
                document.getElementById('migrate-source').value = migrateSource;
            } else if (!migrateTarget) {
                // Если target не выбран и отличается — ставим в target
                if (v.name !== migrateSource) {
                    migrateTarget = v.name;
                    document.getElementById('migrate-target').value = migrateTarget;
                }
            } else {
                // Оба выбраны — сбросить и начать заново
                migrateSource = v.name;
                migrateTarget = '';
                document.getElementById('migrate-source').value = migrateSource;
                document.getElementById('migrate-target').value = '';
            }
        }

        async function executeMigrate() {
            const source = document.getElementById('migrate-source').value;
            const target = document.getElementById('migrate-target').value;

            if (!source || !target) {
                alert('Выберите источник и приёмник');
                return;
            }
            if (source === target) {
                alert('Источник и приёмник не могут совпадать');
                return;
            }

            const btn = document.getElementById('migrate-execute-btn');
            btn.disabled = true;
            btn.textContent = '⏳ Миграция...';

            document.getElementById('migrate-actions').style.display = 'none';
            document.getElementById('migrate-result').style.display = 'block';
            const logEl = document.getElementById('migrate-log');
            logEl.style.display = 'block';
            logEl.textContent = 'Выполняется миграция: ' + source + ' → ' + target + '...';

            try {
                const res = await fetch('/api/projects_migrate.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ slug: migrateSlug, source: source, target: target })
                });
                const json = await res.json();

                if (json.ok) {
                    logEl.innerHTML = '<span style="color:var(--success);">✅ ' + escapeHtml(json.data.message) + '</span>\n\n' + (json.data.log || '');
                    // Перезагрузить список проектов
                    loadProjects();
                } else {
                    let errorMsg = '<span style="color:var(--danger);">❌ ' + escapeHtml(json.error) + '</span>';
                    if (json.error_code === 'SOURCE_BEHIND') {
                        errorMsg += '\n\n⚠ Ваша версия отстаёт от приёмника. Попробуйте обратное направление.';
                    }
                    errorMsg += '\n\n--- Лог ---\n' + (json.data?.log || '');
                    logEl.innerHTML = errorMsg;
                }
            } catch (err) {
                logEl.innerHTML = '<span style="color:var(--danger);">❌ Сетевая ошибка</span>\n\n' + err.message;
            } finally {
                btn.disabled = false;
                btn.textContent = '🔀 Мигрировать';
            }
        }

        function closeMigrateModal() {
            document.getElementById('migrate-modal').style.display = 'none';
            migrateSlug = '';
            migrateSource = '';
            migrateTarget = '';
            migrateVersions = [];
        }

        // Экранирование HTML
        async function doClone(e) {
            e.preventDefault();

            const host = document.getElementById('clone-host').value;
            const owner = document.getElementById('clone-owner').value.trim();
            const slug = document.getElementById('clone-slug').value.trim();
            const localName = document.getElementById('clone-local').value.trim() || slug;

            const btn = document.getElementById('clone-submit-btn');
            btn.disabled = true;
            btn.textContent = '⏳ Клонирование...';

            try {
                const res = await fetch('/api/clone.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ host, owner, slug, local_name: localName })
                });
                const json = await res.json();

                document.getElementById('clone-form').style.display = 'none';
                document.getElementById('clone-result').style.display = 'block';
                const logEl = document.getElementById('clone-log');
                logEl.style.display = 'block';

                if (json.ok) {
                    logEl.innerHTML = '<span style="color:var(--success)">✅ Успешно!</span>\n\n' +
                        'Проект: ' + json.data.slug + '\n' +
                        'Хост: ' + json.data.host + '\n' +
                        'URL: ' + json.data.url + '\n' +
                        '\n--- Лог ---\n' + (json.data.log || 'Готово');
                    // Перезагрузить список проектов
                    loadProjects();
                } else {
                    logEl.innerHTML = '<span style="color:var(--danger)">❌ Ошибка</span>\n\n' +
                        (json.error || 'Неизвестная ошибка') +
                        '\n\n--- Лог ---\n' + (json.data?.log || '');
                }
            } catch (err) {
                document.getElementById('clone-form').style.display = 'none';
                document.getElementById('clone-result').style.display = 'block';
                document.getElementById('clone-log').style.display = 'block';
                document.getElementById('clone-log').innerHTML = '<span style="color:var(--danger)">❌ Сетевая ошибка</span>\n\n' + err.message;
            }
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
