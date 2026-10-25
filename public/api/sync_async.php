<?php
declare(strict_types=1);

/**
 * API: запуск асинхронной синхронизации
 * POST /api/sync_async.php
 * Body: {"slug": "project-name", "mode": "all"|"specific", "remote": "remote-name"}
 *
 * Запускает синхронизацию в фоне и возвращает job_id.
 * Статус проверяется через /api/sync_status.php?job_id=...
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Метод не разрешён', 'error_code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['ok' => false, 'error' => 'Неверный формат данных', 'error_code' => 'INVALID_INPUT'], JSON_UNESCAPED_UNICODE);
    exit;
}

$slug = $input['slug'] ?? '';
$mode = $input['mode'] ?? 'all';
$remote = $input['remote'] ?? '';

// --- Валидация ------------------------------------------------------------
if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $slug)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректное имя проекта', 'error_code' => 'INVALID_SLUG'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($mode, ['all', 'specific'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректный режим', 'error_code' => 'INVALID_MODE'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($mode === 'specific' && (!preg_match('/^[a-zA-Z0-9_-]+$/', $remote))) {
    echo json_encode(['ok' => false, 'error' => 'Некорректное имя remote', 'error_code' => 'INVALID_REMOTE'], JSON_UNESCAPED_UNICODE);
    exit;
}

$workspace = dirname(__DIR__, 2);
$projectsRoot = "$workspace/projects";
$dataDir = "$workspace/data";
$jobsDir = "$dataDir/jobs";

// Проверяем существование проекта
if (!is_dir("$projectsRoot/$slug/.git")) {
    echo json_encode(['ok' => false, 'error' => 'Проект не найден', 'error_code' => 'PROJECT_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Создаём директорию для jobs
if (!is_dir($jobsDir)) {
    @mkdir($jobsDir, 0755, true);
}

// --- Проверяем, нет ли уже запущенной синхронизации -----------------------
$jobFile = "$jobsDir/$slug.json";
if (is_file($jobFile)) {
    $existing = json_decode(file_get_contents($jobFile), true);
    if (is_array($existing) && ($existing['status'] ?? '') === 'running') {
        echo json_encode(['ok' => false, 'error' => 'Синхронизация уже запущена', 'error_code' => 'SYNC_RUNNING', 'data' => ['job_id' => $existing['job_id'] ?? '']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Удаляем завершённый job
    @unlink($jobFile);
}

// --- Создаём job ----------------------------------------------------------
$jobId = sprintf('%s_%s', $slug, date('Ymd_His'));
$jobData = [
    'job_id' => $jobId,
    'slug' => $slug,
    'mode' => $mode,
    'remote' => $remote,
    'status' => 'pending',
    'progress' => 0,
    'started_at' => date('Y-m-d H:i:s'),
    'log' => '',
    'error' => '',
];

file_put_contents($jobFile, json_encode($jobData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// --- Формируем команду ----------------------------------------------------
$syncScript = "$workspace/bin/git-project-sync.sh";
if (!is_executable($syncScript)) {
    @unlink($jobFile);
    echo json_encode(['ok' => false, 'error' => 'Скрипт синхронизации не найден', 'error_code' => 'SCRIPT_MISSING'], JSON_UNESCAPED_UNICODE);
    exit;
}

$logFile = "$jobsDir/$slug.log";
$cmd = sprintf('%s %s', escapeshellarg($syncScript), escapeshellarg($slug));
if ($mode === 'all') {
    $cmd .= ' --all';
} elseif ($mode === 'specific' && preg_match('/^[a-zA-Z0-9_-]+$/', $remote)) {
    $cmd .= sprintf(' --remote %s', escapeshellarg($remote));
}

// Запускаем в фоне
$safeCmd = sprintf(
    'nohup %s > %s 2>&1 & echo $!',
    escapeshellarg($cmd),
    escapeshellarg($logFile)
);

$pid = (int) trim(shell_exec($safeCmd) ?? '0');

// --- Обновляем статус job -------------------------------------------------
$jobData['status'] = 'running';
$jobData['progress'] = 5;
$jobData['pid'] = $pid;
file_put_contents($jobFile, json_encode($jobData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo json_encode([
    'ok' => true,
    'data' => [
        'job_id' => $jobId,
        'slug' => $slug,
        'status' => 'running',
        'pid' => $pid,
    ],
], JSON_UNESCAPED_UNICODE);
