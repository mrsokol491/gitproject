<?php
declare(strict_types=1);

/**
 * API: список remote проекта
 * GET /api/projects/:slug/remotes.php
 *
 * Возвращает таблицу remote с SHA, датой, статусом актуальности.
 */

header('Content-Type: application/json; charset=utf-8');

function strip_ansi(string $text): string {
    return preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $text) ?? $text;
}

$workspace = dirname(__DIR__, 2);
$projectsDir = "$workspace/projects";

$slug = $_GET['slug'] ?? '';
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректный slug', 'error_code' => 'INVALID_SLUG'], JSON_UNESCAPED_UNICODE);
    exit;
}

$repo = "$projectsDir/$slug";
if (!is_dir("$repo/.git")) {
    echo json_encode(['ok' => false, 'error' => 'Проект не найден или не git-репозиторий', 'error_code' => 'PROJECT_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Текущая ветка
$branchOutput = [];
exec('git -C ' . escapeshellarg($repo) . ' branch --show-current 2>&1', $branchOutput, $branchRc);
$branch = trim(implode('', $branchOutput));
if ($branch === '') {
    echo json_encode(['ok' => false, 'error' => 'Не определена текущая ветка', 'error_code' => 'NO_BRANCH'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Список remote
$remoteOutput = [];
exec('git -C ' . escapeshellarg($repo) . ' remote 2>&1', $remoteOutput, $remoteRc);
$remoteNames = array_filter(array_map('trim', $remoteOutput));
$remoteNames = array_values($remoteNames);

if (empty($remoteNames)) {
    echo json_encode(['ok' => true, 'data' => ['branch' => $branch, 'remotes' => []]], JSON_UNESCAPED_UNICODE);
    exit;
}

$remotes = [];
$allShas = [];

foreach ($remoteNames as $rname) {
    $urlOutput = [];
    exec('git -C ' . escapeshellarg($repo) . ' remote get-url ' . escapeshellarg($rname) . ' 2>&1', $urlOutput, $urlRc);
    $url = trim(implode('', $urlOutput));

    $sha = '';
    $date = '';
    $status = 'empty';

    if (exec('git -C ' . escapeshellarg($repo) . ' show-ref --verify --quiet "refs/remotes/' . escapeshellarg($rname) . '/' . escapeshellarg($branch) . '" 2>/dev/null', $_dummy, $refRc)) {
        $shaOutput = [];
        exec('git -C ' . escapeshellarg($repo) . ' rev-parse "refs/remotes/' . escapeshellarg($rname) . '/' . escapeshellarg($branch) . '" 2>&1', $shaOutput, $shaRc);
        $sha = trim(implode('', $shaOutput));

        if ($sha !== '') {
            $dateOutput = [];
            exec('git -C ' . escapeshellarg($repo) . ' log -1 --format=\'%ci\' ' . escapeshellarg($sha) . ' 2>&1', $dateOutput, $dateRc);
            $date = trim(implode('', $dateOutput));

            $allShas[$rname] = $sha;
        }
    }

    // Извлечь host из URL
    $host = '';
    if (preg_match('#https?://([^/]+)#', $url, $um)) {
        $host = $um[1];
    }

    $remotes[] = [
        'name' => $rname,
        'url'  => $url,
        'host' => $host,
        'sha'  => $sha,
        'sha7' => $sha ? substr($sha, 0, 7) : '—',
        'date' => $date,
        'status' => $status, // пока placeholder, пересчитаем ниже
    ];
}

// Найти самый свежий коммит по дате
$latestSha = '';
$latestDate = '';
foreach ($allShas as $rname => $sha) {
    $dateForRname = '';
    foreach ($remotes as $r) {
        if ($r['name'] === $rname && $r['date'] !== '') {
            $dateForRname = $r['date'];
            break;
        }
    }
    if ($dateForRname !== '' && ($latestDate === '' || $dateForRname > $latestDate)) {
        $latestSha = $sha;
        $latestDate = $dateForRname;
    }
}

// Пересчитать статусы
foreach ($remotes as &$r) {
    if ($r['sha'] === '') {
        $r['status'] = 'empty';
        $r['status_label'] = 'не инициализирован';
        $r['status_class'] = 'badge-integrating';
    } elseif ($r['sha'] === $latestSha) {
        $r['status'] = 'fresh';
        $r['status_label'] = 'актуален';
        $r['status_class'] = 'badge-ready';
    } else {
        // Посчитать behind
        $behindOutput = [];
        exec(
            'git -C ' . escapeshellarg($repo) . ' rev-list --count ' . escapeshellarg($r['sha']) . '..' . escapeshellarg($latestSha) . ' 2>&1',
            $behindOutput,
            $behindRc
        );
        $behind = trim(implode('', $behindOutput));
        if (!is_numeric($behind)) {
            $behind = '?';
        }
        $r['status'] = 'behind';
        $r['status_label'] = "отстаёт на $behind";
        $r['status_class'] = 'badge-integrating';
        $r['behind'] = (int)$behind;
    }
}
unset($r);

echo json_encode([
    'ok' => true,
    'data' => [
        'branch' => $branch,
        'remotes' => $remotes,
    ],
], JSON_UNESCAPED_UNICODE);
