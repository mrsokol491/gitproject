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
    $script = $hubRoot . '/bin/project/gitproject-export.sh';
    if (!is_executable($script)) {
        respond(false, [], 'bin/project/gitproject-export.sh не найден', 'SCRIPT_ERROR');
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

// --- GET status: ветка, грязь, ahead/behind по remote -------------------------
if ($action === 'status') {
    if (!is_dir($realRepo . '/.git')) {
        respond(false, [], 'Проект не является git-репозиторием', 'NOT_GIT');
    }
    $run = static function (string $cmd): array {
        $out = [];
        $rc = 0;
        exec($cmd . ' 2>&1', $out, $rc);
        return [$rc, implode("\n", $out)];
    };
    $g = 'git -C ' . escapeshellarg($realRepo) . ' ';
    [, $branchRaw] = $run($g . 'branch --show-current');
    $branch = trim($branchRaw);
    [, $dirtyRaw] = $run($g . 'status --porcelain');
    $dirtyLines = $dirtyRaw === '' ? [] : explode("\n", $dirtyRaw);
    [, $remoteRaw] = $run($g . 'remote');
    $remotes = [];
    foreach (array_filter(explode("\n", $remoteRaw)) as $r) {
        $r = trim($r);
        if (preg_match('/^[A-Za-z0-9_-]+$/', $r) !== 1) {
            continue;
        }
        $ref = 'refs/remotes/' . $r . '/' . $branch;
        [$rcB, $behind] = $run($g . 'rev-list --count ' . escapeshellarg('HEAD..' . $ref));
        [$rcA, $ahead] = $run($g . 'rev-list --count ' . escapeshellarg($ref . '..HEAD'));
        $remotes[] = [
            'name' => $r,
            'behind' => $rcB === 0 ? (int) $behind : null,
            'ahead' => $rcA === 0 ? (int) $ahead : null,
        ];
    }
    respond(true, [
        'branch' => $branch,
        'dirty_count' => count($dirtyLines),
        'dirty' => array_slice($dirtyLines, 0, 50),
        'remotes' => $remotes,
    ]);
}

// --- GET files: листинг каталога (read-only) ----------------------------------
if ($action === 'files') {
    $rel = (string) ($_GET['path'] ?? '');
    $target = paPathWithin($realRepo, $rel);
    if ($target === null || !is_dir($target)) {
        respond(false, [], 'Каталог не найден', 'NOT_FOUND');
    }
    $entries = [];
    foreach (scandir($target) ?: [] as $e) {
        if ($e === '.' || $e === '..' || paHidden($e)) {
            continue;
        }
        $p = $target . '/' . $e;
        $isDir = is_dir($p);
        $entries[] = ['name' => $e, 'dir' => $isDir, 'size' => $isDir ? null : (int) filesize($p)];
        if (count($entries) >= 500) {
            break;
        }
    }
    usort($entries, static function (array $a, array $b): int {
        if ($a['dir'] !== $b['dir']) {
            return $a['dir'] ? -1 : 1;
        }
        return strcasecmp($a['name'], $b['name']);
    });
    respond(true, ['path' => $rel, 'entries' => $entries]);
}

// --- GET file: просмотр текстового файла --------------------------------------
if ($action === 'file') {
    $rel = (string) ($_GET['path'] ?? '');
    $target = paPathWithin($realRepo, $rel);
    if ($target === null || !is_file($target)) {
        respond(false, [], 'Файл не найден', 'NOT_FOUND');
    }
    $size = (int) filesize($target);
    if ($size > 262144) {
        respond(false, [], 'Файл больше 256 КБ — просмотр запрещён', 'TOO_LARGE');
    }
    $content = (string) file_get_contents($target);
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', substr($content, 0, 8000)) === 1
        || preg_match('//u', $content) !== 1) {
        respond(false, [], 'Бинарный или не-UTF-8 файл — просмотр запрещён', 'BINARY');
    }
    respond(true, ['path' => $rel, 'size' => $size, 'content' => $content]);
}

