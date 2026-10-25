<?php
declare(strict_types=1);

/**
 * API: удаление проекта
 * DELETE /api/projects/{slug}.php
 *
 * Query params:
 *   scope:    local | host | all  (по умолчанию: local)
 *   remote:   имя remote (для scope=host и scope=all)
 *   confirm:  код подтверждения (DELETE-LOCAL, DELETE-<REMOTE_UPPER>, DELETE-ALL)
 */

header('Content-Type: application/json; charset=utf-8');

$workspace = dirname(__DIR__, 2);
$projectsDir = "$workspace/projects";

// --- Валидация ------------------------------------------------------------
$uri = $_SERVER['REQUEST_URI'] ?? '';
if (!preg_match('#/api/projects/([a-zA-Z0-9_-]+)\.php#', $uri, $m)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректный запрос', 'error_code' => 'INVALID_REQUEST'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$slug = $m[1];
$repo = "$projectsDir/$slug";

if (!is_dir($repo)) {
    echo json_encode(['ok' => false, 'error' => "Проект '$slug' не найден", 'error_code' => 'PROJECT_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    echo json_encode(['ok' => false, 'error' => 'Метод не разрешён', 'error_code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$scope = $_GET['scope'] ?? 'local';
$remote = $_GET['remote'] ?? '';
$confirm = $_GET['confirm'] ?? '';

if (!in_array($scope, ['local', 'host', 'all'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректный scope', 'error_code' => 'INVALID_SCOPE'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

// --- scope=local: удалить локальный каталог --------------------------------
if ($scope === 'local') {
    if ($confirm !== 'DELETE-LOCAL') {
        echo json_encode([
            'ok' => false,
            'error' => 'Требуется подтверждение',
            'error_code' => 'CONFIRM_REQUIRED',
            'data' => ['required_code' => 'DELETE-LOCAL', 'scope' => 'local']
        ], JSON_UNESCAPED_UNICODE);
        exit(0);
    }

    if (!is_dir($repo)) {
        echo json_encode(['ok' => true, 'data' => ['message' => 'Каталог уже удалён']], JSON_UNESCAPED_UNICODE);
        exit(0);
    }

    $output = [];
    @exec("rm -rf " . escapeshellarg($repo) . " 2>&1", $output, $rc);

    echo json_encode([
        'ok' => true,
        'data' => [
            'message' => "Проект '$slug' удалён локально",
            'scope' => 'local',
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- scope=host: удалить remote с конкретного хоста ------------------------
if ($scope === 'host') {
    if ($remote === '') {
        echo json_encode(['ok' => false, 'error' => 'Укажите remote', 'error_code' => 'REMOTE_REQUIRED'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $remote)) {
        echo json_encode(['ok' => false, 'error' => 'Некорректное имя remote', 'error_code' => 'INVALID_REMOTE'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }

    $expectedConfirm = 'DELETE-' . strtoupper($remote);
    if ($confirm !== $expectedConfirm) {
        echo json_encode([
            'ok' => false,
            'error' => 'Требуется подтверждение',
            'error_code' => 'CONFIRM_REQUIRED',
            'data' => ['required_code' => $expectedConfirm, 'scope' => 'host', 'remote' => $remote]
        ], JSON_UNESCAPED_UNICODE);
        exit(0);
    }

    if (!is_dir("$repo/.git")) {
        echo json_encode(['ok' => false, 'error' => "Проект не является git-репозиторием", 'error_code' => 'NOT_A_REPO'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }

    // Проверяем remote
    $output = [];
    @exec("git -C " . escapeshellarg($repo) . " remote get-url " . escapeshellarg($remote) . " 2>&1", $output, $rc);
    if ($rc !== 0) {
        echo json_encode(['ok' => false, 'error' => "Remote '$remote' не настроен", 'error_code' => 'REMOTE_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }

    $url = trim(implode('', $output));

    // Удаление remote
    $output = [];
    @exec("git -C " . escapeshellarg($repo) . " remote remove " . escapeshellarg($remote) . " 2>&1", $output, $rc);

    if ($rc === 0) {
        echo json_encode([
            'ok' => true,
            'data' => [
                'message' => "Remote '$remote' удалён (репозиторий на хосте нужно удалить вручную через Web-UI)",
                'scope' => 'host',
                'remote' => $remote,
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

// --- scope=all: удалить все remote -----------------------------------------
if ($scope === 'all') {
    if ($confirm !== 'DELETE-ALL') {
        echo json_encode([
            'ok' => false,
            'error' => 'Требуется подтверждение',
            'error_code' => 'CONFIRM_REQUIRED',
            'data' => ['required_code' => 'DELETE-ALL', 'scope' => 'all']
        ], JSON_UNESCAPED_UNICODE);
        exit(0);
    }

    if (!is_dir("$repo/.git")) {
        echo json_encode(['ok' => false, 'error' => "Проект не является git-репозиторием", 'error_code' => 'NOT_A_REPO'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }

    // Получаем список remote
    $output = [];
    @exec("git -C " . escapeshellarg($repo) . " remote 2>&1", $output, $rc);
    $remotes = array_map('trim', $output);
    $remotes = array_filter($remotes, fn($r) => $r !== '');

    if (empty($remotes)) {
        echo json_encode([
            'ok' => true,
            'data' => ['message' => 'Нет настроенных remote', 'scope' => 'all']
        ], JSON_UNESCAPED_UNICODE);
        exit(0);
    }

    $removed = [];
    foreach ($remotes as $r) {
        $out = [];
        @exec("git -C " . escapeshellarg($repo) . " remote remove " . escapeshellarg($r) . " 2>&1", $out, $rc);
        if ($rc === 0) {
            $removed[] = $r;
        }
    }

    echo json_encode([
        'ok' => true,
        'data' => [
            'message' => "Удалено remote: " . implode(', ', $removed) . " (репозитории на хостах нужно удалить вручную через Web-UI)",
            'scope' => 'all',
            'removed' => $removed,
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function strip_ansi($text) {
    return preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $text);
}
