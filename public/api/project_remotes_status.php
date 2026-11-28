<?php
declare(strict_types=1);

/**
 * API статусов remote проекта: какие хосты имеют репозиторий.
 * GET: ?slug=archive
 * Ответ: {ok: true, data: [{remote, url, exists}]}
 */

require_once __DIR__ . '/../lib/marketplace-core.php';

header('Content-Type: application/json; charset=utf-8');

$slug = trim((string) ($_GET['slug'] ?? ''));
if ($slug === '' || !preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $slug)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректный slug'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$hubRoot = dirname(__DIR__);
$repo = $hubRoot . '/projects/' . $slug;
if (!is_dir($repo . '/.git')) {
    echo json_encode(['ok' => false, 'error' => 'Проект не найден'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

// Получить список remote
exec(sprintf('git -C %s remote 2>/dev/null', escapeshellarg($repo)), $remotes, $rc);

$result = [];
foreach ($remotes as $remote) {
    $remote = trim($remote);
    if ($remote === '') continue;
    
    $url = trim(shell_exec(sprintf(
        'git -C %s remote get-url -- %s 2>/dev/null',
        escapeshellarg($repo),
        escapeshellarg($remote)
    )));
    
    // Проверка через ls-remote (с таймаутом 5 сек)
    $exists = false;
    $cmd = sprintf(
        'GIT_TERMINAL_PROMPT=0 timeout 5 git ls-remote -- %s HEAD >/dev/null 2>&1 && echo ok || echo fail',
        escapeshellarg($url)
    );
    $out = trim(shell_exec($cmd));
    $exists = ($out === 'ok');
    
    $result[] = [
        'remote' => $remote,
        'url' => $url,
        'exists' => $exists,
    ];
}

echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);