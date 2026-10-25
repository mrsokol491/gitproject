<?php
declare(strict_types=1);

/**
 * API: статус синхронизации
 * GET /api/sync_status.php?job_id=slug_YYYYMMDD_HHMMSS
 *
 * Возвращает текущий статус job'а синхронизации.
 */

header('Content-Type: application/json; charset=utf-8');

$jobId = $_GET['job_id'] ?? '';

if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $jobId)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректный job_id', 'error_code' => 'INVALID_JOB_ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

$workspace = dirname(__DIR__, 2);
$dataDir = "$workspace/data";
$jobsDir = "$dataDir/jobs";
$jobFile = "$jobsDir/$jobId.json";

if (!is_file($jobFile)) {
    echo json_encode(['ok' => false, 'error' => 'Job не найден', 'error_code' => 'JOB_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
    exit;
}

$jobData = json_decode(file_get_contents($jobFile), true);
if (!is_array($jobData)) {
    echo json_encode(['ok' => false, 'error' => 'Ошибка чтения статуса', 'error_code' => 'READ_ERROR'], JSON_UNESCAPED_UNICODE);
    exit;
}

$slug = $jobData['slug'] ?? '';
$logFile = "$jobsDir/$slug.log";

// --- Проверяем PID --------------------------------------------------------
$pid = $jobData['pid'] ?? 0;
if ((int) $pid > 0 && !posix_kill((int) $pid, 0)) {
    // Процесс завершён — считываем финальный результат
    $jobData['status'] = 'completed';
    $jobData['progress'] = 100;

    if (is_file($logFile)) {
        $logContent = file_get_contents($logFile);
        if (is_string($logContent)) {
            // Очищаем ANSI escape-коды
            $jobData['log'] = preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $logContent);
        }
    }

    // Проверяем по логу — был ли успех
    if (is_file($logFile) && preg_match('/\b(Успех|success|completed)\b/i', $jobData['log'] ?? '')) {
        $jobData['status'] = 'completed';
    } elseif (is_file($logFile) && preg_match('/\b(Ошибка|error|failed|фатал)/i', $jobData['log'] ?? '')) {
        $jobData['status'] = 'failed';
        $jobData['error'] = 'Синхронизация завершена с ошибками';
    }

    file_put_contents($jobFile, json_encode($jobData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// --- Читаем текущий лог ---------------------------------------------------
if (($jobData['status'] ?? '') === 'running' && is_file($logFile)) {
    $logContent = file_get_contents($logFile);
    if (is_string($logContent)) {
        $cleanLog = preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $logContent);
        // Вычисляем прогресс по строкам лога
        $lines = array_filter(explode("\n", $cleanLog ?? ''));
        $lineCount = count($lines);
        $progress = min(95, max(10, (int) ($lineCount * 3)));
        $jobData['progress'] = $progress;
        $jobData['log'] = $cleanLog;
    }
}

// Обрезаем лог для экономии трафика (последние 200 строк)
if (isset($jobData['log']) && is_string($jobData['log'])) {
    $logLines = array_filter(explode("\n", $jobData['log']));
    $truncated = array_slice($logLines, -200);
    $jobData['log'] = implode("\n", $truncated);
}

echo json_encode(['ok' => true, 'data' => $jobData], JSON_UNESCAPED_UNICODE);