// --- GET meta: текущие мета-поля ----------------------------------------------
if ($action === 'meta') {
    $metaFile = paMetaFile($realRepo);
    if ($metaFile === null) {
        respond(false, [], 'Нет composer.json или package.json', 'NO_META');
    }
    $data = json_decode((string) file_get_contents($metaFile), true);
    if (!is_array($data)) {
        respond(false, [], 'Мета-файл повреждён', 'BAD_META');
    }
    $kw = $data['keywords'] ?? [];
    $lic = $data['license'] ?? '';
    respond(true, [
        'file' => basename($metaFile),
        'version' => (string) ($data['version'] ?? ''),
        'homepage' => (string) ($data['homepage'] ?? ''),
        'license' => is_array($lic) ? implode(', ', $lic) : (string) $lic,
        'keywords' => is_array($kw) ? array_values(array_filter($kw, 'is_string')) : [],
    ]);
}

// --- POST metadata: атомарная запись мета-полей -------------------------------
if ($action === 'metadata') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, [], 'Метод не разрешён', 'METHOD_NOT_ALLOWED');
    }
    $metaFile = paMetaFile($realRepo);
    if ($metaFile === null) {
        respond(false, [], 'Нет composer.json или package.json', 'NO_META');
    }
    $data = json_decode((string) file_get_contents($metaFile), true);
    if (!is_array($data)) {
        respond(false, [], 'Мета-файл повреждён', 'BAD_META');
    }
    $in = is_array($input) ? $input : [];
    $version = trim((string) ($in['version'] ?? ''));
    $homepage = trim((string) ($in['homepage'] ?? ''));
    $license = trim((string) ($in['license'] ?? ''));
    $keywords = array_values(array_filter(array_map('trim', explode(',', (string) ($in['keywords'] ?? ''))), static fn(string $s): bool => $s !== ''));
    if ($version !== '' && preg_match('/^[0-9A-Za-z.\-+]{1,50}$/', $version) !== 1) {
        respond(false, [], 'Версия: до 50 символов, буквы/цифры/./-/-+', 'VALIDATION');
    }
    if ($homepage !== '' && preg_match('#^https?://\S{1,200}$#', $homepage) !== 1) {
        respond(false, [], 'Homepage: http(s)-ссылка до 200 символов', 'VALIDATION');
    }
    if (strlen($license) > 100) {
        respond(false, [], 'License: до 100 символов', 'VALIDATION');
    }
    if (count($keywords) > 20) {
        respond(false, [], 'Keywords: не более 20', 'VALIDATION');
    }
    foreach ($keywords as $k) {
        if (strlen($k) > 50) {
            respond(false, [], 'Keyword длиннее 50 символов', 'VALIDATION');
        }
    }
    foreach (['version' => $version, 'homepage' => $homepage, 'license' => $license] as $key => $val) {
        if ($val === '') {
            unset($data[$key]);
        } else {
            $data[$key] = $val;
        }
    }
    if ($keywords === []) {
        unset($data['keywords']);
    } else {
        $data['keywords'] = $keywords;
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        respond(false, [], 'Ошибка кодирования JSON', 'JSON_ERROR');
    }
    $tmp = tempnam(dirname($metaFile), 'meta_');
    if ($tmp === false || file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        if ($tmp !== false) {
            @unlink($tmp);
        }
        respond(false, [], 'Ошибка записи', 'WRITE_FAILED');
    }
    if (!rename($tmp, $metaFile)) {
        @unlink($tmp);
        respond(false, [], 'Ошибка rename', 'WRITE_FAILED');
    }
    respond(true, ['file' => basename($metaFile), 'version' => $version, 'homepage' => $homepage, 'license' => $license, 'keywords' => $keywords]);
}

/** Путь внутри проекта: без '..'/абсолютных, скрытые сегменты запрещены. */
function paPathWithin(string $repo, string $rel): ?string
{
    if (strlen($rel) > 500) {
        return null;
    }
    $rel = str_replace('\\', '/', $rel);
    if (str_starts_with($rel, '/')) {
        return null;
    }
    foreach (explode('/', $rel) as $s) {
        if ($s === '' || $s === '.') {
            continue;
        }
        if ($s === '..' || paHidden($s)) {
            return null;
        }
    }
    $real = realpath($repo . '/' . $rel);
    if ($real === false || !str_starts_with($real . '/', $repo . '/')) {
        return null;
    }
    return $real;
}

