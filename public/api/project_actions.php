<?php

declare(strict_types=1);

/**
 * API страницы проекта: GET ?action=log&slug=X | POST ?action=description {slug, description}.
 * Логи — exec git log (паттерн sync.php). Описание — атомарная запись composer/package.json.
 */

header('Content-Type: application/json; charset=utf-8');

function respond(bool $ok, array $payload, string $error = '', string $code = ''): never
{
    if ($ok) {
        echo json_encode(['ok' => true, 'data' => $payload], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => false, 'error' => $error, 'error_code' => $code, 'data' => $payload], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$hubRoot = dirname(__DIR__, 2);
$action  = (string) ($_GET['action'] ?? '');
$slug    = (string) ($_GET['slug'] ?? '');

$raw = (string) file_get_contents('php://input');
if (strlen($raw) > 2048) {
    respond(false, [], 'Тело запроса слишком большое', 'BODY_TOO_LARGE');
}
$input = $raw !== '' ? json_decode($raw, true) : null;
if ($action === 'description' && is_array($input)) {
    $slug = (string) ($input['slug'] ?? '');
}

if (preg_match('/^[a-zA-Z0-9_-]+$/', $slug) !== 1) {
    respond(false, [], 'Некорректное имя проекта', 'INVALID_SLUG');
}
$repo = $hubRoot . '/projects/' . $slug;
$realProjects = realpath($hubRoot . '/projects');
$realRepo     = realpath($repo);
if ($realProjects === false || $realRepo === false || !str_starts_with($realRepo . '/', $realProjects . '/')) {
    respond(false, [], 'Проект не найден', 'NOT_FOUND');
}

if ($action === 'log') {
    if (!is_dir($realRepo . '/.git')) {
        respond(false, [], 'Проект не является git-репозиторием', 'NOT_GIT');
    }
    $cmd = sprintf(
        'git -C %s log -20 --date=format:%s --pretty=format:%s 2>&1',
        escapeshellarg($realRepo),
        escapeshellarg('%d.%m.%Y %H:%M'),
        escapeshellarg('%h%x1f%ad%x1f%an%x1f%s')
    );
    $output = [];
    $rc = 0;
    exec($cmd, $output, $rc);
    if ($rc !== 0) {
        respond(false, [], 'Не удалось прочитать историю', 'GIT_LOG_FAILED');
    }
    $commits = [];
    foreach ($output as $line) {
        $parts = explode("\x1f", $line, 4);
        if (count($parts) === 4) {
            $commits[] = ['sha' => $parts[0], 'date' => $parts[1], 'author' => $parts[2], 'subject' => $parts[3]];
        }
    }
    respond(true, ['commits' => $commits]);
}

if ($action === 'description') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, [], 'Метод не разрешён', 'METHOD_NOT_ALLOWED');
    }
    $desc = is_array($input) ? (string) ($input['description'] ?? '') : '';
    if (mb_strlen($desc) > 500) {
        respond(false, [], 'Описание длиннее 500 символов', 'DESC_TOO_LONG');
    }
    $metaFile = null;
    if (is_file($realRepo . '/composer.json')) {
        $metaFile = $realRepo . '/composer.json';
    } elseif (is_file($realRepo . '/package.json')) {
        $metaFile = $realRepo . '/package.json';
    }
    if ($metaFile === null) {
        respond(false, [], 'Нет composer.json или package.json', 'NO_META');
    }
    $data = json_decode((string) file_get_contents($metaFile), true);
    if (!is_array($data)) {
        respond(false, [], 'Мета-файл повреждён', 'BAD_META');
    }
    $data['description'] = $desc;
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        respond(false, [], 'Ошибка кодирования JSON', 'JSON_ERROR');
    }
    // Атомарная запись (урок php-security)
    $tmp = tempnam(dirname($metaFile), 'meta_');
    if ($tmp === false || file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        respond(false, [], 'Ошибка записи', 'WRITE_FAILED');
    }
    if (!rename($tmp, $metaFile)) {
        @unlink($tmp);
        respond(false, [], 'Ошибка rename', 'WRITE_FAILED');
    }
    respond(true, ['description' => $desc, 'file' => basename($metaFile)]);
}

if ($action === 'export') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, [], 'Метод не разрешён', 'METHOD_NOT_ALLOWED');
    }
    if (!is_dir($realRepo . '/.git')) {
        respond(false, [], 'Проект не является git-репозиторием', 'NOT_GIT');
    }
    $script = $hubRoot . '/bin/gitproject-export.sh';
    if (!is_executable($script)) {
        respond(false, [], 'bin/gitproject-export.sh не найден', 'SCRIPT_ERROR');
    }
    $output = [];
    $rc = 0;
    exec(escapeshellarg($script) . ' ' . escapeshellarg($slug) . ' 2>/dev/null', $output, $rc);
    $path = trim(implode('', $output));
    if ($rc !== 0 || $path === '' || !is_file($path)) {
        respond(false, [], 'Экспорт не удался (лимит 100 МБ или ошибка git)', 'EXPORT_FAILED');
    }
    respond(true, [
        'file' => basename($path),
        'size' => (int) filesize($path),
        'download_url' => '/api/project_actions.php?action=download&slug=' . rawurlencode($slug) . '&file=' . rawurlencode(basename($path)),
    ]);
}

if ($action === 'download') {
    $file = (string) ($_GET['file'] ?? '');
    if (preg_match('/^[a-zA-Z0-9_-]+-\d{8}-\d{6}(-\d+)?\.zip$/', $file) !== 1) {
        respond(false, [], 'Некорректное имя файла', 'INVALID_FILE');
    }
    if (!str_starts_with($file, $slug . '-')) {
        respond(false, [], 'Файл не принадлежит проекту', 'FILE_MISMATCH');
    }
    $dir = realpath($hubRoot . '/data/exports');
    $real = realpath($hubRoot . '/data/exports/' . $file);
    if ($dir === false || $real === false || !str_starts_with($real, $dir . '/') || !is_file($real)) {
        respond(false, [], 'Файл не найден', 'NOT_FOUND');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($real) . '"');
    header('Content-Length: ' . (string) filesize($real));
    header('X-Content-Type-Options: nosniff');
    readfile($real);
    exit;
}

respond(false, [], 'Неизвестное действие', 'INVALID_ACTION');