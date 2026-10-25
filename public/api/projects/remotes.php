<?php
declare(strict_types=1);

/**
 * API: управление remote проекта
 * GET  /api/projects/{slug}/remotes.php           — список remote с SHA/датой/статусом
 * POST /api/projects/{slug}/remotes.php?action=add&host_id=X[&owner=Y]
 *                                            — добавить remote
 * DELETE /api/projects/{slug}/remotes/{name}.php — удалить remote
 */

header('Content-Type: application/json; charset=utf-8');

function strip_ansi($text) {
    return preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $text);
}

$workspace = dirname(__DIR__, 2);
$projectsDir = "$workspace/projects";
$hostsFile = "$workspace/config/hosts.json";
$envFile = "$workspace/.env";

// --- Helper: получить repo path и slug из URI -------------------------------
function getProjectFromUri($workspace, $hostsFile) {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    // /api/projects/{slug}/remotes.php?...
    if (!preg_match('#/api/projects/([a-zA-Z0-9_-]+)/remotes#', $uri, $m)) {
        return null;
    }
    $slug = $m[1];
    $repo = "$workspace/projects/$slug";
    if (!is_dir("$repo/.git")) {
        return ['error' => "Проект '$slug' не найден или не является git-репозиторием", 'code' => 'PROJECT_NOT_FOUND'];
    }
    return ['slug' => $slug, 'repo' => $repo];
}