function paHidden(string $name): bool
{
    if (in_array($name, ['.git', 'data', 'storage', 'node_modules', 'vendor'], true)) {
        return true;
    }
    return str_starts_with($name, '.env');
}

function paMetaFile(string $repo): ?string
{
    if (is_file($repo . '/composer.json')) {
        return $repo . '/composer.json';
    }
    if (is_file($repo . '/package.json')) {
        return $repo . '/package.json';
    }
    return null;
}

// --- GET check: живое сравнение с сервером (fetch + ahead/behind) -------------
if ($action === 'check') {
    if (!is_dir($realRepo . '/.git')) {
        respond(false, [], 'Проект не является git-репозиторием', 'NOT_GIT');
    }
    $force = isset($_GET['force']) && $_GET['force'] === '1';
    $markDir = $hubRoot . '/data/project-check';
    if (!is_dir($markDir)) {
        @mkdir($markDir, 0770, true);
    }
    $mark = $markDir . '/' . $slug . '.txt';
    $g = 'git -C ' . escapeshellarg($realRepo) . ' ';
    $run = static function (string $cmd): array {
        $o = [];
        $rc = 0;
        exec('timeout 30 ' . $cmd . ' 2>&1', $o, $rc);
        return [$rc, trim(implode("\n", $o))];
    };
    [, $branch] = $run($g . 'branch --show-current');
    [, $remoteRaw] = $run($g . 'remote');
    $remotes = array_values(array_filter(array_map('trim', explode("\n", $remoteRaw)), static fn(string $r): bool => preg_match('/^[A-Za-z0-9_-]+$/', $r) === 1));
    $primary = in_array('origin', $remotes, true) ? 'origin' : ($remotes[0] ?? '');
    $result = [
        'branch' => $branch, 'remote' => $primary, 'remotes' => $remotes,
        'local' => null, 'server' => null, 'ahead' => null, 'behind' => null,
        'state' => 'no-remote', 'fetched' => false, 'checked_at' => gmdate('c'),
    ];
    if ($branch === '' || $primary === '') {
        respond(true, $result);
    }
    $last = is_file($mark) ? (int) trim((string) file_get_contents($mark)) : 0;
    if ($force || (time() - $last) > 900) {
        [$rcF] = $run($g . 'fetch ' . escapeshellarg($primary) . ' ' . escapeshellarg($branch) . ' --tags --force');
        $result['fetched'] = $rcF === 0;
        if ($rcF === 0) {
            file_put_contents($mark, (string) time(), LOCK_EX);
        }
    }
    [$rcL, $localSha] = $run($g . 'rev-parse HEAD');
    $ref = 'refs/remotes/' . $primary . '/' . $branch;
    [$rcS, $serverSha] = $run($g . 'rev-parse ' . escapeshellarg($ref));
    $dateArg = escapeshellarg('--date=format:%d.%m.%Y %H:%M');
    if ($rcL === 0) {
        [, $d] = $run($g . 'log -1 ' . $dateArg . ' --format=%cd HEAD');
        $result['local'] = ['sha' => substr($localSha, 0, 7), 'date' => $d];
    }
    if ($rcS === 0) {
        [, $d] = $run($g . 'log -1 ' . $dateArg . ' --format=%cd ' . escapeshellarg($ref));
        $result['server'] = ['sha' => substr($serverSha, 0, 7), 'date' => $d];
    }
    if ($rcL === 0 && $rcS === 0) {
        [, $b] = $run($g . 'rev-list --count ' . escapeshellarg('HEAD..' . $ref));
        [, $a] = $run($g . 'rev-list --count ' . escapeshellarg($ref . '..HEAD'));
        $result['behind'] = (int) $b;
        $result['ahead'] = (int) $a;
        $result['state'] = $localSha === $serverSha ? 'synced' : ((int) $a === 0 ? 'behind' : ((int) $b === 0 ? 'ahead' : 'diverged'));
    } elseif ($rcL === 0) {
        $result['state'] = 'no-server-branch';
    }
    respond(true, $result);
}

respond(false, [], 'Неизвестное действие', 'INVALID_ACTION');