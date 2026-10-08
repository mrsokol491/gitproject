<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// Функция очистки ANSI escape-последовательностей из вывода
function strip_ansi($text) {
    return preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $text);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Метод не разрешён', 'error_code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$input = json_decode(file_get_contents('php://input'), true);
$slug = $input['slug'] ?? '';
$mode = $input['mode'] ?? 'all';
$remote = $input['remote'] ?? '';

if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректное имя проекта', 'error_code' => 'INVALID_SLUG'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

if (!in_array($mode, ['all', 'specific'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректный режим', 'error_code' => 'INVALID_MODE'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$workspace = dirname(__DIR__, 2);
$syncScript = "$workspace/bin/git-project-sync.sh";

if (!is_executable($syncScript)) {
    echo json_encode(['ok' => false, 'error' => 'Скрипт не найден', 'error_code' => 'SCRIPT_ERROR'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$cmd = sprintf('%s %s', escapeshellarg($syncScript), escapeshellarg($slug));
if ($mode === 'all') {
    $cmd .= ' --all';
} elseif ($mode === 'specific' && preg_match('/^[a-zA-Z0-9_-]+$/', $remote)) {
    $cmd .= sprintf(' --remote %s', escapeshellarg($remote));
}

$output = [];
$returnCode = 0;
exec($cmd . ' 2>&1', $output, $returnCode);

if ($returnCode === 0) {
    echo json_encode(['ok' => true, 'data' => ['message' => 'Успех', 'log' => strip_ansi(implode("\n", $output))]], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['ok' => false, 'error' => 'Ошибка синхронизации', 'error_code' => 'SYNC_FAILED', 'data' => ['log' => strip_ansi(implode("\n", $output))]], JSON_UNESCAPED_UNICODE);
}
