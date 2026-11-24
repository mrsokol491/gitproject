<?php

declare(strict_types=1);

/**
 * Каркас интерфейса v0.2: сайдбар 260px + header 60px + main.
 * Входные переменные: $ws_side, $ws_page, $ws_title, $ws_crumb, $ws_pills,
 * $ws_search_ph, $ws_content (путь к шаблону контента), $csrf.
 * Баннер обновлений хаба живёт в сайдбаре под прежними ID (#hub-update и др.),
 * поэтому project-page.js работает без правок.
 */

$ws_side = $ws_side ?? ['projects' => 0, 'market' => 0, 'pending' => 0, 'hosts' => 0];
$ws_crumb = $ws_crumb ?? 'Workspace / Проекты';
$ws_pills = $ws_pills ?? '';
$ws_search_ph = $ws_search_ph ?? 'Поиск…';
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars($csrf ?? '') ?>">
<title><?= htmlspecialchars($ws_title ?? 'GitProject Workspace Hub') ?></title>
<link rel="stylesheet" href="/assets/css/tokens.css?v=1">
<link rel="stylesheet" href="/assets/style.css?v=3">
</head>
<body class="ws-body">

<aside class="ws-side">
  <div class="ws-brand">🚀 GitProject</div>
  <nav class="ws-nav">
    <div class="ws-sec">Workspace</div>
    <a class="ws-item<?= ($ws_page ?? '') === 'home' ? ' ws-item--on' : '' ?>" href="/">📁 Проекты <span class="ws-count"><?= (int) $ws_side['projects'] ?></span></a>
    <div class="ws-sec">Marketplace</div>
    <a class="ws-item" href="/marketplace/">🛒 Каталог <span class="ws-count"><?= (int) $ws_side['market'] ?></span></a>
    <a class="ws-item" href="/marketplace/">📥 На модерации <span class="ws-count"><?= (int) $ws_side['pending'] ?></span></a>
    <div class="ws-sec">System</div>
    <button type="button" class="ws-item" id="hosts-toggle">🔑 Хосты и токены <span class="ws-count"><?= (int) $ws_side['hosts'] ?></span></button>
    <a class="ws-item" href="/panel.php">🛠 Панель управления</a>
  </nav>
  <div class="ws-hub" id="hub-update">
    <div class="ws-hub-title">Hub</div>
    <div class="ws-hub-text" id="hub-update-text">Загрузка…</div>
    <div class="hub-update-buttons ws-hub-btns">
      <button type="button" class="btn btn--primary ws-hub-btn" id="hub-check" title="Проверить обновления">🔍</button>
      <button type="button" class="btn ws-hub-btn" id="hub-stable" hidden title="Обновить до стабильной">⬇</button>
      <button type="button" class="btn btn--danger ws-hub-btn" id="hub-edge" hidden title="Edge (на свой риск)">⚠</button>
    </div>
    <pre class="hub-log" id="hub-log" hidden></pre>
  </div>
</aside>

<div class="ws-main">
  <header class="ws-head">
    <div class="ws-crumb"><?= $ws_crumb ?></div>
    <div class="ws-pills" id="ws-pills"><?= $ws_pills ?></div>
    <div class="ws-head-right">
      <input type="search" class="ws-search" id="ws-search" placeholder="<?= htmlspecialchars($ws_search_ph) ?>" maxlength="80">
      <a class="ws-gear" href="/panel.php" title="Панель управления">⚙</a>
      <span class="ws-user">@sokol491</span>
    </div>
  </header>
  <main class="ws-content">
<?php include $ws_content; ?>
  </main>
</div>

<div class="mp-toast" id="mp-toast" hidden></div>
<script type="module" src="/assets/js/project-page.js?v=5"></script>
<script type="module" src="/assets/js/workspace.js?v=1"></script>
</body>
</html>