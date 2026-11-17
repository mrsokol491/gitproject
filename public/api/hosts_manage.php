<?php

declare(strict_types=1);

/**
 * API управления хостами и API-ключами (новый UI): list | add | delete.
 * Пишет config/hosts.json и .env атомарно; токены не возвращает и не логирует.
 * CSRF: сессия mp_csrf обязательна для POST (общая с маркетплейсом).
 * Легаси /api/hosts.php не трогаем (панель /panel.php).
 */

header('Content-Type: application/json; charset=utf-8');

function hmJson(bool $ok, array $data = [], string $error = '', string $code = '', int $status = 200): never
{
    http_response_code($status);
    header('X-Content-Type-Options: nosniff');
    echo $ok
        ? json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE)
        : json_encode(['ok' => false, 'error' => $error, 'error_code' => $code], JSON_UNESCAPED_UNICODE);
    exit;
}

$hubRoot   = dirname(__DIR__, 2);
$hostsFile = $hubRoot . '/config/hosts.json';
$envFile   = $hubRoot . '/.env';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['mp_csrf']) || !is_string($_SESSION['mp_csrf'])) {
    $_SESSION['mp_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string) $_SESSION['mp_csrf'];

function hmAtomicWrite(string $path, string $content): bool
{
    $tmp = tempnam(dirname($path), 'hm_');
    if ($tmp === false || file_put_contents($tmp, $content, LOCK_EX) === false) {
        if ($tmp !== false) {
            @unlink($tmp);
        }
        return false;
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function hmEnvLines(string $envFile): array
{
    return is_file($envFile) ? (file($envFile, FILE_IGNORE_NEW_LINES) ?: []) : [];
}

function hmEnvGet(array $lines, string $key): string
{
    foreach ($lines as $l) {
        $t = trim($l);
        if (str_starts_with($t, $key . '=')) {
            return trim(substr($t, strlen($key) + 1));
        }
    }
    return '';
}

function hmEnvSet(array $lines, string $key, string $value): array
{
    $found = false;
    foreach ($lines as $i => $l) {
        if (str_starts_with(trim($l), $key . '=')) {
            $lines[$i] = $key . '=' . $value;
            $found = true;
        }
    }
    if (!$found) {
        $lines[] = $key . '=' . $value;
    }
    return $lines;
}

function hmEnvUnset(array $lines, string $key): array
{
    return array_values(array_filter($lines, static fn(string $l): bool => !str_starts_with(trim($l), $key . '=')));
}

$action = (string) ($_GET['action'] ?? 'list');

// --- GET list: хосты + состояние ключа + csrf для формы -----------------------
if ($action === 'list') {
    $hosts = is_file($hostsFile) ? json_decode((string) file_get_contents($hostsFile), true) : null;
    if (!is_array($hosts)) {
        hmJson(false, [], 'config/hosts.json повреждён или отсутствует', 'BAD_CONFIG');
    }
    $lines = hmEnvLines($envFile);
    $rows = [];
    foreach ($hosts as $id => $h) {
        if (!is_array($h)) {
            continue;
        }
        $prefix = (string) ($h['env_prefix'] ?? strtoupper((string) $id));
        $tok = hmEnvGet($lines, $prefix . '_HUB_TOKEN');
        $rows[] = [
            'id'          => (string) $id,
            'host'        => (string) ($h['host'] ?? ''),
            'status'      => (string) ($h['status'] ?? 'integrating'),
            'registry'    => (string) ($h['registry'] ?? 'foreign'),
            'prefix'      => $prefix,
            'description' => (string) ($h['description'] ?? ''),
            'configured'  => $tok !== '' && $tok !== 'placeholder',
        ];
    }
    hmJson(true, ['hosts' => $rows, 'csrf' => $csrf]);
}

// --- POST: add / delete --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hmJson(false, [], 'Метод не разрешён', 'METHOD_NOT_ALLOWED', 405);
}
$raw = (string) file_get_contents('php://input');
if (strlen($raw) > 4096) {
    hmJson(false, [], 'Тело запроса слишком большое', 'BODY_TOO_LARGE', 400);
}
$in = json_decode($raw, true);
if (!is_array($in) || !hash_equals($csrf, (string) ($in['csrf'] ?? ''))) {
    hmJson(false, [], 'Неверный CSRF-токен', 'CSRF', 403);
}

if ($action === 'add') {
    $id       = strtolower(trim((string) ($in['host_id'] ?? '')));
    $domain   = strtolower(trim((string) ($in['domain'] ?? '')));
    $prefix   = strtoupper(trim((string) ($in['prefix'] ?? '')));
    $registry = (string) ($in['registry'] ?? 'foreign');
    $user     = trim((string) ($in['user'] ?? ''));
    $token    = (string) ($in['token'] ?? '');
    $scope    = (string) ($in['scope'] ?? 'BOTH');

    if (preg_match('/^[a-z][a-z0-9_]{1,29}$/', $id) !== 1) {
        hmJson(false, [], 'ID: строчные латиница/цифры/_, 2–30 символов, начните с буквы', 'VALIDATION', 400);
    }
    if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain) !== 1 || strlen($domain) > 100) {
        hmJson(false, [], 'Некорректный домен', 'VALIDATION', 400);
    }
    if (preg_match('/^[A-Z][A-Z0-9_]{1,19}$/', $prefix) !== 1) {
        hmJson(false, [], 'Префикс: ЗАГЛАВНЫЕ латиница/цифры/_, 2–20 символов', 'VALIDATION', 400);
    }
    if (!in_array($registry, ['rf', 'foreign'], true)) {
        hmJson(false, [], 'Реестр: rf или foreign', 'VALIDATION', 400);
    }
    if (!in_array($scope, ['BOTH', 'HUB', 'PROJECT'], true)) {
        hmJson(false, [], 'Область: BOTH, HUB или PROJECT', 'VALIDATION', 400);
    }
    if ($user === '' || strlen($user) > 60) {
        hmJson(false, [], 'Пользователь обязателен (до 60 символов)', 'VALIDATION', 400);
    }
    $keepToken = false;
    if ($token === '') {
        $keepToken = true; // обновление без замены ключа
    } elseif (strlen($token) > 300) {
        hmJson(false, [], 'Токен длиннее 300 символов', 'VALIDATION', 400);
    }

    $hosts = is_file($hostsFile) ? json_decode((string) file_get_contents($hostsFile), true) : null;
    if (!is_array($hosts)) {
        $hosts = [];
    }
    $isNew = !isset($hosts[$id]);
    if ($isNew && $keepToken) {
        hmJson(false, [], 'Для нового хоста токен обязателен', 'VALIDATION', 400);
    }
    $old = $hosts[$id] ?? [];
    $entry = [
        'host'         => $domain,
        'status'       => (string) ($old['status'] ?? 'integrating'),
        'env_prefix'   => $prefix,
        'url_template' => (string) ($old['url_template'] ?? 'https://' . $domain . '/{owner}/{slug}.git'),
        'registry'     => $registry,
        'description'  => (string) ($old['description'] ?? $domain),
    ];
    if (isset($old['is_update_source'])) {
        $entry['is_update_source'] = $old['is_update_source'];
    }
    if (isset($old['is_mirror'])) {
        $entry['is_mirror'] = $old['is_mirror'];
    }
    $hosts[$id] = $entry;

    $json = json_encode($hosts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || !hmAtomicWrite($hostsFile, $json . "\n")) {
        hmJson(false, [], 'Ошибка записи hosts.json', 'WRITE_FAILED', 500);
    }

    $lines = hmEnvLines($envFile);
    $lines = hmEnvSet($lines, $prefix . '_HUB_USER', $user);
    if (!$keepToken) {
        $lines = hmEnvSet($lines, $prefix . '_HUB_TOKEN', $token);
    }
    if ($scope === 'PROJECT' || $scope === 'BOTH') {
        $lines = hmEnvSet($lines, $prefix . '_PROJECT_USER', $user);
        if (!$keepToken) {
            $lines = hmEnvSet($lines, $prefix . '_PROJECT_TOKEN', $token);
        }
    }
    if (!hmAtomicWrite($envFile, implode("\n", $lines) . "\n")) {
        hmJson(false, [], 'Ошибка записи .env', 'WRITE_FAILED', 500);
    }
    @chmod($envFile, 0600);
    hmJson(true, ['id' => $id, 'created' => $isNew, 'message' => $isNew ? 'Хост добавлен' : 'Хост обновлён' . ($keepToken ? ', ключ не менялся' : ', ключ заменён')]);
}

if ($action === 'delete') {
    $id      = strtolower(trim((string) ($in['host_id'] ?? '')));
    $confirm = (string) ($in['confirm'] ?? '');
    if (in_array($id, ['gitverse'], true)) {
        hmJson(false, [], 'gitverse — базовый хост обновлений, удаление запрещено', 'PROTECTED', 403);
    }
    if ($confirm !== 'DELETE-' . strtoupper($id)) {
        hmJson(false, [], 'Подтвердите кодом DELETE-' . strtoupper($id), 'CONFIRM_REQUIRED', 400);
    }
    $hosts = is_file($hostsFile) ? json_decode((string) file_get_contents($hostsFile), true) : null;
    if (!is_array($hosts) || !isset($hosts[$id])) {
        hmJson(false, [], 'Хост не найден', 'NOT_FOUND', 404);
    }
    $prefix = (string) ($hosts[$id]['env_prefix'] ?? strtoupper($id));
    unset($hosts[$id]);
    $json = json_encode($hosts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || !hmAtomicWrite($hostsFile, $json . "\n")) {
        hmJson(false, [], 'Ошибка записи hosts.json', 'WRITE_FAILED', 500);
    }
    $lines = hmEnvLines($envFile);
    foreach ([$prefix . '_HUB_USER', $prefix . '_HUB_TOKEN', $prefix . '_PROJECT_USER', $prefix . '_PROJECT_TOKEN'] as $k) {
        $lines = hmEnvUnset($lines, $k);
    }
    if (!hmAtomicWrite($envFile, implode("\n", $lines) . "\n")) {
        hmJson(false, [], 'Ошибка записи .env', 'WRITE_FAILED', 500);
    }
    @chmod($envFile, 0600);
    hmJson(true, ['id' => $id, 'message' => 'Хост удалён, его ключи стёрты из .env']);
}

hmJson(false, [], 'Неизвестное действие', 'INVALID_ACTION', 400);