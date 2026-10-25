<?php
declare(strict_types=1);

/**
 * API: информация о проектах
 * GET /api/projects.php?search=...&sort=...&order=...
 *
 * Возвращает список проектов с метаданными:
 *  - name, description (из composer.json)
 *  - branch, commits, last_commit, last_commit_date
 *  - remotes (список удалённых хранилищ)
 *  - dirty (есть ли незакоммиченные изменения)
 *  - sync_status (last_sync_at)
 */

header('Content-Type: application/json; charset=utf-8');

$workspace = dirname(__DIR__, 2);
$projectsDir = "$workspace/projects";
$dataDir = "$workspace/data";

// --- Query params -----------------------------------------------------------
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort = $_GET['sort'] ?? 'name';       // name | commits | last_commit
$order = ($_GET['order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

if (!is_dir($projectsDir)) {
    echo json_encode(['ok' => true, 'data' => [], 'meta' => ['count' => 0]], JSON_UNESCAPED_UNICODE);
    exit;
}

$items = [];

foreach (scandir($projectsDir) as $item) {
    if ($item === '.' || $item === '..') continue;
    $projectPath = "$projectsDir/$item";
    if (!is_dir($projectPath)) continue;
    if (!is_dir("$projectPath/.git")) continue;

    // --- composer.json ------------------------------------------------------
    $name = $item;
    $description = '';
    $composerFile = "$projectPath/composer.json";
    if (is_file($composerFile)) {
        $composer = json_decode(file_get_contents($composerFile), true);
        if (is_array($composer)) {
            if (isset($composer['name']) && is_string($composer['name'])) {
                $name = $composer['name'];
            }
            if (isset($composer['description']) && is_string($composer['description'])) {
                $description = $composer['description'];
            }
        }
    }

    // --- Search filter ------------------------------------------------------
    if ($search !== '') {
        $haystack = strtolower("$item $name $description");
        if (strpos($haystack, strtolower($search)) === false) {
            continue;
        }
    }

    // --- Git info -----------------------------------------------------------
    $branch = '';
    $commits = 0;
    $lastCommit = '';
    $lastCommitDate = '';
    $dirty = false;

    $gitRoot = $projectPath;
    // Определяем корень репозитория (для поддиректорий)
    $gitDir = "$projectPath/.git";
    if (is_file($gitDir)) {
        // Gitlink / submodule — читаем gitdir
        $gitLinkContent = file_get_contents($gitDir);
        if (preg_match('/gitdir:\s*(.+)$/', $gitLinkContent ?? '', $m)) {
            $gitRoot = trim($m[1]);
            // Если путь относительный — относим к projectPath
            if (!str_starts_with($gitRoot, '/')) {
                $gitRoot = dirname($projectPath) . '/' . $gitRoot;
            }
        }
    }

    // Текущая ветка
    $headFile = "$gitRoot/HEAD";
    if (is_file($headFile)) {
        $head = trim(file_get_contents($headFile) ?? '');
        if (str_starts_with($head, 'ref: ')) {
            $refPath = substr($head, 5);
            $refFile = "$gitRoot/$refPath";
            if (is_file($refFile)) {
                $branch = trim(file_get_contents($refFile) ?? '');
            }
        } else {
            $branch = $head; // detached HEAD
        }
    }

    // Количество коммитов (от первого до текущего)
    $output = [];
    $rc = 0;
    @exec(sprintf('git -C %s rev-list --count HEAD 2>&1', escapeshellarg($projectPath)), $output, $rc);
    if ($rc === 0 && is_numeric(implode('', $output))) {
        $commits = (int) implode('', $output);
    }

    // Последний коммит
    $output = [];
    $rc = 0;
    @exec(sprintf(
        'git -C %s log -1 --format="%%h|||%%s|||%%ci" HEAD 2>&1',
        escapeshellarg($projectPath)
    ), $output, $rc);
    if ($rc === 0 && !empty(implode('', $output))) {
        $parts = explode('|||', implode('', $output));
        if (count($parts) >= 3) {
            $lastCommit = $parts[0];
            $lastCommitDate = $parts[2];
        }
    }

    // Dirty status
    $output = [];
    $rc = 0;
    @exec(sprintf('git -C %s status --porcelain 2>&1', escapeshellarg($projectPath)), $output, $rc);
    $dirty = !empty($output);

    // --- Remotes ------------------------------------------------------------
    $remotes = [];
    $output = [];
    $rc = 0;
    @exec(sprintf('git -C %s remote -v 2>&1', escapeshellarg($projectPath)), $output, $rc);
    if ($rc === 0) {
        foreach ($output as $line) {
            if (preg_match('/^(\S+)\t+(\S+)\s+\(fetch\)$/', $line, $m)) {
                $remoteName = $m[1];
                $remoteUrl = $m[2];
                // Определяем хост по URL
                $host = '';
                if (preg_match('#https?://([^/]+)#', $remoteUrl, $um)) {
                    $host = $um[1];
                }
                $remotes[$remoteName] = [
                    'url' => $remoteUrl,
                    'host' => $host,
                ];
            }
        }
    }

    // --- Last sync (из лога) ------------------------------------------------
    $lastSyncAt = '';
    $syncLog = "$dataDir/sync.log";
    if (is_file($syncLog)) {
        $lines = file($syncLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach (array_reverse($lines) as $logLine) {
            $ll = (string) $logLine;
            // Ищем строку вида: [YYYY-MM-DD HH:MM:SS] Project: slug
            if (preg_match('/\[\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}\]\s+.*?' . preg_quote($item, '/') . '/', $ll)) {
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\]/', $ll, $sm)) {
                    $lastSyncAt = $sm[1];
                }
                break;
            }
        }
    }

    // --- Docroot status -----------------------------------------------------
    $docrootStatus = 'unknown';
    $candidates = ['public', 'Project', 'project', 'www', 'web', 'html'];
    foreach ($candidates as $c) {
        if (is_dir("$projectPath/$c") && (is_file("$projectPath/$c/index.php") || is_file("$projectPath/$c/index.html"))) {
            $docrootStatus = $c === 'public' ? 'ok' : 'legacy';
            break;
        }
    }

    $items[] = [
        'slug' => $item,
        'name' => $name,
        'description' => $description,
        'branch' => $branch,
        'commits' => $commits,
        'last_commit' => $lastCommit,
        'last_commit_date' => $lastCommitDate,
        'dirty' => $dirty,
        'remotes' => $remotes,
        'last_sync_at' => $lastSyncAt,
        'docroot' => $docrootStatus,
    ];
}

// --- Sorting ----------------------------------------------------------------
usort($items, function ($a, $b) use ($sort, $order) {
    $result = match ($sort) {
        'commits' => $a['commits'] <=> $b['commits'],
        'last_commit' => strcmp($a['last_commit_date'], $b['last_commit_date']),
        default => strcmp($a['name'], $b['name']),
    };
    return $order === 'desc' ? -$result : $result;
});

echo json_encode([
    'ok' => true,
    'data' => $items,
    'meta' => ['count' => count($items)],
], JSON_UNESCAPED_UNICODE);
