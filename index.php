<?php

declare(strict_types=1);

/**
 * GitProject Workspace Hub — PHP-логика.
 * Сканирует projects/, собирает метаданные, определяет, какой экран показать.
 * HTML вынесен в views/home.php.
 */

/**
 * Размер каталога в байтах, исключая .git, vendor, node_modules.
 */
function dirSize(string $dir): int
{
    $size = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (str_contains($path, '/.git/')
            || str_contains($path, '/vendor/')
            || str_contains($path, '/node_modules/')) {
            continue;
        }
        if ($file->isFile()) {
            $size += (int) $file->getSize();
        }
    }
    return $size;
}

$workspaceRoot = __DIR__;
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

        $docroot    = null;
        $entryPoint = null;
        foreach ($docrootCandidates as $candidate) {
            $dir = $root . '/' . $candidate;
            if (!is_dir($dir)) {
                continue;
            }
            foreach (['index.php', 'index.html'] as $idx) {
                if (is_file($dir . '/' . $idx)) {
                    $docroot    = $candidate;
                    $entryPoint = '/hub/project/' . rawurlencode($entry) . '/';
                    break 2;
                }
            }
        }

        $type = 'unknown';
        $desc = '';
        if (is_file($root . '/composer.json')) {
            $type = 'PHP';
            $data = json_decode((string) file_get_contents($root . '/composer.json'), true);
            if (is_array($data)) {
                $desc = (string) ($data['description'] ?? '');
            }
        } elseif (is_file($root . '/package.json')) {
            $type = 'Node.js';
        }

        $dot = 'bad';
        if ($docroot === 'public') {
            $dot = 'ok';
        } elseif ($docroot !== null) {
            $dot = 'warn';
        }

        $projects[] = [
            'name'        => $entry,
            'type'        => $type,
            'description' => $desc,
            'docroot'     => $docroot,
            'dot'         => $dot,
            'entry_point' => $entryPoint,
            'modified'    => date('d.m.Y', (int) filemtime($root)),
            'size_kb'     => intdiv(dirSize($root), 1024),
            'local_version' => null,
            'git_version'   => null,
            'synced'        => null,
        ];
    }
}

usort($projects, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

// --- Определяем, какой экран показать ----------------------------------------
$project = null;

// Определяем slug из пути /hub/project/<slug>/
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path       = parse_url($requestUri, PHP_URL_PATH);
$path       = (string) (preg_replace('#/+#', '/', $path) ?? '/');
$path       = rtrim($path, '/');

if (str_starts_with($path, '/hub/project/')) {
    $slug = trim(str_replace('/hub/project/', '', $path), '/');
    $slug = rawurldecode($slug);

    // Ищем проект по slug
    foreach ($projects as $p) {
        if ($p['name'] === $slug) {
            $project = $p;
            break;
        }
    }
}

// --- Подключаем шаблон -------------------------------------------------------
include __DIR__ . '/views/home.php';
