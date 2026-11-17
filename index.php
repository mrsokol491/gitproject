<?php

declare(strict_types=1);

/**
 * GitProject Workspace Hub — PHP-логика (issue #10).
 * Сканирует projects/, собирает метаданные, определяет, какой экран показать.
 * HTML — в views/home.php, стили — в assets/style.css.
 * Git-данные читаются из файлов .git без exec (STANDARD.md §9: disable_functions).
 * HUB_ROOT позволяет docroot-лицу клона показывать рабочее пространство хаба.
 */

function dirSize(string $dir): int
{
    $size = 0;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (str_contains($path, '/.git/')
                || str_contains($path, '/vendor/')
                || str_contains($path, '/node_modules/')) {
                continue;
            }
            if ($file->isLink()) {
                continue;
            }
            if ($file->isFile()) {
                $size += (int) $file->getSize();
            }
        }
    } catch (Throwable) {
        // Недоступные подкаталоги не должны ронять главную страницу.
    }
    return $size;
}

function humanSize(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, '.', '') . ' MB';
    }
    return intdiv($bytes, 1024) . ' KB';
}

function gitDir(string $root): ?string
{
    $dot = $root . '/.git';
    if (is_dir($dot)) {
        return $dot;
    }
    if (is_file($dot)) {
        $content = (string) @file_get_contents($dot);
        if (preg_match('/^gitdir:\s*(.+)$/m', $content, $m) === 1) {
            $path = trim($m[1]);
            $abs = str_starts_with($path, '/') ? $path : $root . '/' . $path;
            $real = realpath($abs);
            return $real === false ? null : $real;
        }
    }
    return null;
}

function gitRead(string $gitDir, string $rel): ?string
{
    $path = $gitDir . '/' . $rel;
    if (!is_file($path)) {
        return null;
    }
    $content = @file_get_contents($path);
    return $content === false ? null : trim($content);
}

function gitRefSha(string $gitDir, string $ref): ?string
{
    $loose = gitRead($gitDir, $ref);
    if ($loose !== null && preg_match('/^[0-9a-f]{40}$/', $loose) === 1) {
        return $loose;
    }
    $packed = gitRead($gitDir, 'packed-refs');
    if ($packed !== null) {
        foreach (explode("\n", $packed) as $line) {
            if (preg_match('/^([0-9a-f]{40})\s+(.+)$/', trim($line), $m) === 1 && $m[2] === $ref) {
                return $m[1];
            }
        }
    }
    return null;
}

function gitRefDate(string $gitDir, string $ref): ?string
{
    $path = $gitDir . '/' . $ref;
    $ts = is_file($path) ? (int) filemtime($path) : 0;
    if ($ts === 0) {
        $packed = $gitDir . '/packed-refs';
        $ts = is_file($packed) ? (int) filemtime($packed) : 0;
    }
    return $ts > 0 ? date('d.m.Y', $ts) : null;
}

/**
 * Локальная и серверная версии по ссылкам .git.
 * Серверная = tracking-ссылка последнего fetch (без сети и без exec).
 */
function gitVersions(string $root): array
{
    $result = [
        'branch'        => null,
        'local_sha'     => null,
        'local_date'    => null,
        'server_sha'    => null,
        'server_date'   => null,
        'server_remote' => null,
        'synced'        => null,
    ];
    $gd = gitDir($root);
    if ($gd === null) {
        return $result;
    }
    $head = gitRead($gd, 'HEAD');
    if ($head === null) {
        return $result;
    }
    if (str_starts_with($head, 'ref: ')) {
        $ref = trim(substr($head, 5));
        $result['branch'] = str_starts_with($ref, 'refs/heads/') ? substr($ref, 11) : $ref;
        $result['local_sha'] = gitRefSha($gd, $ref);
        $result['local_date'] = gitRefDate($gd, $ref);
    } elseif (preg_match('/^[0-9a-f]{40}$/', $head) === 1) {
        $result['local_sha'] = $head;
    }
    if ($result['branch'] === null) {
        return $result;
    }
    $config = gitRead($gd, 'config') ?? '';
    preg_match_all('/^\[remote "([^"]+)"\]/m', $config, $m);
    foreach ($m[1] ?? [] as $remote) {
        $ref = 'refs/remotes/' . $remote . '/' . $result['branch'];
        $sha = gitRefSha($gd, $ref);
        if ($sha !== null) {
            $result['server_sha'] = $sha;
            $result['server_date'] = gitRefDate($gd, $ref);
            $result['server_remote'] = $remote;
            break;
        }
    }
    if ($result['local_sha'] !== null && $result['server_sha'] !== null) {
        $result['synced'] = $result['local_sha'] === $result['server_sha'];
    }
    return $result;
}

