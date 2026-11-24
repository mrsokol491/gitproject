<?php

declare(strict_types=1);

/**
 * API чтения карточки маркетплейса: GET ?host=&owner=&slug=.
 * Без SQLite: живой fetch raw README + мета через lib/marketplace-core.php.
 * Кэш не пишется; аватар игнорируется (клиент рисует монограмму).
 */

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../../lib/marketplace-core.php';

function mrJson(bool $ok, array $data = [], string $error = '', string $code = '', int $status = 200): never
{
    http_response_code($status);
    header('X-Content-Type-Options: nosniff');
    echo $ok
        ? json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE)
        : json_encode(['ok' => false, 'error' => $error, 'error_code' => $code], JSON_UNESCAPED_UNICODE);
    exit;
}

$host  = (string) ($_GET['host'] ?? '');
$owner = (string) ($_GET['owner'] ?? '');
$slug  = (string) ($_GET['slug'] ?? '');

[$ok, $msg] = mpValidateEntry(['host' => $host, 'owner' => $owner, 'slug' => $slug, 'category' => 'dev-tools', 'note' => '']);
if (!$ok) {
    mrJson(false, [], $msg, 'VALIDATION', 400);
}

$c = mpFetchItem(['id' => 0, 'host_id' => $host, 'owner' => $owner, 'slug' => $slug, 'default_branch' => 'master']);
if ($c['readme_html'] === null) {
    mrJson(false, [], $c['fetch_error'] ?? 'README недоступен', 'FETCH_FAILED', 502);
}

mrJson(true, [
    'html'        => $c['readme_html'],
    'description' => $c['description'],
    'stars'       => $c['stars'],
    'forks'       => $c['forks'],
    'language'    => $c['language'],
    'license'     => $c['license'],
    'pushed_at'   => $c['pushed_at'],
    'archived'    => $c['archived'],
    'warning'     => $c['fetch_error'],
]);