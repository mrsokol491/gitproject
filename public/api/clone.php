<?php
declare(strict_types=1);

/**
 * API: клонирование проекта
 * POST /api/clone.php
 *
 * Тело JSON:
 * {
 *   "host": "gitflic.ru",
 *   "owner": "sokol491",
 *   "slug": "my-project",
 *   "local_name": "my-project" // опционально, по умолчанию = slug
 * }
 */

header('Content-Type: application/json; charset=utf-8');

function strip_ansi(string $text): string {
    return preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $text) ?? $text;
}

// --- Метод и тело запроса ----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Метод не разрешён', 'error_code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if ($input === null) {
    echo json_encode(['ok' => false, 'error' => 'Некорректный JSON', 'error_code' => 'INVALID_JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$host     = trim($input['host'] ?? '');
$owner    = trim($input['owner'] ?? '');
$slug     = trim($input['slug'] ?? '');
$localName = trim($input['local_name'] ?? $slug);

// --- Валидация ---------------------------------------------------------------

if (!preg_match('/^[a-zA-Z0-9._-]+$/', $host)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректный хост', 'error_code' => 'INVALID_HOST'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9._-]+$/', $owner)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректный owner', 'error_code' => 'INVALID_OWNER'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректный slug проекта', 'error_code' => 'INVALID_SLUG'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9_-]+$/', $localName)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректное локальное имя', 'error_code' => 'INVALID_LOCAL_NAME'], JSON_UNESCAPED_UNICODE);
    exit;
}

$workspace = dirname(__DIR__, 2);
$projectsDir = "$workspace/projects";
$hostsFile = "$workspace/config/hosts.json";

// --- Проверка хоста ----------------------------------------------------------

if (!is_file($hostsFile)) {
    echo json_encode(['ok' => false, 'error' => 'hosts.json не найден', 'error_code' => 'CONFIG_MISSING'], JSON_UNESCAPED_UNICODE);
    exit;
}

$hosts = json_decode(file_get_contents($hostsFile), true);
if (!is_array($hosts)) {
    echo json_encode(['ok' => false, 'error' => 'hosts.json некорректен', 'error_code' => 'CONFIG_INVALID'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Найти хост по домену
$hostConfig = null;
foreach ($hosts as $id => $cfg) {
    if (($cfg['host'] ?? '') === $host) {
        $hostConfig = $cfg;
        break;
    }
}

if ($hostConfig === null) {
    echo json_encode(['ok' => false, 'error' => "Хост '$host' не найден в hosts.json", 'error_code' => 'HOST_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
    exit;
}

$status = $hostConfig['status'] ?? '';
if ($status !== 'ready') {
    echo json_encode(['ok' => false, 'error' => "Хост '$host' не готов (статус: $status)", 'error_code' => 'HOST_NOT_READY'], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Сформировать URL репозитория --------------------------------------------

$template = $hostConfig['url_template'] ?? '';
if ($template === '') {
    echo json_encode(['ok' => false, 'error' => "Для хоста '$host' не задан url_template", 'error_code' => 'NO_URL_TEMPLATE'], JSON_UNESCAPED_UNICODE);
    exit;
}

$url = str_replace(['{owner}', '{slug}'], [$owner, $slug], $template);
if (!str_ends_with($url, '.git')) {
    $url .= '.git';
}

// --- Проверка: проект не существует локально ---------------------------------

$targetDir = "$projectsDir/$localName";

if (is_dir($targetDir)) {
    // Проверить, является ли уже git-репозиторием
    if (is_dir("$targetDir/.git")) {
        echo json_encode(['ok' => false, 'error' => "Каталог '$localName' уже существует и является git-репозиторием", 'error_code' => 'DIR_EXISTS'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => false, 'error' => "Каталог '$localName' уже существует", 'error_code' => 'DIR_EXISTS'], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Выполнить клонирование --------------------------------------------------

$envFile = "$workspace/.env";
$envVars = [];

// Собрать переменные окружения из .env для credential helper
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            $envVars[$m[1]] = $m[2];
        }
    }
}

// Построить строку env для exec
$envString = '';
foreach ($envVars as $key => $value) {
    $envString .= ' ' . escapeshellarg("$key=" . escapeshellarg($value));
}

$prefix = $hostConfig['env_prefix'] ?? strtoupper($id);
$envString .= ' GIT_HOST=' . escapeshellarg($host);

$cmd = sprintf(
    'cd %s && GIT_PROJECT_WORKSPACE=%s env %s git clone %s %s 2>&1',
    escapeshellarg($projectsDir),
    escapeshellarg($workspace),
    ltrim($envString),
    escapeshellarg($url),
    escapeshellarg($localName)
);

$output = [];
$returnCode = 0;
exec($cmd, $output, $returnCode);
$log = strip_ansi(implode("\n", $output));

if ($returnCode !== 0) {
    echo json_encode([
        'ok' => false,
        'error' => 'Клонирование не удалось',
        'error_code' => 'CLONE_FAILED',
        'data' => ['log' => $log],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Ответ -------------------------------------------------------------------

echo json_encode([
    'ok' => true,
    'data' => [
        'slug' => $localName,
        'name' => $localName,
        'url' => $url,
        'host' => $host,
        'path' => $targetDir,
        'log' => $log,
    ],
], JSON_UNESCAPED_UNICODE);
