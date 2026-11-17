<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>GitProject Workspace Hub</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<div class="container">

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
  </section>

  <section class="project-actions">
    <div class="action-group">
      <h3 class="action-group-title">Git-операции</h3>
      <div class="action-buttons">
        <button type="button" class="btn btn--primary" disabled title="Скоро: bin/git-project-sync.sh">⬇ Получить версию с Git-сервера</button>
        <button type="button" class="btn" disabled title="Скоро: bin/git-project-sync.sh">⬆ Загрузить локальную версию на Git-сервер</button>
        <button type="button" class="btn" disabled title="Скоро: bin/git-project-migrate.sh">🔀 Merge</button>
      </div>
      <p class="action-hint">Пока выполняется через bin/git-project-sync.sh</p>
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
        <button type="button" class="btn" disabled title="Скоро">📜 Просмотр логов коммитов</button>
        <button type="button" class="btn" disabled title="Скоро">📁 Открыть в файловом менеджере</button>
        <button type="button" class="btn" id="export-project">📦 Экспорт проекта (zip)</button>
      </div>
    </div>
    <div class="action-group">
      <h3 class="action-group-title">Настройки</h3>
      <div class="action-buttons">
        <button type="button" class="btn" disabled title="Скоро">✏️ Изменить описание проекта</button>
        <button type="button" class="btn" disabled title="Скоро">⚙️ Редактировать мета-данные</button>
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
</body>
</html>