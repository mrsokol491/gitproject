<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>GitProject Workspace Hub</title>
<link rel="stylesheet" href="/assets/style.css?v=2">
</head>
<body data-slug="<?= htmlspecialchars($project['name'] ?? '') ?>" data-remote="<?= htmlspecialchars($project['git_remote'] ?? '') ?>">
<div class="container">
  <section class="hub-update" id="hub-update" hidden>
    <div class="hub-update-text" id="hub-update-text"></div>
    <div class="hub-update-buttons">
      <button type="button" class="btn btn--primary" id="hub-check">🔍 Проверить обновления</button>
      <button type="button" class="btn" id="hub-stable" hidden>⬇ Стабильная</button>
      <button type="button" class="btn btn--danger" id="hub-edge" hidden>⚠ Edge (риск)</button>
    </div>
    <pre class="hub-log" id="hub-log" hidden></pre>
  </section>
<?php if ($project === null): ?>

  <header class="header">
    <h1>GitProject Workspace Hub</h1>
    <p class="sub">Рабочее пространство @sokol491 · <a class="header-link" href="/marketplace/">🛒 Маркетплейс</a></p>
  </header>

  <?php if ($projects === []): ?>
    <div class="empty">
      <p>📂 В projects/ пока нет репозиториев.</p>
    </div>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($projects as $p): ?>
        <a class="card" href="<?= htmlspecialchars($p['page_url']) ?>">
          <div class="card-head">
            <span class="card-name">
              <span class="dot dot--<?= $p['dot'] ?>"></span>
              <?= htmlspecialchars($p['name']) ?>
            </span>
            <span class="badge badge--<?= str_replace(['.', ' '], '-', strtolower($p['type'])) ?>"><?= htmlspecialchars($p['type']) ?></span>
          </div>
          <?php if ($p['description'] !== ''): ?>
            <div class="card-desc"><?= htmlspecialchars($p['description']) ?></div>
          <?php endif; ?>
          <div class="card-meta">
            <span>📅 <?= htmlspecialchars($p['modified']) ?></span>
            <span>📦 <?= htmlspecialchars($p['size_human']) ?></span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php else: ?>

  <header class="project-header">
    <a href="/" class="project-back">← Назад</a>
    <h1 class="project-title">
      <span class="dot dot--<?= $project['dot'] ?>"></span>
      <?= htmlspecialchars($project['name']) ?>
    </h1>
  </header>

  <section class="hub-update" id="proj-update" hidden>
    <div class="hub-update-text" id="proj-update-text"></div>
    <div class="hub-update-buttons">
      <button type="button" class="btn btn--primary" id="proj-check">🔍 Проверить сервер</button>
      <button type="button" class="btn" id="proj-pull" hidden>⬇ Обновить из сервера</button>
      <button type="button" class="btn" id="proj-push" hidden>⬆ Опубликовать на сервер</button>
    </div>
    <pre class="hub-log" id="proj-log" hidden></pre>
  </section>

  <section class="project-info">
    <?php if ($project['description'] !== ''): ?>
    <div class="info-row">
      <span class="info-label">Описание</span>
      <span class="info-value"><?= htmlspecialchars($project['description']) ?></span>
    </div>
    <?php endif; ?>
    <div class="info-row">
      <span class="info-label">Тип</span>
      <span class="info-value"><?= htmlspecialchars($project['type']) ?></span>
    </div>
    <?php if ($project['version'] !== null): ?>
    <div class="info-row">
      <span class="info-label">Версия</span>
      <span class="info-value"><?= htmlspecialchars($project['version']) ?></span>
    </div>
    <?php endif; ?>
    <div class="info-row">
      <span class="info-label">Docroot</span>
      <span class="info-value"><?= $project['docroot'] !== null ? htmlspecialchars($project['docroot']) . '/' : 'none' ?></span>
    </div>
    <div class="info-row">
      <span class="info-label">Размер</span>
      <span class="info-value"><?= htmlspecialchars($project['size_human']) ?></span>
    </div>
    <div class="info-row">
      <span class="info-label">Обновлено</span>
      <span class="info-value"><?= htmlspecialchars($project['modified_full']) ?></span>
    </div>
    <hr class="info-divider">
    <div class="version-row">
      <div class="version-item">
        <span class="info-label">Локальная<?= $project['branch'] !== null ? ' (' . htmlspecialchars($project['branch']) . ')' : '' ?></span>
        <span class="info-value version-tag"><?= htmlspecialchars($project['local_version'] ?? '—') ?><?= $project['local_date'] !== null ? ' · ' . htmlspecialchars($project['local_date']) : '' ?></span>
      </div>
      <div class="version-item">
        <span class="info-label">Git-сервер<?= $project['git_remote'] !== null ? ' (' . htmlspecialchars($project['git_remote']) . ')' : '' ?></span>
        <span class="info-value version-tag"><?= htmlspecialchars($project['git_version'] ?? '—') ?><?= $project['git_date'] !== null ? ' · ' . htmlspecialchars($project['git_date']) : '' ?></span>
      </div>
    </div>
    <div class="sync-status">
      <?php if ($project['synced'] === true): ?>
        <span class="sync-icon sync-ok">✅ Синхронизировано</span>
      <?php elseif ($project['synced'] === false): ?>
        <span class="sync-icon sync-warn">⚠️ Есть обновления</span>
      <?php else: ?>
        <span class="sync-icon sync-none">⚪ Нет данных о сервере (выполните fetch)</span>
      <?php endif; ?>
    </div>
    <p class="mp-stats-note">Панель ниже показывает состояние последнего fetch. Актуальное сравнение с сервером — «🔍 Проверить сервер» выше.</p>
  </section>

  <section class="project-actions">
    <div class="action-group">
      <h3 class="action-group-title">Git-операции</h3>
      <div class="action-buttons">
        <button type="button" class="btn btn--primary" data-op="pull">⬇ Получить версию с Git-сервера</button>
        <button type="button" class="btn" data-op="push">⬆ Загрузить локальную версию на Git-сервер</button>
        <button type="button" class="btn" data-op="merge">🔀 Merge (все remote)</button>
        <button type="button" class="btn" id="git-status">📊 Статус репозитория</button>
      </div>
      <div class="status-box" id="status-box" hidden></div>
      <pre class="hub-log" id="git-log" hidden></pre>
    </div>
    <div class="action-group">
      <h3 class="action-group-title">Просмотр</h3>
      <div class="action-buttons">
        <?php if ($project['entry_point'] !== null): ?>
          <a href="<?= htmlspecialchars($project['entry_point']) ?>" class="btn" target="_blank" rel="noopener">🌐 Открыть проект для визуального отображения</a>
        <?php else: ?>
          <button type="button" class="btn" disabled title="Нет docroot с index">🌐 Открыть проект для визуального отображения</button>
        <?php endif; ?>
      </div>
    </div>
    <div class="action-group">
      <h3 class="action-group-title">Информация</h3>
      <div class="action-buttons">
        <button type="button" class="btn" id="show-commits">📜 Просмотр логов коммитов</button>
        <button type="button" class="btn" id="export-project">📦 Экспорт проекта (zip)</button>
        <button type="button" class="btn" id="fm-open">📁 Файловый менеджер</button>
      </div>
      <div class="commits-list" id="commits-list" hidden></div>
      <div class="fm-box" id="fm-box" hidden>
        <div class="fm-crumbs" id="fm-crumbs"></div>
        <button type="button" class="btn fm-back" id="fm-back" hidden>← К списку</button>
        <div class="fm-list" id="fm-list"></div>
        <pre class="fm-view" id="fm-view" hidden></pre>
      </div>
    </div>
    <div class="action-group">
      <h3 class="action-group-title">Настройки</h3>
      <div class="action-buttons">
        <button type="button" class="btn" id="edit-desc">✏️ Изменить описание проекта</button>
        <button type="button" class="btn" id="edit-meta">⚙️ Редактировать мета-данные</button>
      </div>
      <div class="desc-form" id="desc-form" hidden>
        <textarea id="desc-input" rows="3" maxlength="500"><?= htmlspecialchars($project['description']) ?></textarea>
        <button type="button" class="btn btn--primary" id="save-desc">Сохранить</button>
      </div>
      <div class="desc-form" id="meta-form" hidden>
        <input type="text" id="meta-version" maxlength="50" placeholder="Версия (1.2.3)">
        <input type="url" id="meta-homepage" maxlength="200" placeholder="Homepage (https://…)">
        <input type="text" id="meta-license" maxlength="100" placeholder="Лицензия (MIT)">
        <input type="text" id="meta-keywords" maxlength="1100" placeholder="Keywords через запятую">
        <button type="button" class="btn btn--primary" id="save-meta">Сохранить мета-данные</button>
      </div>
    </div>
        <div class="action-group action-group--danger">
      <h3 class="action-group-title">Опасная зона</h3>
      <div class="action-buttons">
        <button type="button" class="btn btn--danger" id="delete-local">🗑 Удалить локально</button>
      </div>
      <p class="action-hint">Удаление с хостов — через /panel.php (там выбор remote). Локальное удаление необратимо.</p>
    </div>
  </section>

<?php endif; ?>

</div>
<script type="module" src="/assets/js/project-page.js?v=4"></script>
</body>
</html>