<?php

declare(strict_types=1);

/**
 * GitProject Workspace Hub — главная страница хаба.
 * Сканирует ТОЛЬКО контейнер projects/ (репозитории других проектов);
 * служебные папки хаба (bin/, data/, assets/) в список не попадают.
 * Вызывается из router.php при запросе "/".
 */

/**
 * Размер каталога в байтах, исключая .git, vendor, node_modules.
 */
function dirSize(string $dir): int
{
    $size = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (str_contains($path, '/.git/')
            || str_contains($path, '/vendor/')
            || str_contains($path, '/node_modules/')) {
            continue;
        }
        if ($file->isFile()) {
            $size += (int) $file->getSize();
        }
    }
    return $size;
}

$workspaceRoot = __DIR__;
$projectsRoot  = $workspaceRoot . '/projects';

// Порядок поиска docroot: public/ (STANDARD) первым, legacy — временно,
// до миграции проектов на каркас (STANDARD.md §3, §9).
$docrootCandidates = ['public', 'Project', 'project', 'www', 'web', 'html'];

$projects = [];

if (is_dir($projectsRoot)) {
    foreach (scandir($projectsRoot) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $root = $projectsRoot . '/' . $entry;
        if (!is_dir($root)) {
            continue;
        }

        // Docroot и точка входа
        $docroot    = null;
        $entryPoint = null;
        foreach ($docrootCandidates as $candidate) {
            $dir = $root . '/' . $candidate;
            if (!is_dir($dir)) {
                continue;
            }
            foreach (['index.php', 'index.html'] as $idx) {
                if (is_file($dir . '/' . $idx)) {
                    $docroot    = $candidate;
                    $entryPoint = '/' . rawurlencode($entry) . '/';
                    break 2;
                }
            }
        }

        // Метаданные проекта
        $type = 'unknown';
        $desc = '';
        if (is_file($root . '/composer.json')) {
            $type = 'PHP';
            $data = json_decode((string) file_get_contents($root . '/composer.json'), true);
            if (is_array($data)) {
                $desc = (string) ($data['description'] ?? '');
            }
        } elseif (is_file($root . '/package.json')) {
            $type = 'Node.js';
        }

        // Индикатор: ok = public/, warn = legacy, bad = нет docroot
        $dot = 'bad';
        if ($docroot === 'public') {
            $dot = 'ok';
        } elseif ($docroot !== null) {
            $dot = 'warn';
        }

        $projects[] = [
            'name'        => $entry,
            'type'        => $type,
            'description' => $desc,
            'docroot'     => $docroot,
            'dot'         => $dot,
            'entry_point' => $entryPoint,
            'modified'    => date('Y-m-d H:i', (int) filemtime($root)),
            'size_kb'     => intdiv(dirSize($root), 1024),
        ];
    }
}

usort($projects, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
?>
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
  <h1>🚀 GitProject Workspace Hub</h1>
  <p class="sub">Рабочее пространство @sokol491 — репозитории в контейнере <code>projects/</code></p>

  <div class="info">
    <span>Server:</span> <?= htmlspecialchars($_SERVER['SERVER_ADDR'] ?? '0.0.0.0') ?>:<?= htmlspecialchars((string) ($_SERVER['SERVER_PORT'] ?? '8000')) ?><br>
    <span>Container:</span> <?= htmlspecialchars($projectsRoot) ?><br>
    <span>Projects:</span> <?= count($projects) ?> ·
    <span>PHP:</span> <?= PHP_VERSION ?>
  </div>

  <?php if ($projects === []): ?>
    <div class="empty">
      <p>📂 В <code>projects/</code> пока нет репозиториев.</p>
      <p style="margin-top:1rem">Клонируйте репозиторий:</p>
      <code>cd <?= htmlspecialchars($projectsRoot) ?> && git clone https://gitflic.ru/project/sokol491/&lt;repo&gt;.git</code>
    </div>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($projects as $p): ?>
        <?php if ($p['entry_point'] !== null): ?>
          <a class="card" href="<?= htmlspecialchars($p['entry_point']) ?>">
        <?php else: ?>
          <div class="card disabled">
        <?php endif; ?>

            <div class="card-head">
              <span class="card-name">
                <span class="dot <?= $p['dot'] ?>"></span>
                <?= htmlspecialchars($p['name']) ?>
              </span>
              <span class="badge"><?= htmlspecialchars($p['type']) ?></span>
            </div>
            <div class="card-desc">
              <?= $p['description'] !== '' ? htmlspecialchars($p['description']) : '<i>нет описания</i>' ?>
            </div>
            <div class="card-meta">
              <span>📅 <?= htmlspecialchars($p['modified']) ?></span>
              <span>📦 <?= $p['size_kb'] ?> KB</span>
              <?php if ($p['docroot'] === 'public'): ?>
                <span style="color:#22c55e">docroot: public/</span>
              <?php elseif ($p['docroot'] !== null): ?>
                <span style="color:#f59e0b">legacy: <?= htmlspecialchars($p['docroot']) ?>/ — нужна миграция</span>
              <?php else: ?>
                <span style="color:#ef4444">⚠ нет точки входа</span>
              <?php endif; ?>
            </div>

        <?php if ($p['entry_point'] !== null): ?>
          </a>
        <?php else: ?>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