// --- DELETE: удалить remote -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (!preg_match('#/api/projects/([a-zA-Z0-9_-]+)/remotes/([a-zA-Z0-9_-]+)\.php#', $uri, $m)) {
        echo json_encode(['ok' => false, 'error' => 'Некорректный запрос', 'error_code' => 'INVALID_REQUEST'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }
    $slug = $m[1];
    $remoteName = $m[2];
    $repo = "$projectsDir/$slug";

    if (!is_dir("$repo/.git")) {
        echo json_encode(['ok' => false, 'error' => "Проект '$slug' не найден", 'error_code' => 'PROJECT_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }
    if ($remoteName === 'origin') {
        echo json_encode(['ok' => false, 'error' => 'Удалить origin нельзя', 'error_code' => 'ORIGIN_PROTECTED'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }

    // Проверяем существование remote
    $output = [];
    @exec("git -C " . escapeshellarg($repo) . " remote get-url " . escapeshellarg($remoteName) . " 2>&1", $output, $rc);
    if ($rc !== 0) {
        echo json_encode(['ok' => false, 'error' => "Remote '$remoteName' не существует", 'error_code' => 'REMOTE_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }

    $url = trim(implode('', $output));

    // Удаление
    $output = [];
    @exec("git -C " . escapeshellarg($repo) . " remote remove " . escapeshellarg($remoteName) . " 2>&1", $output, $rc);

    if ($rc === 0) {
        echo json_encode([
            'ok' => true,
            'data' => [
                'message' => "Remote '$remoteName' удалён",
                'url' => $url,
            ]
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'ok' => false,
            'error' => 'Ошибка удаления remote',
            'error_code' => 'REMOVE_FAILED',
            'data' => ['log' => strip_ansi(implode("\n", $output))]
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// --- POST: добавить remote --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project = getProjectFromUri($workspace, $hostsFile);
    if (isset($project['error'])) {
        echo json_encode(['ok' => false, 'error' => $project['error'], 'error_code' => $project['code']], JSON_UNESCAPED_UNICODE);
        exit(1);
    }
    $slug = $project['slug'];
    $repo = $project['repo'];

    $input = json_decode(file_get_contents('php://input'), true);
    $hostId = $input['host_id'] ?? ($_GET['host_id'] ?? '');
    $owner  = $input['owner'] ?? ($_GET['owner'] ?? '');

    if (!preg_match('/^[a-z]+$/', $hostId)) {
        echo json_encode(['ok' => false, 'error' => 'Некорректный ID хоста', 'error_code' => 'INVALID_HOST'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $owner)) {
        echo json_encode(['ok' => false, 'error' => 'Некорректное имя владельца', 'error_code' => 'INVALID_OWNER'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }

    // Загрузка hosts.json
    if (!is_file($hostsFile)) {
        echo json_encode(['ok' => false, 'error' => 'hosts.json не найден', 'error_code' => 'CONFIG_MISSING'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }
    $hosts = json_decode(file_get_contents($hostsFile), true);
    if (!isset($hosts[$hostId])) {
        echo json_encode(['ok' => false, 'error' => "Хост '$hostId' не найден", 'error_code' => 'HOST_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }

    $hostConfig = $hosts[$hostId];
    $domain = $hostConfig['host'] ?? '';
    $urlTemplate = $hostConfig['url_template'] ?? '';

    if ($domain === '' || $urlTemplate === '') {
        echo json_encode(['ok' => false, 'error' => "Хост '$hostId' не настроен", 'error_code' => 'HOST_INCOMPLETE'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }

    // Remote name = host_id
    $remoteName = $hostId;

    // Проверяем, не существует ли уже
    $output = [];
    @exec("git -C " . escapeshellarg($repo) . " remote get-url " . escapeshellarg($remoteName) . " 2>&1", $output, $rc);
    if ($rc === 0) {
        echo json_encode(['ok' => false, 'error' => "Remote '$remoteName' уже существует", 'error_code' => 'REMOTE_EXISTS'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }

    // Формируем URL
    $url = str_replace(['{owner}', '{slug}'], [$owner, $slug], $urlTemplate);

    // Проверяем существование репозитория
    $checkUrl = rtrim($url, '.git') . '.git';
    $output = [];
    @exec("git ls-remote " . escapeshellarg($checkUrl) . " 2>&1", $output, $rc);
    if ($rc !== 0 || empty(implode('', $output))) {
        echo json_encode([
            'ok' => false,
            'error' => "Репозиторий $owner/$slug не найден на $domain",
            'error_code' => 'REPO_NOT_FOUND',
            'data' => ['log' => strip_ansi(implode("\n", $output))]
        ], JSON_UNESCAPED_UNICODE);
        exit(1);
    }

    // Добавляем remote
    $output = [];
    @exec("git -C " . escapeshellarg($repo) . " remote add " . escapeshellarg($remoteName) . " " . escapeshellarg($url) . " 2>&1", $output, $rc);

    if ($rc !== 0) {
        echo json_encode([
            'ok' => false,
            'error' => 'Ошибка добавления remote',
            'error_code' => 'ADD_FAILED',
            'data' => ['log' => strip_ansi(implode("\n", $output))]
        ], JSON_UNESCAPED_UNICODE);
        exit(1);
    }

    // Fetch
    $fetchOutput = [];
    @exec("git -C " . escapeshellarg($repo) . " fetch " . escapeshellarg($remoteName) . " 2>&1", $fetchOutput, $fetchRc);

    // Получаем обновлённый список
    $remotes = getRemotesInfo($repo);

    echo json_encode([
        'ok' => true,
        'data' => [
            'message' => "Remote '$remoteName' добавлен",
            'remote' => $remoteName,
            'url' => $url,
            'fetched' => ($fetchRc === 0),
            'remotes' => $remotes,
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- GET: список remote с SHA/датой/статусом --------------------------------
$project = getProjectFromUri($workspace, $hostsFile);
if (isset($project['error'])) {
    echo json_encode(['ok' => false, 'error' => $project['error'], 'error_code' => $project['code']], JSON_UNESCAPED_UNICODE);
    exit(1);
}
$slug = $project['slug'];
$repo = $project['repo'];

// Текущая ветка
$branch = '';
$output = [];
@exec("git -C " . escapeshellarg($repo) . " branch --show-current 2>&1", $output, $rc);
if ($rc === 0) $branch = trim(implode('', $output));

if ($branch === '') {
    echo json_encode(['ok' => false, 'error' => 'Не определена текущая ветка', 'error_code' => 'NO_BRANCH'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

// Получаем remote
$remotes = getRemotesInfo($repo);

// Находим самый свежий SHA среди remote
$latestSha = '';
$latestDate = '';
foreach ($remotes as $r) {
    if ($r['sha'] !== '' && $r['date'] !== '') {
        if ($latestDate === '' || $r['date'] > $latestDate) {
            $latestSha = $r['sha'];
            $latestDate = $r['date'];
        }
    }
}

// Добавляем статус
foreach ($remotes as &$r) {
    if ($r['sha'] === '') {
        $r['status'] = 'empty';
        $r['status_text'] = '⚪ не инициализирован';
    } elseif ($r['sha'] === $latestSha) {
        $r['status'] = 'fresh';
        $r['status_text'] = '✅ актуален';
    } else {
        // Считаем отставание
        $behind = 0;
        $out = [];
        @exec("git -C " . escapeshellarg($repo) . " rev-list --count " . escapeshellarg($r['sha']) . ".." . escapeshellarg($latestSha) . " 2>&1", $out, $rc);
        if ($rc === 0 && is_numeric(implode('', $out))) {
            $behind = (int) implode('', $out);
        }
        $r['status'] = 'behind';
        $r['status_text'] = "⚠ отстаёт на $behind";
        $r['behind'] = $behind;
    }
}
unset($r);

echo json_encode([
    'ok' => true,
    'data' => [
        'slug' => $slug,
        'branch' => $branch,
        'remotes' => $remotes,
        'latest_sha' => $latestSha,
        'latest_date' => $latestDate,
    ]
], JSON_UNESCAPED_UNICODE);
exit;

// --- Helper functions -------------------------------------------------------

function getRemotesInfo(string $repo): array {
    $remotes = [];
    $output = [];
    @exec("git -C " . escapeshellarg($repo) . " remote 2>&1", $output, $rc);
    if ($rc !== 0) return $remotes;

    $branch = '';
    $out = [];
    @exec("git -C " . escapeshellarg($repo) . " branch --show-current 2>&1", $out, $rc);
    if ($rc === 0) $branch = trim(implode('', $out));

    foreach ($output as $remoteName) {
        $remoteName = trim($remoteName);
        if ($remoteName === '') continue;

        $url = '';
        $out = [];
        @exec("git -C " . escapeshellarg($repo) . " remote get-url " . escapeshellarg($remoteName) . " 2>&1", $out, $rc);
        if ($rc === 0) $url = trim(implode('', $out));

        $sha = '';
        $date = '';
        if ($branch !== '' && $url !== '') {
            $out = [];
            @exec("git -C " . escapeshellarg($repo) . " show-ref --verify --quiet refs/remotes/$remoteName/$branch 2>&1", $out, $rc);
            if ($rc === 0) {
                $out = [];
                @exec("git -C " . escapeshellarg($repo) . " rev-parse refs/remotes/$remoteName/$branch 2>&1", $out, $rc);
                if ($rc === 0) {
                    $sha = trim(implode('', $out));
                    if ($sha !== '') {
                        $out = [];
                        @exec("git -C " . escapeshellarg($repo) . " log -1 --format='%ci' " . escapeshellarg($sha) . " 2>&1", $out, $rc);
                        if ($rc === 0) $date = trim(implode('', $out));
                    }
                }
            }
        }

        // Определяем host по URL
        $host = '';
        if (preg_match('#https?://([^/]+)#', $url, $um)) {
            $host = $um[1];
        }

        $remotes[$remoteName] = [
            'name' => $remoteName,
            'url' => $url,
            'host' => $host,
            'sha' => $sha,
            'date' => $date,
        ];
    }

    return $remotes;
}
