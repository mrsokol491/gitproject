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
    <p class="sub">Рабочее пространство @sokol491</p>
  </header>

  <?php if ($projects === []): ?>
    <div class="empty">
      <p>В projects/ пока нет репозиториев.</p>
    </div>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($projects as $p): ?>
        <a class="card" href="<?= htmlspecialchars($p['entry_point']) ?>">
          <div class="card-head">
            <span class="card-name">
              <span class="dot <?= $p['dot'] ?>"></span>
              <?= htmlspecialchars($p['name']) ?>
            </span>
            <span class="badge badge--<?= str_replace(['.', ' '], '-', strtolower($p['type'])) ?>"><?= htmlspecialchars($p['type']) ?></span>
          </div>
          <?php if ($p['description'] !== ''): ?>
            <div class="card-desc"><?= htmlspecialchars($p['description']) ?></div>
          <?php endif; ?>
          <div class="card-meta">
            <span>📅 <?= htmlspecialchars($p['modified']) ?></span>
            <span>📦 <?= $p['size_kb'] ?> KB</span>
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
    <div class="info-row">
      <span class="info-label">Тип</span>
      <span class="info-value"><?= htmlspecialchars($project['type']) ?></span>
    </div>
    <?php if ($project['description'] !== ''): ?>
    <div class="info-row">
      <span class="info-label">Описание</span>
      <span class="info-value"><?= htmlspecialchars($project['description']) ?></span>
    </div>
    <?php endif; ?>
    <?php if ($project['docroot'] !== null): ?>
    <div class="info-row">
      <span class="info-label">Docroot</span>
      <span class="info-value"><?= htmlspecialchars($project['docroot']) ?>/</span>
    </div>
    <?php endif; ?>
    <div class="info-row">
      <span class="info-label">Размер</span>
      <span class="info-value"><?= $project['size_kb'] ?> KB</span>
    </div>
    <div class="info-row">
      <span class="info-label">Обновлено</span>
      <span class="info-value"><?= htmlspecialchars($project['modified']) ?></span>
    </div>
    <hr class="info-divider">
    <div class="version-row">
      <div class="version-item">
        <span class="info-label">Локальная</span>
        <span class="info-value version-tag"><?= htmlspecialchars($project['local_version'] ?? '—') ?></span>
      </div>
      <div class="version-item">
        <span class="info-label">Git-сервер</span>
        <span class="info-value version-tag"><?= htmlspecialchars($project['git_version'] ?? '—') ?></span>
      </div>
    </div>
    <div class="sync-status">
      <?php if ($project['synced'] === true): ?>
        <span class="sync-icon sync-ok">✅ Синхронизировано</span>
      <?php else: ?>
        <span class="sync-icon sync-warn">⚠️ Есть обновления</span>
      <?php endif; ?>
    </div>
  </section>

  <section class="project-actions">
    <div class="action-group">
      <h3 class="action-group-title">Git-операции</h3>
      <div class="action-buttons">
        <a href="<?= htmlspecialchars($project['entry_point']) ?>" class="btn btn--primary">Открыть проект</a>
      </div>
    </div>
    <div class="action-group">
      <h3 class="action-group-title">Просмотр</h3>
      <div class="action-buttons">
        <a href="<?= htmlspecialchars($project['entry_point']) ?>" class="btn" target="_blank">Визуальное отображение</a>
      </div>
    </div>
    <div class="action-group">
      <h3 class="action-group-title">Информация</h3>
      <div class="action-buttons">
        <a href="#" class="btn">Логи коммитов</a>
        <a href="<?= htmlspecialchars($project['entry_point']) ?>" class="btn">Файловый менеджер</a>
      </div>
    </div>
    <div class="action-group">
      <h3 class="action-group-title">Настройки</h3>
      <div class="action-buttons">
        <a href="#" class="btn">Изменить описание</a>
        <a href="#" class="btn">Редактировать мета-данные</a>
      </div>
    </div>
  </section>

<?php endif; ?>

</div>
</body>
</html>
