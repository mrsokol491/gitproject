<?php
declare(strict_types=1);

/**
 * API: аудит проекта
 * POST /api/audit.php — запустить аудит
 * GET  /api/audit.php?slug=<slug>&file=<filename> — скачать отчёт
 */

header('Content-Type: application/json; charset=utf-8');

$workspace = dirname(__DIR__, 2);
$projectsDir = "$workspace/projects";
$auditsDir = "$workspace/data/audits";

// --- GET: скачать отчёт ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $slug = $_GET['slug'] ?? '';
    $file = $_GET['file'] ?? '';

    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
        echo json_encode(['ok' => false, 'error' => 'Некорректное имя проекта', 'error_code' => 'INVALID_SLUG'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }

    if (empty($file)) {
        // Список доступных отчётов
        if (!is_dir($auditsDir)) {
            echo json_encode(['ok' => true, 'data' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $reports = [];
        $pattern = preg_quote($slug, '/') . '-\d{8}_\d{6}\.txt';
        foreach (scandir($auditsDir) as $f) {
            if (preg_match('/^' . $pattern . '$/', $f)) {
                $fullPath = "$auditsDir/$f";
                $reports[] = [
                    'file' => $f,
                    'size' => filesize($fullPath),
                    'date' => date('Y-m-d H:i:s', filemtime($fullPath)),
                ];
            }
        }

        usort($reports, function ($a, $b) {
            return strcmp($b['date'], $a['date']);
        });

        echo json_encode(['ok' => true, 'data' => $reports], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Скачать конкретный файл
    if (!preg_match('/^[a-zA-Z0-9_-]+-\d{8}_\d{6}\.txt$/', $file)) {
        echo json_encode(['ok' => false, 'error' => 'Некорректное имя файла', 'error_code' => 'INVALID_FILE'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }

    $filePath = "$auditsDir/$file";
    if (!is_file($filePath)) {
        echo json_encode(['ok' => false, 'error' => 'Файл не найден', 'error_code' => 'FILE_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }

    // Отдаём файл как скачивание
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}

// --- POST: запуск аудита ----------------------------------------------------
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

$projectPath = "$projectsDir/$slug";
if (!is_dir("$projectPath/.git")) {
    echo json_encode(['ok' => false, 'error' => 'Проект не найден', 'error_code' => 'PROJECT_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

// Создаём директорию для отчётов
if (!is_dir($auditsDir)) {
    mkdir($auditsDir, 0755, true);
}

$auditFile = "$auditsDir/{$slug}-" . date('Y%m%d_%H%M%S') . '.txt';

// Генерируем отчёт
$output = [];
$returnCode = 0;
exec(sprintf(
    'cd %s && { ' .
    'echo "===== AUDIT %s, %s ====="; ' .
    'echo "--- pwd: $(pwd)"; ' .
    'echo "--- ветка: $(git branch --show-current), последний коммит: $(git log --oneline -1)"; ' .
    'echo "--- php: $(php -v 2>/dev/null | head -n 1 || echo "N/A")"; ' .
    'echo ""; ' .
    'echo "--- список файлов (без .git):"; ' .
    'find . -not -path "./.git*" -type f | sort | head -200; ' .
    'echo ""; ' .
    'echo "--- data/:"; ' .
    'ls -la data/ 2>/dev/null || echo "data/ отсутствует"; ' .
    'echo "--- composer.json:"; ' .
    '[ -f composer.json ] && cat composer.json || echo "composer.json отсутствует"; ' .
    'echo ""; ' .
    'echo "--- история коммитов (последние 20):"; ' .
    'git log --oneline | head -n 20; ' .
    '} > %s 2>&1',
    escapeshellarg($projectPath),
    escapeshellarg($slug),
    escapeshellarg(date('Y-m-d H:i:s')),
    escapeshellarg($auditFile)
), $output, $returnCode);

if ($returnCode !== 0 || !is_file($auditFile)) {
    echo json_encode(['ok' => false, 'error' => 'Ошибка генерации отчёта', 'error_code' => 'AUDIT_FAILED'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$fileSize = filesize($auditFile);
$fileLines = count(file($auditFile));

// Безопасная сводка — проверяем на возможные секреты
$secretsFound = [];
$secretLines = [];
if (is_readable($auditFile)) {
    $lines = file($auditFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $lineNum => $line) {
        if (preg_match('/(password|token|secret|api[_-]?key)\s*=\s*(\S+)/i', $line, $m)) {
            $secretsFound[] = trim($m[1]);
            // Маскируем значение
            $secretLines[] = "  Строка " . ($lineNum + 1) . ": " . preg_replace('/(=\s*)(\S+)/', '$1[скрыто]', $line);
        }
    }
}

echo json_encode([
    'ok' => true,
    'data' => [
        'file' => basename($auditFile),
        'size' => $fileSize,
        'lines' => $fileLines,
        'download_url' => "/api/audit.php?slug=" . urlencode($slug) . "&file=" . urlencode(basename($auditFile)),
        'secrets_found' => array_unique($secretsFound),
        'secret_warnings' => array_slice($secretLines, 0, 5),
    ],
], JSON_UNESCAPED_UNICODE);