$workspaceRoot = (string) ($_SERVER['HUB_ROOT'] ?? __DIR__);
$projectsRoot  = $workspaceRoot . '/projects';

$docrootCandidates = ['public', 'Project', 'project', 'www', 'web', 'html'];

// --- Собираем список всех проектов -------------------------------------------
$projects = [];

if (is_dir($projectsRoot)) {
    foreach (scandir($projectsRoot) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $root = $projectsRoot . '/' . $entry;
        if (!is_dir($root)) {
            continue;
        }

        $docroot = null;
        foreach ($docrootCandidates as $candidate) {
            $dir = $root . '/' . $candidate;
            if (!is_dir($dir)) {
                continue;
            }
            foreach (['index.php', 'index.html'] as $idx) {
                if (is_file($dir . '/' . $idx)) {
                    $docroot = $candidate;
                    break 2;
                }
            }
        }

        $type     = 'unknown';
        $desc     = '';
        $version  = null;
        $metaFile = null;
        if (is_file($root . '/composer.json')) {
            $type = 'PHP';
            $metaFile = $root . '/composer.json';
        } elseif (is_file($root . '/package.json')) {
            $type = 'Node.js';
            $metaFile = $root . '/package.json';
        }
        if ($metaFile !== null) {
            $data = json_decode((string) @file_get_contents($metaFile), true);
            if (is_array($data)) {
                $desc = (string) ($data['description'] ?? '');
                $version = isset($data['version']) ? (string) $data['version'] : null;
            }
        }

        $dot = 'bad';
        if ($docroot === 'public') {
            $dot = 'ok';
        } elseif ($docroot !== null) {
            $dot = 'warn';
        }

        $bytes = dirSize($root);
        $git   = gitVersions($root);
        $mtime = (int) filemtime($root);

        $projects[] = [
            'name'          => $entry,
            'type'          => $type,
            'description'   => $desc,
            'version'       => $version,
            'docroot'       => $docroot,
            'dot'           => $dot,
            'page_url'      => '/hub/project/' . rawurlencode($entry) . '/',
            'entry_point'   => $docroot !== null ? '/' . rawurlencode($entry) . '/' : null,
            'modified'      => date('d.m.Y', $mtime),
            'modified_full' => date('d.m.Y H:i', $mtime),
            'size_kb'       => intdiv($bytes, 1024),
            'size_human'    => humanSize($bytes),
            'branch'        => $git['branch'],
            'local_version' => $git['local_sha'] !== null ? substr($git['local_sha'], 0, 7) : null,
            'local_date'    => $git['local_date'],
            'git_version'   => $git['server_sha'] !== null ? substr($git['server_sha'], 0, 7) : null,
            'git_date'      => $git['server_date'],
            'git_remote'    => $git['server_remote'],
            'synced'        => $git['synced'],
        ];
    }
}

usort($projects, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

// --- Определяем, какой экран показать ----------------------------------------
$project = null;

$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path       = parse_url($requestUri, PHP_URL_PATH);
$path       = is_string($path) ? $path : '/';
$path       = (string) (preg_replace('#/+#', '/', $path) ?? '/');
$path       = rtrim($path, '/');

if (str_starts_with($path, '/hub/project/')) {
    $slug = trim(str_replace('/hub/project/', '', $path), '/');
    $slug = rawurldecode($slug);

    foreach ($projects as $p) {
        if ($p['name'] === $slug) {
            $project = $p;
            break;
        }
    }
}

// --- Подключаем шаблон -------------------------------------------------------
include __DIR__ . '/views/home.php';