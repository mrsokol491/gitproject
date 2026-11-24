<?php

declare(strict_types=1);

/**
 * Каркас интерфейса v0.3: сайдбар + header + рабочее поле.
 * Рабочее поле переключает 4 mutually-exclusive вида:
 *   home (проекты), hosts (хосты и токены), market (маркетплейс), git-add (клонирование по URL).
 * Входные переменные: $ws_side, $ws_view, $ws_title, $ws_crumb, $ws_pills, $ws_search_ph.
 * Баннер обновлений хаба живёт в сайдбаре под прежними ID (#hub-update и др.),
 * поэтому project-page.js работает без правок.
 */

$ws_side = $ws_side ?? ['projects' => 0, 'market' => 0, 'hosts' => 0];
$ws_view = $ws_view ?? 'home';
$ws_crumb = $ws_crumb ?? 'Workspace / Проекты';
$ws_pills = $ws_pills ?? '';
$ws_search_ph = $ws_search_ph ?? 'Поиск…';
$ws_view_files = [
    'home'    => __DIR__ . '/home.php',
    'hosts'   => __DIR__ . '/hosts.php',
    'market'  => __DIR__ . '/market.php',
    'git-add' => __DIR__ . '/git-add.php',
];
$ws_content = $ws_view_files[$ws_view] ?? $ws_view_files['home'];
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars($csrf ?? '') ?>">
<title><?= htmlspecialchars($ws_title ?? 'GitProject Workspace Hub') ?></title>
<link rel="stylesheet" href="/assets/css/tokens.css?v=1">
<link rel="stylesheet" href="/assets/style.css?v=5">
</head>
<body class="ws-body" data-view="<?= htmlspecialchars($ws_view) ?>">

<aside class="ws-side">
  <div class="ws-brand">🚀 GitProject</div>
  <nav class="ws-nav">
    <div class="ws-sec">Workspace</div>
    <a class="ws-item<?= $ws_view === 'home' ? ' ws-item--on' : '' ?>" href="/?view=home">📁 Проекты <span class="ws-count"><?= (int) $ws_side['projects'] ?></span></a>
    <button type="button" class="ws-item ws-item--action<?= $ws_view === 'git-add' ? ' ws-item--on' : '' ?>" id="nav-git-add">➕ Добавить git</button>
    <div class="ws-sec">Marketplace</div>
    <a class="ws-item<?= $ws_view === 'market' ? ' ws-item--on' : '' ?>" href="/?view=market">🛒 Каталог <span class="ws-count"><?= (int) $ws_side['market'] ?></span></a>
    <div class="ws-sec">System</div>
    <a class="ws-item<?= $ws_view === 'hosts' ? ' ws-item--on' : '' ?>" href="/?view=hosts">🔑 Хосты и токены <span class="ws-count"><?= (int) $ws_side['hosts'] ?></span></a>
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
<script type="module" src="/assets/js/project-page.js?v=6"></script>
<script type="module" src="/assets/js/workspace.js?v=2"></script>
</body>
</html>