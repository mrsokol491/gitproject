<?php
declare(strict_types=1);

/**
 * API: мульти-push самого Hub (корневой репозиторий) на все remote
 * POST /api/hub_multi_push.php
 *
 * Коммитит локальные изменения и push на все настроенные remote.
 */

header('Content-Type: application/json; charset=utf-8');

function strip_ansi($text) {
    return preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $text);
}

$workspace = dirname(__DIR__, 2);
$hubRoot = $workspace;
$hubGitDir = "$hubRoot/.git";
$hubScript = "$workspace/bin/hub/gitproject-sync-hub.sh";

if (!is_dir($hubGitDir)) {
    echo json_encode(['ok' => false, 'error' => 'Не git-репозиторий', 'error_code' => 'NOT_A_REPO'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Метод не разрешён', 'error_code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

$output = [];
$returnCode = 0;

// Запускаем скрипт мульти-пуша
exec(sprintf('%s 2>&1', escapeshellarg($hubScript)), $output, $returnCode);

$logText = strip_ansi(implode("\n", $output));

if ($returnCode === 0) {
    echo json_encode([
        'ok' => true,
        'data' => [
            'message' => 'Мульти-push хаба завершён',
            'log' => $logText,
        ],
    ], JSON_UNESCAPED_UNICODE);
} else {
    $errorCode = 'HUB_PUSH_FAILED';
    if (strpos($logText, 'не является git-репозиторием') !== false || strpos($logText, 'Не git') !== false) {
        $errorCode = 'NOT_A_REPO';
    } elseif (strpos($logText, 'Не определена') !== false) {
        $errorCode = 'NO_BRANCH';
    }
    echo json_encode([
        'ok' => false,
        'error' => 'Ошибка мульти-пуша хаба',
        'error_code' => $errorCode,
        'data' => ['log' => $logText],
    ], JSON_UNESCAPED_UNICODE);
}
