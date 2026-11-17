<?php

declare(strict_types=1);

/**
 * API версий хаба: GET ?action=status | POST ?action=update {channel, confirm}.
 * Читает .git без exec для статуса; обновление делегирует bin/gitproject-update.sh.
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

function stripAnsi(string $text): string
{
    return (string) preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $text);
}

$hubRoot = dirname(__DIR__, 2);
$action  = (string) ($_GET['action'] ?? 'status');

// --- Чтение .git без exec ----------------------------------------------------
function gitRead(string $gd, string $rel): ?string
{
    $p = $gd . '/' . $rel;
    if (!is_file($p)) {
        return null;
    }
    $c = @file_get_contents($p);
    return $c === false ? null : trim($c);
}

function refSha(string $gd, string $ref): ?string
{
    $loose = gitRead($gd, $ref);
    if ($loose !== null && preg_match('/^[0-9a-f]{40}$/', $loose) === 1) {
        return $loose;
    }
    $packed = gitRead($gd, 'packed-refs');
    if ($packed !== null) {
        foreach (explode("\n", $packed) as $line) {
            if (preg_match('/^([0-9a-f]{40})\s+(.+)$/', trim($line), $m) === 1 && $m[2] === $ref) {
                return $m[1];
            }
        }
    }
    return null;
}

$gd = $hubRoot . '/.git';
if (!is_dir($gd)) {
    respond(false, [], 'Хаб не является git-репозиторием', 'NOT_GIT');
}

$head   = gitRead($gd, 'HEAD') ?? '';
$branch = str_starts_with($head, 'ref: refs/heads/') ? substr(trim(substr($head, 5)), 11) : null;
$localSha = $branch !== null ? refSha($gd, 'refs/heads/' . $branch) : (preg_match('/^[0-9a-f]{40}$/', $head) === 1 ? $head : null);
$edgeSha  = $branch !== null ? refSha($gd, 'refs/remotes/gitverse/' . $branch) : null;

// Теги из packed-refs и loose refs/tags
$tags = [];
$packed = gitRead($gd, 'packed-refs') ?? '';
foreach (explode("\n", $packed) as $line) {
    if (preg_match('/^([0-9a-f]{40})\s+refs\/tags\/(.+)$/', trim($line), $m) === 1 && !str_ends_with($m[2], '^{}')) {
        $tags[$m[2]] = $m[1];
    }
}
if (is_dir($gd . '/refs/tags')) {
    foreach (scandir($gd . '/refs/tags') ?: [] as $t) {
        if ($t === '.' || $t === '..') {
            continue;
        }
        $sha = refSha($gd, 'refs/tags/' . $t);
        if ($sha !== null) {
            $tags[$t] = $sha;
        }
    }
}
$stableTags = array_filter(array_keys($tags), static fn(string $t): bool => preg_match('/^\d+\.\d+\.\d+$/', $t) === 1);
usort($stableTags, static fn(string $a, string $b): int => version_compare($b, $a));
$stableTag = $stableTags[0] ?? null;
$stableSha = $stableTag !== null ? $tags[$stableTag] : null;

$versionFile = $hubRoot . '/data/version.txt';
$currentLabel = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '';
if ($currentLabel === '' && $localSha !== null) {
    $currentLabel = substr($localSha, 0, 7);
}
$edgeLabel = $stableTag !== null && $edgeSha !== null ? $stableTag . '-r' . substr($edgeSha, 0, 6) : null;

// --- GET status ---------------------------------------------------------------
if ($action === 'status') {
    respond(true, [
        'branch'         => $branch,
        'current_label'  => $currentLabel,
        'local_sha'      => $localSha !== null ? substr($localSha, 0, 7) : null,
        'stable'         => $stableTag !== null ? ['label' => $stableTag, 'sha' => substr((string) $stableSha, 0, 7)] : null,
        'edge'           => $edgeLabel !== null ? ['label' => $edgeLabel, 'sha' => substr((string) $edgeSha, 0, 7)] : null,
        'update_available' => $edgeSha !== null && $localSha !== null && $edgeSha !== $localSha,
        'note'           => 'Данные последнего fetch. Нажмите «Проверить» для fetch.',
    ]);
}

// --- POST refresh: лёгкий fetch через скрипт (только теги+ветка) ---------------
if ($action === 'refresh') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, [], 'Метод не разрешён', 'METHOD_NOT_ALLOWED');
    }
    $script = $hubRoot . '/bin/gitproject-fetch.sh';
    if (!is_executable($script)) {
        respond(false, [], 'bin/gitproject-fetch.sh не найден', 'SCRIPT_ERROR');
    }
    $output = [];
    $rc = 0;
    exec(escapeshellarg($script) . ' 2>&1', $output, $rc);
    $log = stripAnsi(implode("\n", $output));
    if ($rc !== 0) {
        respond(false, ['log' => $log], 'Fetch не удался', 'FETCH_FAILED');
    }
    respond(true, ['log' => $log]);
}

// --- POST update ---------------------------------------------------------------
if ($action === 'update') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, [], 'Метод не разрешён', 'METHOD_NOT_ALLOWED');
    }
    $raw = (string) file_get_contents('php://input');
    if (strlen($raw) > 2048) {
        respond(false, [], 'Тело запроса слишком большое', 'BODY_TOO_LARGE');
    }
    $input   = json_decode($raw, true);
    $channel = is_array($input) ? (string) ($input['channel'] ?? '') : '';
    $confirm = is_array($input) ? (string) ($input['confirm'] ?? '') : '';
    if (!in_array($channel, ['stable', 'edge'], true)) {
        respond(false, [], 'Канал: stable или edge', 'INVALID_CHANNEL');
    }
    // Для edge — явное подтверждение словом (урок cli-wizard-ux-patterns)
    if ($channel === 'edge' && $confirm !== 'EDGE') {
        respond(false, [], 'Для edge введите confirm=EDGE (нестабильная версия)', 'CONFIRM_REQUIRED');
    }
    $script = $hubRoot . '/bin/gitproject-update.sh';
    if (!is_executable($script)) {
        respond(false, [], 'bin/gitproject-update.sh не найден', 'SCRIPT_ERROR');
    }
    $cmd = escapeshellarg($script) . ' ' . escapeshellarg($channel) . ' --yes';
    $output = [];
    $rc = 0;
    exec($cmd . ' 2>&1', $output, $rc);
    $log = stripAnsi(implode("\n", $output));
    if ($rc !== 0) {
        respond(false, ['log' => $log], 'Обновление не удалось', 'UPDATE_FAILED');
    }
    $newLabel = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '';
    respond(true, ['log' => $log, 'version' => $newLabel]);
}

respond(false, [], 'Неизвестное действие', 'INVALID_ACTION');