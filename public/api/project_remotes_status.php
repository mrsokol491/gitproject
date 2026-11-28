<?php

declare(strict_types=1);

/**
 * Статусы remote проекта: существует ли репозиторий на каждом хосте.
 * GET: ?slug=archive[&refresh=1]
 * Ответ: {ok:true, data:[{remote, name, domain, url, exists}]}
 * Кэш: data/remotes-cache.json (TTL 900 с), запись атомарная.
 */

header('Content-Type: application/json; charset=utf-8');

$hubRoot = dirname(__DIR__, 2);
$slug = trim((string) ($_GET['slug'] ?? ''));
$refresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';

if ($slug === '' || preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $slug) !== 1) {
    echo json_encode(['ok' => false, 'error' => 'Некорректный slug', 'error_code' => 'INVALID_SLUG'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$repo = $hubRoot . '/projects/' . $slug;
if (!is_dir($repo . '/.git')) {
    echo json_encode(['ok' => false, 'error' => 'Проект не найден', 'error_code' => 'NOT_FOUND'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$hosts = [];
$hostsFile = $hubRoot . '/config/hosts.json';
if (is_readable($hostsFile)) {
    $decoded = json_decode((string) file_get_contents($hostsFile), true);
    if (is_array($decoded)) {
        $hosts = $decoded;
    }
}

$cacheFile = $hubRoot . '/data/remotes-cache.json';
$cache = [];
if (!$refresh && is_readable($cacheFile)) {
    $decoded = json_decode((string) file_get_contents($cacheFile), true);
    if (is_array($decoded)) {
        $cache = $decoded;
    }
}

exec(sprintf('git -C %s remote 2>/dev/null', escapeshellarg($repo)), $remoteLines, $rc);
$now = time();
$result = [];
$cacheChanged = false;

foreach ($remoteLines as $remote) {
    $remote = trim($remote);
    if ($remote === '') {
        continue;
    }

    $url = trim((string) shell_exec(sprintf(
        'git -C %s remote get-url -- %s 2>/dev/null',
        escapeshellarg($repo),
        escapeshellarg($remote)
    )));

    $domain = '';
    $parts = parse_url($url);
    if (is_array($parts) && isset($parts['host'])) {
        $domain = (string) $parts['host'];
    }

    $name = $remote;
    if (isset($hosts[$remote]['description']) && is_string($hosts[$remote]['description'])) {
        $desc = $hosts[$remote]['description'];
        $pos = strpos($desc, ' —');
        $name = $pos === false ? $desc : substr($desc, 0, $pos);
    } elseif ($domain !== '') {
        foreach ($hosts as $h) {
            if (($h['host'] ?? '') === $domain && isset($h['description'])) {
                $desc = (string) $h['description'];
                $pos = strpos($desc, ' —');
                $name = $pos === false ? $desc : substr($desc, 0, $pos);
                break;
            }
        }
    }

    $key = $slug . '/' . $remote;
    if (isset($cache[$key]['ttl'], $cache[$key]['exists']) && (int) $cache[$key]['ttl'] > $now) {
        $exists = (bool) $cache[$key]['exists'];
    } else {
        $cmd = sprintf(
            'GIT_TERMINAL_PROMPT=0 timeout 8 git ls-remote -- %s HEAD >/dev/null 2>&1; echo $?',
            escapeshellarg($url)
        );
        $exit = (int) trim((string) shell_exec($cmd));
        $exists = ($exit === 0);
        $cache[$key] = ['ttl' => $now + 900, 'exists' => $exists];
        $cacheChanged = true;
    }

    $result[] = [
        'remote' => $remote,
        'name' => $name,
        'domain' => $domain,
        'url' => $url,
        'exists' => $exists,
    ];
}

if ($cacheChanged) {
    $dir = dirname($cacheFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $tmp = tempnam($dir, 'rcache');
    if ($tmp !== false) {
        file_put_contents($tmp, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        rename($tmp, $cacheFile);
    }
}

echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);