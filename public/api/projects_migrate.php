<?php
declare(strict_types=1);

/**
 * API: миграция проекта между хостами
 * GET  /api/projects_migrate.php?slug=<slug>    — таблица версий
 * POST /api/projects_migrate.php                — выполнить миграцию
 *
 * Тело POST: { "slug": "...", "source": "local|remote_name", "target": "local|remote_name" }
 */

header('Content-Type: application/json; charset=utf-8');

function strip_ansi($text) {
    return preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $text);
}

$workspace = dirname(__DIR__, 2);
$migrateScript = "$workspace/bin/git-project-migrate.sh";

if (!is_executable($migrateScript)) {
    echo json_encode(['ok' => false, 'error' => 'Скрипт миграции не найден', 'error_code' => 'SCRIPT_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

// --- GET: таблица версий ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $slug = $_GET['slug'] ?? '';

    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
        echo json_encode(['ok' => false, 'error' => 'Некорректное имя проекта', 'error_code' => 'INVALID_SLUG'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }

    $output = [];
    $returnCode = 0;
    // Используем --source local --target local чтобы получить только таблицу без интерактива
    // Скрипт выводит таблицу в stdout, поэтому парсим вывод
    exec(sprintf('%s %s --source local --target local 2>&1', escapeshellarg($migrateScript), escapeshellarg($slug)), $output, $returnCode);

    $logText = strip_ansi(implode("\n", $output));

    // Парсим таблицу из вывода
    $versions = [];
    $inTable = false;
    foreach ($output as $line) {
        $clean = strip_ansi($line);
        // Ищем строки с номерами версий (начинаются с пробелов и числа)
        if (preg_match('/^\s+\d+\s+\S+\s+\S+\s+\S+\s+.*/', $clean, $m)) {
            $inTable = true;
            // Парсим: N  ИМЯ  SHA  ДАТА  СОСТОЯНИЕ
            if (preg_match('/\s+(\d+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(.*)/', $clean, $parts)) {
                $versions[] = [
                    'num' => (int)$parts[1],
                    'name' => $parts[2],
                    'sha' => $parts[3],
                    'date' => $parts[4],
                    'state' => trim($parts[5]),
                ];
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'data' => [
            'slug' => $slug,
            'log' => $logText,
            'versions' => $versions,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- POST: выполнение миграции ----------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Метод не разрешён', 'error_code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$input = json_decode(file_get_contents('php://input'), true);
$slug = $input['slug'] ?? '';
$source = $input['source'] ?? '';
$target = $input['target'] ?? '';

if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректное имя проекта', 'error_code' => 'INVALID_SLUG'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

if (empty($source) || empty($target)) {
    echo json_encode(['ok' => false, 'error' => 'Укажите source и target', 'error_code' => 'MISSING_PARAMS'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

// source/target: "local" или имя remote
if (!preg_match('/^(local|[a-zA-Z0-9_-]+)$/', $source) || !preg_match('/^(local|[a-zA-Z0-9_-]+)$/', $target)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректное имя источника или приёмника', 'error_code' => 'INVALID_SOURCE_TARGET'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

if ($source === $target) {
    echo json_encode(['ok' => false, 'error' => 'Источник и приёмник не могут совпадать', 'error_code' => 'SAME_SOURCE_TARGET'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$output = [];
$returnCode = 0;
exec(sprintf('%s %s --source %s --target %s 2>&1', escapeshellarg($migrateScript), escapeshellarg($slug), escapeshellarg($source), escapeshellarg($target)), $output, $returnCode);

$logText = strip_ansi(implode("\n", $output));

if ($returnCode === 0) {
    echo json_encode([
        'ok' => true,
        'data' => [
            'message' => 'Миграция завершена успешно',
            'source' => $source,
            'target' => $target,
            'log' => $logText,
        ],
    ], JSON_UNESCAPED_UNICODE);
} else {
    $errorCode = 'MIGRATE_FAILED';
    if (strpos($logText, 'ОТСТАЁТ') !== false || strpos($logText, 'блокирована') !== false) {
        $errorCode = 'SOURCE_BEHIND';
    } elseif (strpos($logText, 'не инициализирован') !== false) {
        $errorCode = 'EMPTY_SOURCE';
    }
    echo json_encode([
        'ok' => false,
        'error' => 'Ошибка миграции',
        'error_code' => $errorCode,
        'data' => ['log' => $logText],
    ], JSON_UNESCAPED_UNICODE);
}
