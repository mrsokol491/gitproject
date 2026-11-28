<?php

declare(strict_types=1);

/**
 * Выборочная синхронизация: push на выбранные remote (1..N), pull с одного.
 * POST: {slug, action: "push"|"pull", remotes: ["gitflic", ...]}
 * Ответ: {ok:true, data:{results:[{remote, ok, log}], log}}
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Метод не разрешён', 'error_code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$hubRoot = dirname(__DIR__, 2);
$slug = trim((string) ($input['slug'] ?? ''));
$action = (string) ($input['action'] ?? '');
$remotes = $input['remotes'] ?? [];

if ($slug === '' || preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $slug) !== 1) {
    echo json_encode(['ok' => false, 'error' => 'Некорректный slug', 'error_code' => 'INVALID_SLUG'], JSON_UNESCAPED_UNICODE);
    exit(1);
}
if (!in_array($action, ['push', 'pull'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректное действие', 'error_code' => 'INVALID_ACTION'], JSON_UNESCAPED_UNICODE);
    exit(1);
}
if (!is_array($remotes) || $remotes === [] || count($remotes) > 12) {
    echo json_encode(['ok' => false, 'error' => 'Выберите от 1 до 12 remote', 'error_code' => 'INVALID_REMOTES'], JSON_UNESCAPED_UNICODE);
    exit(1);
}
if ($action === 'pull' && count($remotes) !== 1) {
    echo json_encode(['ok' => false, 'error' => 'Pull выполняется с одного remote', 'error_code' => 'PULL_SINGLE_ONLY'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$repo = $hubRoot . '/projects/' . $slug;
if (!is_dir($repo . '/.git')) {
    echo json_encode(['ok' => false, 'error' => 'Проект не найден', 'error_code' => 'NOT_FOUND'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

exec(sprintf('git -C %s remote 2>/dev/null', escapeshellarg($repo)), $knownLines, $rc);
$known = array_map('trim', $knownLines);

$script = $hubRoot . '/bin/project/gitproject-sync.sh';
if (!is_executable($script)) {
    echo json_encode(['ok' => false, 'error' => 'Скрипт синхронизации не найден', 'error_code' => 'SCRIPT_ERROR'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$results = [];
$allLog = [];
$allOk = true;

foreach ($remotes as $remote) {
    $remote = trim((string) $remote);
    if ($remote === '' || preg_match('/^[A-Za-z0-9._-]+$/', $remote) !== 1 || !in_array($remote, $known, true)) {
        $results[] = ['remote' => $remote, 'ok' => false, 'log' => 'remote не настроен у проекта'];
        $allOk = false;
        continue;
    }

    $args = [$script, $slug];
    if ($action === 'push') {
        $args[] = '--remote';
        $args[] = $remote;
    } else {
        $args[] = '--pull';
        $args[] = '--remote';
        $args[] = $remote;
    }

    $cmd = 'GIT_TERMINAL_PROMPT=0 timeout 120 ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    $output = [];
    $exit = 0;
    exec($cmd, $output, $exit);
    $log = preg_replace('/\x1b\[[0-9;]*m/', '', implode("\n", $output));
    $ok = ($exit === 0);
    $allOk = $allOk && $ok;
    $results[] = ['remote' => $remote, 'ok' => $ok, 'log' => $log];
    $allLog[] = '=== ' . $action . ' ' . $remote . ' (exit ' . $exit . ") ===\n" . $log;
}

echo json_encode([
    'ok' => $allOk,
    'data' => ['results' => $results, 'log' => implode("\n", $allLog)],
    'error' => $allOk ? null : 'Часть операций завершилась с ошибкой',
    'error_code' => $allOk ? null : 'SYNC_PARTIAL',
], JSON_UNESCAPED_UNICODE);