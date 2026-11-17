<?php

declare(strict_types=1);

/**
 * API версий хаба: status | refresh | update | backups | rollback.
 * Статус читается из файлов .git без exec; действия делегируются bin-скриптам.
 * Портативно: edge берётся из gitverse, иначе origin, иначе первого remote с tracking-ссылкой.
 * Метка версии: data/version.txt, иначе тег на HEAD, иначе <stable>-r<hash6>.
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

$head     = gitRead($gd, 'HEAD') ?? '';
$branch   = str_starts_with($head, 'ref: refs/heads/') ? substr(trim(substr($head, 5)), 11) : null;
$localSha = $branch !== null
    ? refSha($gd, 'refs/heads/' . $branch)
    : (preg_match('/^[0-9a-f]{40}$/', $head) === 1 ? $head : null);

// --- Remote для edge: gitverse -> origin -> первый с tracking-ссылкой ---------
$config = gitRead($gd, 'config') ?? '';
preg_match_all('/^\[remote "([^"]+)"\]/m', $config, $rm);
$remotes = $rm[1] ?? [];
$preferred = [];
foreach (['gitverse', 'origin'] as $p) {
    if (in_array($p, $remotes, true)) {
        $preferred[] = $p;
    }
}
foreach ($remotes as $r) {
    if (!in_array($r, $preferred, true)) {
        $preferred[] = $r;
    }
}
$edgeSha = null;
$edgeRemote = null;
if ($branch !== null) {
    foreach ($preferred as $r) {
        $sha = refSha($gd, 'refs/remotes/' . $r . '/' . $branch);
        if ($sha !== null) {
            $edgeSha = $sha;
            $edgeRemote = $r;
            break;
        }
    }
}

// --- Теги: packed-refs + loose, с peeled-ссылками аннотированных тегов --------
$tags = [];
$peel = [];
$packed = gitRead($gd, 'packed-refs') ?? '';
$lastTag = null;
foreach (explode("\n", $packed) as $line) {
    $line = trim($line);
    if (preg_match('/^([0-9a-f]{40})\s+refs\/tags\/(.+)$/', $line, $m) === 1 && !str_ends_with($m[2], '^{}')) {
        $tags[$m[2]] = $m[1];
        $lastTag = $m[2];
        continue;
    }
    if ($lastTag !== null && preg_match('/^\^([0-9a-f]{40})$/', $line, $m) === 1) {
        $peel[$lastTag] = $m[1];
        $lastTag = null;
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
$tagCommit = static function (string $t) use ($tags, $peel): string {
    return $peel[$t] ?? $tags[$t];
};
$stableTags = array_values(array_filter(array_keys($tags), static fn(string $t): bool => preg_match('/^\d+\.\d+\.\d+$/', $t) === 1));
usort($stableTags, static fn(string $a, string $b): int => version_compare($b, $a));
$stableTag = $stableTags[0] ?? null;
$stableSha = $stableTag !== null ? $tagCommit($stableTag) : null;

// --- Метки версий -------------------------------------------------------------
$versionFile  = $hubRoot . '/data/version.txt';
$currentLabel = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '';
if ($currentLabel === '' && $localSha !== null) {
    $tagAtHead = null;
    foreach ($stableTags as $t) {
        if ($tagCommit($t) === $localSha) {
            $tagAtHead = $t;
            break;
        }
    }
    $currentLabel = $tagAtHead ?? ($stableTag !== null ? $stableTag . '-r' . substr($localSha, 0, 6) : substr($localSha, 0, 7));
}
$edgeLabel = $stableTag !== null && $edgeSha !== null
    ? ($edgeSha === $stableSha ? $stableTag : $stableTag . '-r' . substr($edgeSha, 0, 6))
    : null;

// --- GET status ----------------------------------------------------------------
if ($action === 'status') {
    respond(true, [
        'branch'           => $branch,
        'current_label'    => $currentLabel,
        'local_sha'        => $localSha !== null ? substr($localSha, 0, 7) : null,
        'remote'           => $edgeRemote,
        'stable'           => $stableTag !== null ? ['label' => $stableTag, 'sha' => substr((string) $stableSha, 0, 7)] : null,
        'edge'             => $edgeLabel !== null ? ['label' => $edgeLabel, 'sha' => substr((string) $edgeSha, 0, 7)] : null,
        'update_available' => $edgeSha !== null && $localSha !== null && $edgeSha !== $localSha,
        'note'             => 'Данные последнего fetch. Нажмите «Проверить» для fetch.',
    ]);
}

// --- POST refresh ---------------------------------------------------------------
if ($action === 'refresh') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, [], 'Метод не разрешён', 'METHOD_NOT_ALLOWED');
    }
    $script = $hubRoot . '/bin/gitproject-fetch.sh';
    if (!is_executable($script)) {
        respond(false, [], 'bin/gitproject-fetch.sh не найден', 'SCRIPT_ERROR');
    }
    $force = isset($_GET['force']) && $_GET['force'] === '1';
    $cmd = escapeshellarg($script) . ($force ? ' --force' : '');
    $output = [];
    $rc = 0;
    exec($cmd . ' 2>&1', $output, $rc);
    $log = stripAnsi(implode("\n", $output));
    if ($rc !== 0) {
        respond(false, ['log' => $log], 'Fetch не удался', 'FETCH_FAILED');
    }
    respond(true, ['log' => $log]);
}

// --- POST update ------------------------------------------------------------------
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

// --- GET backups -------------------------------------------------------------------
if ($action === 'backups') {
    $script = $hubRoot . '/bin/gitproject-rollback.sh';
    if (!is_executable($script)) {
        respond(false, [], 'bin/gitproject-rollback.sh не найден', 'SCRIPT_ERROR');
    }
    $output = [];
    $rc = 0;
    exec(escapeshellarg($script) . ' list 2>&1', $output, $rc);
    $rows = [];
    foreach ($output as $line) {
        $parts = explode("\t", $line, 3);
        if (count($parts) === 3 && str_starts_with($parts[0], 'backup/pre-')) {
            $rows[] = ['branch' => $parts[0], 'sha' => $parts[1], 'date' => $parts[2]];
        }
    }
    respond(true, ['backups' => $rows]);
}

// --- POST rollback -------------------------------------------------------------------
if ($action === 'rollback') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, [], 'Метод не разрешён', 'METHOD_NOT_ALLOWED');
    }
    $raw = (string) file_get_contents('php://input');
    if (strlen($raw) > 2048) {
        respond(false, [], 'Тело запроса слишком большое', 'BODY_TOO_LARGE');
    }
    $input  = json_decode($raw, true);
    $branch = is_array($input) ? (string) ($input['branch'] ?? '') : '';
    if (preg_match('/^backup\/pre-[a-z]+-\d{8}-\d{6}(-\d+)?$/', $branch) !== 1) {
        respond(false, [], 'Допустимы только ветки backup/pre-*', 'INVALID_BRANCH');
    }
    $script = $hubRoot . '/bin/gitproject-rollback.sh';
    if (!is_executable($script)) {
        respond(false, [], 'bin/gitproject-rollback.sh не найден', 'SCRIPT_ERROR');
    }
    $output = [];
    $rc = 0;
    exec(escapeshellarg($script) . ' ' . escapeshellarg($branch) . ' --yes 2>&1', $output, $rc);
    $log = stripAnsi(implode("\n", $output));
    if ($rc !== 0) {
        respond(false, ['log' => $log], 'Откат не удался', 'ROLLBACK_FAILED');
    }
    respond(true, ['log' => $log]);
}

respond(false, [], 'Неизвестное действие', 'INVALID_ACTION');