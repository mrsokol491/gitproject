<?php
declare(strict_types=1);

/**
 * API: удаление проекта
 * DELETE /api/projects/:slug.php
 *
 * Query params:
 *   scope=local|host|all
 *   remote=<remote_name>  // для scope=host
 *
 * Тело JSON:
 * {
 *   "confirm": "DELETE-LOCAL" | "DELETE-<REMOTE>" | "DELETE-ALL"
 * }
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    echo json_encode(['ok' => false, 'error' => 'Метод не разрешён', 'error_code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

$slug = trim($_GET['slug'] ?? '');
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректный slug', 'error_code' => 'INVALID_SLUG'], JSON_UNESCAPED_UNICODE);
    exit;
}

$host = trim($_GET['host'] ?? '');

$input = json_decode(file_get_contents('php://input'), true);
$confirm = trim($input['confirm'] ?? '');

$workspace = dirname(__DIR__, 2);
$projectsDir = "$workspace/projects";
$repo = "$projectsDir/$slug";

// --- Scope: local -------------------------------------------------------------

if ($confirm === 'DELETE-LOCAL') {
    if (!is_dir($repo)) {
        echo json_encode(['ok' => false, 'error' => 'Локальный каталог не найден', 'error_code' => 'DIR_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $output = [];
    $rc = 0;
    exec('rm -rf ' . escapeshellarg($repo) . ' 2>&1', $output, $rc);

    if ($rc !== 0 || !is_dir($repo)) {
        echo json_encode([
            'ok' => true,
            'data' => [
                'message' => "Проект '$slug' удалён локально",
                'scope' => 'local',
            ],
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'ok' => false,
            'error' => 'Не удалось удалить каталог',
            'error_code' => 'REMOVE_FAILED',
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// --- Scope: host --------------------------------------------------------------

if (preg_match('/^DELETE-([A-Za-z0-9_-]+)$/', $confirm, $cm)) {
    $remoteName = $cm[1];

    if (!is_dir("$repo/.git")) {
        echo json_encode(['ok' => false, 'error' => 'Проект не найден или не git-репозиторий', 'error_code' => 'PROJECT_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Проверить remote
    $checkOutput = [];
    exec('git -C ' . escapeshellarg($repo) . ' remote get-url ' . escapeshellarg($remoteName) . ' 2>&1', $checkOutput, $checkRc);
    if ($checkRc !== 0 || trim(implode('', $checkOutput)) === '') {
        echo json_encode(['ok' => false, 'error' => "Remote '$remoteName' не существует", 'error_code' => 'REMOTE_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $url = trim(implode('', $checkOutput));

    // Удалить remote
    $removeOutput = [];
    exec('git -C ' . escapeshellarg($repo) . ' remote remove ' . escapeshellarg($remoteName) . ' 2>&1', $removeOutput, $removeRc);

    if ($removeRc !== 0) {
        echo json_encode([
            'ok' => false,
            'error' => 'Не удалось удалить remote',
            'error_code' => 'REMOVE_FAILED',
            'data' => ['log' => implode("\n", $removeOutput)],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'data' => [
            'message' => "Remote '$remoteName' удалён",
            'scope' => 'host',
            'remote' => $remoteName,
            'url' => $url,
            'note' => 'Репозиторий на хосте удалите вручную через Web-UI',
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Scope: all ---------------------------------------------------------------

if ($confirm === 'DELETE-ALL') {
    if (!is_dir("$repo/.git")) {
        echo json_encode(['ok' => false, 'error' => 'Проект не найден или не git-репозиторий', 'error_code' => 'PROJECT_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Получить список remote
    $remoteOutput = [];
    exec('git -C ' . escapeshellarg($repo) . ' remote 2>&1', $remoteOutput, $remoteRc);
    $remotes = array_filter(array_map('trim', $remoteOutput));
    $remotes = array_values($remotes);

    if (empty($remotes)) {
        echo json_encode([
            'ok' => false,
            'error' => 'Нет настроенных remote',
            'error_code' => 'NO_REMOTES',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $removed = [];
    $failed = [];

    foreach ($remotes as $rname) {
        $urlOutput = [];
        exec('git -C ' . escapeshellarg($repo) . ' remote get-url ' . escapeshellarg($rname) . ' 2>&1', $urlOutput, $urlRc);
        $url = trim(implode('', $urlOutput));

        $removeOutput = [];
        exec('git -C ' . escapeshellarg($repo) . ' remote remove ' . escapeshellarg($rname) . ' 2>&1', $removeOutput, $removeRc);

        if ($removeRc === 0) {
            $removed[] = ['name' => $rname, 'url' => $url];
        } else {
            $failed[] = $rname;
        }
    }

    echo json_encode([
        'ok' => true,
        'data' => [
            'message' => 'Все remote удалены',
            'scope' => 'all',
            'removed' => $removed,
            'failed' => $failed,
            'note' => 'Репозитории на хостах удалите вручную через Web-UI',
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- No confirm ---------------------------------------------------------------

echo json_encode([
    'ok' => false,
    'error' => 'Не указано подтверждение',
    'error_code' => 'NO_CONFIRM',
    'data' => [
        'expected_local' => 'DELETE-LOCAL',
        'expected_all' => 'DELETE-ALL',
        'remotes' => [],
    ],
], JSON_UNESCAPED_UNICODE);
