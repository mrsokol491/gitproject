<?php
declare(strict_types=1);

/**
 * API: клонирование проекта с выбранного хоста
 * POST /api/clone.php
 *
 * Тело JSON:
 *   {
 *     "host_id": "gitflic",       // id хоста из hosts.json
 *     "owner": "sokol491",        // владелец репозитория
 *     "slug": "my-project",       // имя репозитория
 *     "local_name": ""            // опционально: локальное имя каталога (по умолчанию = slug)
 *   }
 */

header('Content-Type: application/json; charset=utf-8');

function strip_ansi($text) {
    return preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $text);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Метод не разрешён', 'error_code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$input = json_decode(file_get_contents('php://input'), true);

$hostId = $input['host_id'] ?? '';
$owner  = $input['owner'] ?? '';
$slug   = $input['slug'] ?? '';
$localName = $input['local_name'] ?? '';

// --- Валидация ------------------------------------------------------------
if (!preg_match('/^[a-z]+$/', $hostId)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректный ID хоста', 'error_code' => 'INVALID_HOST'], JSON_UNESCAPED_UNICODE);
    exit(1);
}
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $owner)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректное имя владельца', 'error_code' => 'INVALID_OWNER'], JSON_UNESCAPED_UNICODE);
    exit(1);
}
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректное имя проекта', 'error_code' => 'INVALID_SLUG'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$localName = $localName !== '' ? $localName : $slug;
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $localName)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректное локальное имя', 'error_code' => 'INVALID_LOCAL_NAME'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$workspace = dirname(__DIR__, 2);
$projectsDir = "$workspace/projects";
$hostsFile = "$workspace/config/hosts.json";
$envFile = "$workspace/.env";

// --- Проверка конфигурации хоста ------------------------------------------
if (!is_file($hostsFile) || !is_file($envFile)) {
    echo json_encode(['ok' => false, 'error' => 'Конфигурация не найдена', 'error_code' => 'CONFIG_MISSING'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$hosts = json_decode(file_get_contents($hostsFile), true);
if (!isset($hosts[$hostId])) {
    echo json_encode(['ok' => false, 'error' => "Хост '$hostId' не найден в hosts.json", 'error_code' => 'HOST_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$hostConfig = $hosts[$hostId];
$domain = $hostConfig['host'] ?? '';
$urlTemplate = $hostConfig['url_template'] ?? '';

if ($domain === '' || $urlTemplate === '') {
    echo json_encode(['ok' => false, 'error' => "Хост '$hostId' не настроен (нет host/url_template)", 'error_code' => 'HOST_INCOMPLETE'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

// --- Проверка токена -------------------------------------------------------
$prefix = $hostConfig['env_prefix'] ?? strtoupper($hostId);
$envContent = file_get_contents($envFile);

// Ищем PROJECT_TOKEN (для клонирования проектов нужен PROJECT_TOKEN)
$hasToken = (bool) preg_match("/^{$prefix}_PROJECT_TOKEN=(?!placeholder$).+/m", $envContent);
if (!$hasToken) {
    // Fallback: HUB_TOKEN
    $hasToken = (bool) preg_match("/^{$prefix}_HUB_TOKEN=(?!placeholder$).+/m", $envContent);
}
if (!$hasToken) {
    echo json_encode(['ok' => false, 'error' => "Токен для хоста '$hostId' не настроен в .env", 'error_code' => 'TOKEN_MISSING'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

// --- Формируем URL репозитория ---------------------------------------------
$url = str_replace(['{owner}', '{slug}'], [$owner, $slug], $urlTemplate);
$url = rtrim($url, '.git'); // убираем .git для проверки ls-remote
$url .= '.git';

// --- Проверка существования репозитория ------------------------------------
$output = [];
$rc = 0;
exec("git ls-remote " . escapeshellarg($url) . " 2>&1", $output, $rc);
if ($rc !== 0 || empty(implode('', $output))) {
    echo json_encode([
        'ok' => false,
        'error' => "Репозиторий $owner/$slug не найден на $domain",
        'error_code' => 'REPO_NOT_FOUND',
        'data' => ['log' => strip_ansi(implode("\n", $output))]
    ], JSON_UNESCAPED_UNICODE);
    exit(1);
}

// --- Проверка существующего каталога ---------------------------------------
$targetDir = "$projectsDir/$localName";
if (is_dir($targetDir)) {
    if (is_dir("$targetDir/.git")) {
        echo json_encode([
            'ok' => false,
            'error' => "Каталог '$localName' уже существует и является git-репозиторием",
            'error_code' => 'DIR_EXISTS',
            'data' => ['existing' => true, 'local_name' => $localName]
        ], JSON_UNESCAPED_UNICODE);
        exit(0);
    }
    // Каталог есть, но не git — используем другое имя
    $localName = $slug . '-' . $hostId;
    $targetDir = "$projectsDir/$localName";
}

// --- Клонирование ----------------------------------------------------------
$cloneUrl = str_replace(['{owner}', '{slug}'], [$owner, $slug], $urlTemplate);

mkdir($projectsDir, 0755, true);

$log = [];
$log[] = "Клонирование $owner/$slug -> $localName";
$log[] = "URL: $cloneUrl";

$output = [];
exec("git clone \"$cloneUrl\" \"$targetDir\" 2>&1", $output, $rc);
$log[] = strip_ansi(implode("\n", $output));

if ($rc !== 0) {
    echo json_encode([
        'ok' => false,
        'error' => 'Ошибка клонирования',
        'error_code' => 'CLONE_FAILED',
        'data' => ['log' => $log]
    ], JSON_UNESCAPED_UNICODE);
    exit(1);
}

// --- Fetch origin ----------------------------------------------------------
$output = [];
@exec("git -C " . escapeshellarg($targetDir) . " fetch origin 2>&1", $output, $rc);
$log[] = "fetch origin: " . strip_ansi(implode("\n", $output));

// --- Получаем информацию о проекте -----------------------------------------
$branch = '';
$commits = 0;
$output = [];
@exec("git -C " . escapeshellarg($targetDir) . " branch --show-current 2>&1", $output, $rc);
if ($rc === 0) $branch = trim(implode('', $output));

$output = [];
@exec("git -C " . escapeshellarg($targetDir) . " rev-list --count HEAD 2>&1", $output, $rc);
if ($rc === 0 && is_numeric(implode('', $output))) $commits = (int) implode('', $output);

// --- Remotes после клонирования --------------------------------------------
$remotes = [];
$output = [];
@exec("git -C " . escapeshellarg($targetDir) . " remote -v 2>&1", $output, $rc);
if ($rc === 0) {
    foreach ($output as $line) {
        if (preg_match('/^(\S+)\t+(\S+)\s+\(fetch\)$/', $line, $m)) {
            $remoteName = $m[1];
            $remoteUrl = $m[2];
            $host = '';
            if (preg_match('#https?://([^/]+)#', $remoteUrl, $um)) {
                $host = $um[1];
            }
            $remotes[$remoteName] = [
                'url' => $remoteUrl,
                'host' => $host,
            ];
        }
    }
}

echo json_encode([
    'ok' => true,
    'data' => [
        'slug' => $localName,
        'name' => "$owner/$slug",
        'branch' => $branch,
        'commits' => $commits,
        'remotes' => $remotes,
        'log' => $log,
    ],
], JSON_UNESCAPED_UNICODE);
