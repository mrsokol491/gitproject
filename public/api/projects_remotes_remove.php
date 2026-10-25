<?php
declare(strict_types=1);

/**
 * API: удалить remote у проекта
 * DELETE /api/projects/:slug/remotes/:remote.php
 *
 * Тело JSON:
 * {
 *   "confirm": "DELETE-GITVERSE"
 * }
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    echo json_encode(['ok' => false, 'error' => 'Метод не разрешён', 'error_code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

$slug   = trim($_GET['slug'] ?? '');
$remote = trim($_GET['remote'] ?? '');

if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректный slug', 'error_code' => 'INVALID_SLUG'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9_-]+$/', $remote)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректное имя remote', 'error_code' => 'INVALID_REMOTE'], JSON_UNESCAPED_UNICODE);
    exit;
}

// origin удалять нельзя
if ($remote === 'origin') {
    echo json_encode(['ok' => false, 'error' => 'origin удалять нельзя', 'error_code' => 'CANNOT_DELETE_ORIGIN'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$confirm = trim($input['confirm'] ?? '');
$expectedConfirm = 'DELETE-' . strtoupper($remote);

if ($confirm !== $expectedConfirm) {
    echo json_encode([
        'ok' => false,
        'error' => 'Подтверждение не совпадает',
        'error_code' => 'CONFIRM_MISMATCH',
        'data' => ['expected' => $expectedConfirm],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$workspace = dirname(__DIR__, 2);
$projectsDir = "$workspace/projects";
$repo = "$projectsDir/$slug";

if (!is_dir("$repo/.git")) {
    echo json_encode(['ok' => false, 'error' => 'Проект не найден', 'error_code' => 'PROJECT_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Проверить, существует ли remote
$checkOutput = [];
exec('git -C ' . escapeshellarg($repo) . ' remote get-url ' . escapeshellarg($remote) . ' 2>&1', $checkOutput, $checkRc);
if ($checkRc !== 0 || trim(implode('', $checkOutput)) === '') {
    echo json_encode(['ok' => false, 'error' => "Remote '$remote' не существует", 'error_code' => 'REMOTE_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Получить URL для лога
$url = trim(implode('', $checkOutput));

// Удалить remote
$removeOutput = [];
exec('git -C ' . escapeshellarg($repo) . ' remote remove ' . escapeshellarg($remote) . ' 2>&1', $removeOutput, $removeRc);

if ($removeRc !== 0) {
    echo json_encode([
        'ok' => false,
        'error' => 'Не удалось удалить remote',
        'error_code' => 'REMOVE_FAILED',
        'data' => ['log' => implode("\n", $removeOutput)],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'data' => [
        'remote' => $remote,
        'url' => $url,
        'message' => "Remote '$remote' удалён",
    ],
], JSON_UNESCAPED_UNICODE);
