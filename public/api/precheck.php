<?php
declare(strict_types=1);

/**
 * API: предпроверки перед синхронизацией
 * POST /api/precheck.php — запустить предпроверки проекта
 */

header('Content-Type: application/json; charset=utf-8');

function strip_ansi($text) {
    return preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $text);
}

$workspace = dirname(__DIR__, 2);
$precheckScript = "$workspace/bin/gitproject-sync-precheck.sh";

if (!is_executable($precheckScript)) {
    echo json_encode(['ok' => false, 'error' => 'Скрипт предпроверок не найден', 'error_code' => 'SCRIPT_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Метод не разрешён', 'error_code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$input = json_decode(file_get_contents('php://input'), true);
$slug = $input['slug'] ?? '';

if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректное имя проекта', 'error_code' => 'INVALID_SLUG'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$output = [];
$returnCode = 0;
exec(sprintf('%s %s 2>&1', escapeshellarg($precheckScript), escapeshellarg($slug)), $output, $returnCode);

$logText = strip_ansi(implode("\n", $output));

if ($returnCode === 0) {
    echo json_encode([
        'ok' => true,
        'data' => [
            'message' => 'Предпроверки пройдены',
            'log' => $logText,
        ],
    ], JSON_UNESCAPED_UNICODE);
} else {
    $errorCode = 'PRECHECK_FAILED';
    $failures = [];

    // Парсим тип ошибки
    if (strpos($logText, 'запрещённые файлы') !== false || strpos($logText, 'Найдены запрещённые файлы') !== false) {
        $errorCode = 'FORBIDDEN_FILES';
        $failures[] = 'Найдены запрещённые файлы (vendor/, data/, .env и т.д.)';
    }
    if (strpos($logText, 'Не определена ветка') !== false || strpos($logText, "Разрешён только master") !== false) {
        $errorCode = 'NOT_MASTER_BRANCH';
        $failures[] = 'Ветка не master';
    }
    if (strpos($logText, 'конфликты') !== false || strpos($logText, 'неразрешённые конфликты') !== false) {
        $errorCode = 'MERGE_CONFLICTS';
        $failures[] = 'Есть неразрешённые конфликты';
    }
    if (strpos($logText, 'composer.json') !== false) {
        $errorCode = 'COMPOSER_ISSUE';
        $failures[] = 'Проблемы с composer.json';
    }
    if (strpos($logText, 'Markdown') !== false || strpos($logText, 'slopcheck') !== false) {
        $errorCode = 'MARKDOWN_QUALITY';
        $failures[] = 'Низкое качество markdown-файлов';
    }
    if (strpos($logText, 'origin') !== false && strpos($logText, 'Нет remote') !== false) {
        $errorCode = 'NO_ORIGIN';
        $failures[] = 'Нет remote origin';
    }

    if (empty($failures)) {
        $failures[] = 'Предпроверка не пройдена';
    }

    echo json_encode([
        'ok' => false,
        'error' => 'Предпроверка не пройдена',
        'error_code' => $errorCode,
        'data' => [
            'log' => $logText,
            'failures' => $failures,
        ],
    ], JSON_UNESCAPED_UNICODE);
}
