<?php
declare(strict_types=1);

/**
 * API: добавить remote проекту
 * POST /api/projects/:slug/remotes/add.php
 *
 * Тело JSON:
 * {
 *   "domain": "gitverse.ru",
 *   "owner": "sokol491"  // опционально, берётся из .env если не указан
 * }
 */

header('Content-Type: application/json; charset=utf-8');

function strip_ansi(string $text): string {
    return preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $text) ?? $text;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Метод не разрешён', 'error_code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if ($input === null) {
    echo json_encode(['ok' => false, 'error' => 'Некорректный JSON', 'error_code' => 'INVALID_JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$slug     = trim($input['slug'] ?? '');
$domain   = trim($input['domain'] ?? '');
$owner    = trim($input['owner'] ?? '');

if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректный slug', 'error_code' => 'INVALID_SLUG'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9._-]+$/', $domain)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректный домен', 'error_code' => 'INVALID_DOMAIN'], JSON_UNESCAPED_UNICODE);
    exit;
}

$workspace = dirname(__DIR__, 2);
$projectsDir = "$workspace/projects";
$hostsFile = "$workspace/config/hosts.json";
$envFile = "$workspace/.env";

$repo = "$projectsDir/$slug";
if (!is_dir("$repo/.git")) {
    echo json_encode(['ok' => false, 'error' => 'Проект не найден', 'error_code' => 'PROJECT_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Найти хост
if (!is_file($hostsFile)) {
    echo json_encode(['ok' => false, 'error' => 'hosts.json не найден', 'error_code' => 'CONFIG_MISSING'], JSON_UNESCAPED_UNICODE);
    exit;
}

$hosts = json_decode(file_get_contents($hostsFile), true);
$hostConfig = null;
$hostId = '';
foreach ($hosts as $id => $cfg) {
    if (($cfg['host'] ?? '') === $domain) {
        $hostConfig = $cfg;
        $hostId = $id;
        break;
    }
}

if ($hostConfig === null) {
    echo json_encode(['ok' => false, 'error' => "Хост '$domain' не найден в hosts.json", 'error_code' => 'HOST_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($hostConfig['status'] ?? '') !== 'ready') {
    echo json_encode(['ok' => false, 'error' => "Хост '$domain' не готов (статус: " . ($hostConfig['status'] ?? '?') . ")", 'error_code' => 'HOST_NOT_READY'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Remote name = host_id
$remoteName = $hostId;

// Проверить, не существует ли уже
$checkOutput = [];
exec('git -C ' . escapeshellarg($repo) . ' remote get-url ' . escapeshellarg($remoteName) . ' 2>&1', $checkOutput, $checkRc);
if ($checkRc === 0 && trim(implode('', $checkOutput)) !== '') {
    echo json_encode(['ok' => false, 'error' => "Remote '$remoteName' уже существует", 'error_code' => 'REMOTE_EXISTS'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Owner из .env
if ($owner === '') {
    $prefix = $hostConfig['env_prefix'] ?? strtoupper($hostId);
    $ownerVar = $prefix . '_PROJECT_USER';
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, $ownerVar . '=')) {
                $owner = trim(substr($line, strlen($ownerVar) + 1));
                break;
            }
        }
    }
}

if ($owner === '') {
    echo json_encode(['ok' => false, 'error' => "Не найден owner для хоста '$domain' в .env", 'error_code' => 'NO_OWNER'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Сформировать URL
$template = $hostConfig['url_template'] ?? '';
if ($template === '') {
    echo json_encode(['ok' => false, 'error' => "Для хоста '$domain' не задан url_template", 'error_code' => 'NO_URL_TEMPLATE'], JSON_UNESCAPED_UNICODE);
    exit;
}

$url = str_replace(['{owner}', '{slug}'], [$owner, $slug], $template);
if (!str_ends_with($url, '.git')) {
    $url .= '.git';
}

// Проверить существование репозитория на хосте
$lsRemoteOutput = [];
exec('git ls-remote ' . escapeshellarg($url) . ' 2>&1', $lsRemoteOutput, $lsRemoteRc);
$lsRemoteStr = implode('', $lsRemoteOutput);
if ($lsRemoteRc !== 0 && !preg_match('/not found|does not exist|404|Authentication failed/i', $lsRemoteStr)) {
    // Ошибка не связана с отсутствием — возможно сетевая проблема
    echo json_encode([
        'ok' => false,
        'error' => 'Не удалось проверить репозиторий',
        'error_code' => 'CHECK_FAILED',
        'data' => ['log' => strip_ansi($lsRemoteStr)],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Добавить remote
$addOutput = [];
exec('git -C ' . escapeshellarg($repo) . ' remote add ' . escapeshellarg($remoteName) . ' ' . escapeshellarg($url) . ' 2>&1', $addOutput, $addRc);
if ($addRc !== 0) {
    echo json_encode([
        'ok' => false,
        'error' => 'Не удалось добавить remote',
        'error_code' => 'ADD_FAILED',
        'data' => ['log' => strip_ansi(implode("\n", $addOutput))],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Fetch
$fetchOutput = [];
exec('git -C ' . escapeshellarg($repo) . ' fetch ' . escapeshellarg($remoteName) . ' 2>&1', $fetchOutput, $fetchRc);
$fetchLog = strip_ansi(implode("\n", $fetchOutput));

echo json_encode([
    'ok' => true,
    'data' => [
        'remote' => $remoteName,
        'url' => $url,
        'host' => $domain,
        'fetch_log' => $fetchLog,
    ],
], JSON_UNESCAPED_UNICODE);
