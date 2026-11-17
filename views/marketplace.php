<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
<title>Маркетплейс · GitProject</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body class="mp-body">
<div class="mp-shell">

  <header class="mp-top">
    <a href="/" class="mp-back">← Хаб</a>
    <div class="mp-top-title">
      <h1>🛒 Маркетплейс</h1>
      <p class="mp-sub">Курируемые решения для разработчиков · <?= (int) $total ?> в каталоге</p>
    </div>
    <button type="button" class="btn btn--primary" id="mp-open-submit">＋ Предложить</button>
  </header>

  <section class="mp-submit" id="mp-submit" hidden>
    <h2 class="mp-submit-title">Предложить проект</h2>
    <p class="mp-submit-hint">Публичная ссылка на GitHub, GitVerse или GitFlic. Владелец проверит и одобрит через CLI.</p>
    <form id="mp-submit-form" class="mp-submit-form">
      <input type="url" name="url" id="mp-url" required maxlength="300" placeholder="https://github.com/owner/repo" class="mp-input">
      <select name="category" id="mp-cat" class="mp-input">
        <?php foreach ($categories as $c): ?>
          <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars(mpViewCat($c)) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="note" id="mp-note" maxlength="500" placeholder="Чем гениально (до 500 символов)" class="mp-input">
      <button type="submit" class="btn btn--primary" id="mp-send">Отправить</button>
    </form>
    <p class="mp-msg" id="mp-submit-msg" role="status"></p>
  </section>

  <form method="get" action="/marketplace/" class="mp-filters" id="mp-filters">
    <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Поиск…" class="mp-input mp-search" maxlength="80">
    <select name="cat" class="mp-input">
      <option value="">Все категории</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= htmlspecialchars($c) ?>"<?= $cat === $c ? ' selected' : '' ?>><?= htmlspecialchars(mpViewCat($c)) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="host" class="mp-input">
      <option value="">Все хосты</option>
      <?php foreach ($hosts as $hid => $h): ?>
        <option value="<?= htmlspecialchars($hid) ?>"<?= $host === $hid ? ' selected' : '' ?>><?= htmlspecialchars($h['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="lang" class="mp-input">
      <option value="">Все языки</option>
      <?php foreach ($languages as $l): ?>
        <option value="<?= htmlspecialchars((string) $l) ?>"<?= $lang === $l ? ' selected' : '' ?>><?= htmlspecialchars((string) $l) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="sort" class="mp-input" id="mp-sort">
      <option value="stars"<?= $sort === 'stars' ? ' selected' : '' ?>>По звёздам</option>
      <option value="updated"<?= $sort === 'updated' ? ' selected' : '' ?>>По свежести</option>
      <option value="added"<?= $sort === 'added' ? ' selected' : '' ?>>Недавно добавленные</option>
    </select>
    <button type="submit" class="btn">Применить</button>
    <?php if ($cat !== '' || $host !== '' || $lang !== '' || $q !== ''): ?>
      <a href="/marketplace/" class="btn btn--ghost">Сбросить</a>
    <?php endif; ?>
  </form>

  <?php if ($items === []): ?>
    <div class="mp-empty">
      <p class="mp-empty-icon">🗂</p>
      <p>Каталог пуст под текущий фильтр.</p>
      <p class="mp-empty-hint">Первый запуск: <code>php bin/marketplace.php import &amp;&amp; php bin/marketplace.php refresh --force</code></p>
    </div>
  <?php else: ?>
    <div class="mp-grid">
      <?php foreach ($items as $it): ?>
        <a class="mp-card<?= $it['archived'] ? ' mp-card--archived' : '' ?>" href="<?= htmlspecialchars($it['page']) ?>">
          <div class="mp-card-head">
            <img class="mp-avatar" src="<?= htmlspecialchars($it['avatar']) ?>" alt="" width="56" height="56" loading="lazy">
            <div class="mp-card-names">
              <span class="mp-card-name"><?= htmlspecialchars($it['slug']) ?></span>
              <span class="mp-card-owner"><?= htmlspecialchars($it['owner']) ?></span>
            </div>
            <span class="mp-host <?= htmlspecialchars(mpViewHostClass($it['host_id'])) ?>"><?= htmlspecialchars($it['host_name']) ?></span>
          </div>
          <p class="mp-card-desc"><?= htmlspecialchars($it['description'] !== '' ? $it['description'] : 'Описание появится после загрузки README.') ?></p>
          <div class="mp-card-tags">
            <span class="mp-cat"><?= htmlspecialchars(mpViewCat($it['category'])) ?></span>
            <?php if ($it['language'] !== null): ?><span class="mp-lang"><?= htmlspecialchars($it['language']) ?></span><?php endif; ?>
            <?php if ($it['cloned']): ?><span class="mp-cloned">✓ у вас</span><?php endif; ?>
            <?php if ($it['archived']): ?><span class="mp-arch">архив</span><?php endif; ?>
          </div>
          <div class="mp-card-foot">
            <span class="mp-stat" title="Звёзды">⭐ <?= htmlspecialchars(mpViewFmt($it['stars'])) ?></span>
            <span class="mp-stat" title="Форки">⑂ <?= htmlspecialchars(mpViewFmt($it['forks'])) ?></span>
            <span class="mp-fresh"><?= htmlspecialchars(mpViewAgo($it['fetched_at'])) ?></span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
      <nav class="mp-pager">
        <?php
        $base = ['cat' => $cat, 'host' => $host, 'lang' => $lang, 'q' => $q, 'sort' => $sort];
        for ($p = 1; $p <= $pages; $p++):
            $qs = http_build_query(array_filter($base, static fn($v): bool => $v !== '') + ['page' => $p]);
        ?>
          <a class="mp-page<?= $p === $page ? ' mp-page--on' : '' ?>" href="/marketplace/?<?= htmlspecialchars($qs) ?>"><?= $p ?></a>
        <?php endfor; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>

  <footer class="mp-foot">
    <span>Данные из публичных API и README, кэш 24 ч. GitFlic — без счётчиков (API требует токен).</span>
    <?php if ($pendingCount > 0): ?><span class="mp-pending">На модерации: <?= (int) $pendingCount ?> · <code>php bin/marketplace.php list --pending</code></span><?php endif; ?>
  </footer>

</div>
<div class="mp-toast" id="mp-toast" hidden></div>
<script type="module" src="/assets/js/marketplace.js"></script>
</body>
</html>