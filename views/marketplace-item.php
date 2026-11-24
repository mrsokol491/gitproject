<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
<title><?= $item !== null ? htmlspecialchars($item['slug']) . ' · Маркетплейс' : 'Не найдено · Маркетплейс' ?></title>
<link rel="stylesheet" href="/assets/style.css?v=2">
</head>
<body class="mp-body"<?= $item !== null ? ' data-item-id="' . (int) $item['id'] . '"' : '' ?>>
<div class="mp-shell">

  <header class="mp-top">
    <a href="/marketplace/" class="mp-back">← Маркетплейс</a>
    <div class="mp-top-title">
      <h1><?= $item !== null ? htmlspecialchars($item['slug']) : 'Запись не найдена' ?></h1>
      <?php if ($item !== null): ?><p class="mp-sub"><?= htmlspecialchars($item['owner']) ?> · <?= htmlspecialchars($item['host_name']) ?></p><?php endif; ?>
    </div>
    <?php if ($item !== null): ?>
      <a class="btn" href="<?= htmlspecialchars($item['repo_url']) ?>" target="_blank" rel="noopener noreferrer">↗ На хосте</a>
    <?php endif; ?>
  </header>

  <?php if ($item === null): ?>
    <div class="mp-empty"><p class="mp-empty-icon">🔍</p><p>Запись не найдена или ещё не одобрена.</p></div>
  <?php else: ?>

    <section class="mp-hero">
      <img class="mp-hero-avatar" src="<?= htmlspecialchars($item['avatar']) ?>" alt="" width="96" height="96">
      <div class="mp-hero-main">
        <div class="mp-hero-badges">
          <span class="mp-host <?= htmlspecialchars(mpViewHostClass($item['host_id'])) ?>"><?= htmlspecialchars($item['host_name']) ?></span>
          <span class="mp-cat"><?= htmlspecialchars(mpViewCat($item['category'])) ?></span>
          <?php if ($item['language'] !== null): ?><span class="mp-lang"><?= htmlspecialchars($item['language']) ?></span><?php endif; ?>
          <?php if ($item['license'] !== null && $item['license'] !== '' && $item['license'] !== 'NOASSERTION'): ?><span class="mp-lang"><?= htmlspecialchars($item['license']) ?></span><?php endif; ?>
          <?php if ($item['archived']): ?><span class="mp-arch">архивирован</span><?php endif; ?>
          <?php if ($item['cloned']): ?><span class="mp-cloned">✓ уже у вас</span><?php endif; ?>
        </div>
        <p class="mp-hero-desc"><?= htmlspecialchars($item['description']) ?></p>
        <?php if ($item['editorial_note'] !== ''): ?>
          <p class="mp-note">💡 <?= htmlspecialchars($item['editorial_note']) ?></p>
        <?php endif; ?>
        <div class="mp-stats">
          <span class="mp-stat">⭐ <?= htmlspecialchars(mpViewFmt($item['stars'])) ?></span>
          <span class="mp-stat">⑂ <?= htmlspecialchars(mpViewFmt($item['forks'])) ?></span>
          <?php if ($item['open_issues'] !== null): ?><span class="mp-stat">issues <?= htmlspecialchars(mpViewFmt($item['open_issues'])) ?></span><?php endif; ?>
          <?php if ($item['last_release'] !== null && $item['last_release'] !== ''): ?><span class="mp-stat">релиз <?= htmlspecialchars($item['last_release']) ?></span><?php endif; ?>
          <span class="mp-fresh"><?= htmlspecialchars(mpViewAgo($item['fetched_at'])) ?></span>
        </div>
        <?php if (!$item['host_stats']): ?>
          <p class="mp-stats-note">Счётчики GitFlic недоступны без токена — данные не выдумываются.</p>
        <?php endif; ?>
        <?php if ($item['fetch_error'] !== null): ?>
          <p class="mp-warn">⚠ <?= htmlspecialchars($item['fetch_error']) ?></p>
        <?php endif; ?>
      </div>
      <div class="mp-hero-actions">
        <?php if ($item['cloned'] && $item['project_page'] !== null): ?>
          <a class="btn btn--primary" href="<?= htmlspecialchars($item['project_page']) ?>">Открыть в хабе</a>
        <?php else: ?>
          <button type="button" class="btn btn--primary" id="mp-clone"
                  data-host="<?= htmlspecialchars($item['host_domain']) ?>"
                  data-owner="<?= htmlspecialchars($item['owner']) ?>"
                  data-slug="<?= htmlspecialchars($item['slug']) ?>"
                  data-clone-url="<?= htmlspecialchars($item['clone_url']) ?>">⬇ Клонировать к себе</button>
        <?php endif; ?>
        <button type="button" class="btn btn--ghost" id="mp-copy" data-cmd="git clone <?= htmlspecialchars($item['clone_url']) ?> ~/gitproject/projects/<?= htmlspecialchars($item['slug']) ?>">⧉ Команда clone</button>
        <pre class="mp-log" id="mp-clone-log" hidden></pre>
      </div>
    </section>

    <?php if ($item['topics'] !== [] || $item['tags'] !== []): ?>
      <div class="mp-topics">
        <?php foreach (array_unique(array_merge($item['tags'], $item['topics'])) as $t): ?>
          <span class="mp-topic">#<?= htmlspecialchars((string) $t) ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <article class="mp-readme">
      <?php if ($item['readme_html'] !== ''): ?>
        <?= $item['readme_html'] ?>
      <?php else: ?>
        <p class="mp-empty-hint">README ещё не загружен. Владелец: <code>php bin/marketplace.php refresh --force</code></p>
      <?php endif; ?>
    </article>

  <?php endif; ?>

</div>
<div class="mp-toast" id="mp-toast" hidden></div>
<script type="module" src="/assets/js/marketplace.js"></script>
</body>
</html>