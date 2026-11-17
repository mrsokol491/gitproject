<?php

declare(strict_types=1);

/**
 * API маркетплейса: list | item | submit | img.
 * Без токенов. Кэш читается из SQLite; сеть — только в CLI-cron.
 */

require __DIR__ . '/../../lib/marketplace-core.php';

function mpApiJson(bool $ok, array $data = [], string $error = '', string $code = '', int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    if ($ok) {
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => false, 'error' => $error, 'error_code' => $code], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function mpApiRow(array $r): array
{
    $hosts = mpHosts();
    $h = $hosts[$r['host_id']] ?? null;
    $slug = (string) $r['slug'];
    $tags = json_decode((string) ($r['tags_json'] ?? '[]'), true);
    $topics = json_decode((string) ($r['topics_json'] ?? '[]'), true);
    return [
        'id'            => (int) $r['id'],
        'host_id'       => (string) $r['host_id'],
        'host_name'     => $h['name'] ?? (string) $r['host_id'],
        'host_domain'   => $h['domain'] ?? '',
        'owner'         => (string) $r['owner'],
        'slug'          => $slug,
        'category'      => (string) $r['category'],
        'description'   => (string) ($r['description'] ?? $r['readme_excerpt'] ?? ''),
        'stars'         => $r['stars'] !== null ? (int) $r['stars'] : null,
        'forks'         => $r['forks'] !== null ? (int) $r['forks'] : null,
        'language'      => $r['language'] !== null ? (string) $r['language'] : null,
        'license'       => $r['license'] !== null ? (string) $r['license'] : null,
        'last_release'  => $r['last_release'] !== null ? (string) $r['last_release'] : null,
        'archived'      => (int) ($r['archived'] ?? 0) === 1,
        'tags'          => is_array($tags) ? $tags : [],
        'topics'        => is_array($topics) ? $topics : [],
        'avatar'        => '/api/marketplace.php?action=img&id=' . (int) $r['id'],
        'repo_url'      => $h !== null ? sprintf($h['repo_url'], $r['owner'], $slug) : '',
        'clone_url'     => $h !== null ? sprintf($h['clone_url'], $r['owner'], $slug) : '',
        'cloned'        => is_dir(mpRoot() . '/projects/' . $slug),
        'fetched_at'    => $r['fetched_at'] !== null ? (string) $r['fetched_at'] : null,
        'fetch_error'   => $r['fetch_error'] !== null ? (string) $r['fetch_error'] : null,
    ];
}

function mpApiFilters(): array
{
    $hosts = mpHosts();
    $cat = (string) ($_GET['cat'] ?? '');
    $host = (string) ($_GET['host'] ?? '');
    $lang = (string) ($_GET['lang'] ?? '');
    $q = trim((string) ($_GET['q'] ?? ''));
    $sort = (string) ($_GET['sort'] ?? 'stars');
    $page = max(1, (int) ($_GET['page'] ?? 1));
    if ($cat !== '' && !in_array($cat, MP_CATEGORIES, true)) {
        $cat = '';
    }
    if ($host !== '' && !isset($hosts[$host])) {
        $host = '';
    }
    if ($lang !== '' && preg_match('/^[A-Za-z0-9+#. -]{1,30}$/', $lang) !== 1) {
        $lang = '';
    }
    if (strlen($q) > 80) {
        $q = substr($q, 0, 80);
    }
    if (!in_array($sort, ['stars', 'updated', 'added'], true)) {
        $sort = 'stars';
    }
    return ['cat' => $cat, 'host' => $host, 'lang' => $lang, 'q' => $q, 'sort' => $sort, 'page' => $page];
}

function mpApiWhere(array $f): array
{
    $where = ["i.status = 'approved'"];
    $args = [];
    if ($f['cat'] !== '') {
        $where[] = 'i.category = :cat';
        $args[':cat'] = $f['cat'];
    }
    if ($f['host'] !== '') {
        $where[] = 'i.host_id = :host';
        $args[':host'] = $f['host'];
    }
    if ($f['lang'] !== '') {
        $where[] = 'c.language = :lang';
        $args[':lang'] = $f['lang'];
    }
    if ($f['q'] !== '') {
        $where[] = '(i.owner LIKE :q OR i.slug LIKE :q OR c.description LIKE :q OR i.tags_json LIKE :q)';
        $args[':q'] = '%' . $f['q'] . '%';
    }
    return [implode(' AND ', $where), $args];
}

function mpApiOrder(string $sort): string
{
    return match ($sort) {
        'updated' => 'c.pushed_at DESC NULLS LAST, i.id DESC',
        'added'   => 'i.created_at DESC, i.id DESC',
        default   => 'c.stars DESC NULLS LAST, i.id DESC',
    };
}

$action = (string) ($_GET['action'] ?? 'list');
$pdo = mpDb();

if ($action === 'img') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id < 1) {
        http_response_code(404);
        exit;
    }
    $st = $pdo->prepare('SELECT i.slug, c.avatar_file FROM mp_items i LEFT JOIN mp_cache c ON c.item_id = i.id WHERE i.id = :id AND i.status = \'approved\'');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!is_array($row)) {
        http_response_code(404);
        exit;
    }
    $dir = realpath(mpRoot() . '/data/marketplace/img');
    $file = (string) ($row['avatar_file'] ?? '');
    $path = $file !== '' && $dir !== false ? realpath($dir . '/' . basename($file)) : false;
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=86400');
    if ($path !== false && $dir !== false && str_starts_with($path, $dir . '/') && is_file($path)) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            default => 'application/octet-stream',
        };
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }
    $svg = mpMonogram((string) $row['slug']);
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Content-Length: ' . (string) strlen($svg));
    echo $svg;
    exit;
}

if ($action === 'list') {
    $f = mpApiFilters();
    [$where, $args] = mpApiWhere($f);
    $per = 24;
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM mp_items i LEFT JOIN mp_cache c ON c.item_id = i.id WHERE ' . $where);
    $cnt->execute($args);
    $total = (int) $cnt->fetchColumn();
    $pages = max(1, (int) ceil($total / $per));
    $page = min($f['page'], $pages);
    $sql = 'SELECT i.*, c.description, c.readme_excerpt, c.stars, c.forks, c.language, c.license,
                   c.last_release, c.archived, c.topics_json, c.fetched_at, c.fetch_error
            FROM mp_items i LEFT JOIN mp_cache c ON c.item_id = i.id
            WHERE ' . $where . ' ORDER BY ' . mpApiOrder($f['sort']) . ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per);
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $items = array_map('mpApiRow', $st->fetchAll());
    mpApiJson(true, [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'filters' => $f,
        'categories' => MP_CATEGORIES,
        'hosts' => array_map(static fn(array $h): array => ['name' => $h['name'], 'domain' => $h['domain'], 'stats' => $h['stats']], mpHosts()),
    ]);
}

if ($action === 'item') {
    $id = (int) ($_GET['id'] ?? 0);
    $st = $pdo->prepare('SELECT i.*, c.description, c.readme_html, c.readme_excerpt, c.avatar_url, c.avatar_file,
                                c.stars, c.forks, c.open_issues, c.releases_count, c.last_release, c.topics_json,
                                c.license, c.language, c.pushed_at, c.archived, c.fetched_at, c.fetch_error
                         FROM mp_items i LEFT JOIN mp_cache c ON c.item_id = i.id
                         WHERE i.id = :id AND i.status = \'approved\'');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!is_array($row)) {
        mpApiJson(false, [], 'Запись не найдена', 'NOT_FOUND', 404);
    }
    $data = mpApiRow($row);
    $data['readme_html'] = (string) ($row['readme_html'] ?? '');
    $data['editorial_note'] = (string) ($row['editorial_note'] ?? '');
    $data['open_issues'] = $row['open_issues'] !== null ? (int) $row['open_issues'] : null;
    $data['releases_count'] = $row['releases_count'] !== null ? (int) $row['releases_count'] : null;
    $data['pushed_at'] = $row['pushed_at'] !== null ? (string) $row['pushed_at'] : null;
    $data['project_page'] = $data['cloned'] ? '/hub/project/' . rawurlencode($data['slug']) . '/' : null;
    mpApiJson(true, $data);
}

if ($action === 'submit') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        mpApiJson(false, [], 'Метод не разрешён', 'METHOD_NOT_ALLOWED', 405);
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $raw = (string) file_get_contents('php://input');
    if (strlen($raw) > 2048) {
        mpApiJson(false, [], 'Тело запроса слишком большое', 'BODY_TOO_LARGE', 400);
    }
    $in = json_decode($raw, true);
    if (!is_array($in)) {
        mpApiJson(false, [], 'Неверный JSON', 'BAD_JSON', 400);
    }
    $token = (string) ($in['csrf'] ?? '');
    if (!isset($_SESSION['mp_csrf']) || !hash_equals((string) $_SESSION['mp_csrf'], $token)) {
        mpApiJson(false, [], 'Неверный CSRF-токен', 'CSRF', 403);
    }
    $now = time();
    $times = array_values(array_filter(
        is_array($_SESSION['mp_submit_times'] ?? null) ? $_SESSION['mp_submit_times'] : [],
        static fn($t): bool => is_int($t) && $t > $now - 3600
    ));
    if (count($times) >= 5) {
        mpApiJson(false, [], 'Не более 5 предложений в час', 'RATE_LIMIT', 429);
    }
    $url = trim((string) ($in['url'] ?? ''));
    $note = trim((string) ($in['note'] ?? ''));
    $cat = (string) ($in['category'] ?? 'dev-tools');
    $parsed = mpParseRepoUrl($url);
    if ($parsed === null) {
        mpApiJson(false, [], 'Допустимы ссылки GitHub, GitVerse, GitFlic на публичный репозиторий', 'BAD_URL', 400);
    }
    $entry = ['host' => $parsed['host'], 'owner' => $parsed['owner'], 'slug' => $parsed['slug'], 'category' => $cat, 'note' => $note];
    [$ok, $msg] = mpValidateEntry($entry);
    if (!$ok) {
        mpApiJson(false, [], $msg, 'VALIDATION', 400);
    }
    $st = $pdo->prepare('INSERT INTO mp_items (host_id, owner, slug, category, editorial_note, canonical_key, status)
                         VALUES (:h, :o, :s, :c, :n, :k, \'pending\')');
    try {
        $st->execute([
            ':h' => $entry['host'], ':o' => $entry['owner'], ':s' => $entry['slug'],
            ':c' => $entry['category'], ':n' => $entry['note'],
            ':k' => strtolower($entry['owner'] . '/' . $entry['slug']),
        ]);
    } catch (PDOException) {
        mpApiJson(false, [], 'Такой проект уже в каталоге или на модерации', 'DUPLICATE', 409);
    }
    $times[] = $now;
    $_SESSION['mp_submit_times'] = $times;
    mpApiJson(true, ['id' => (int) $pdo->lastInsertId(), 'status' => 'pending', 'message' => 'Предложение отправлено владельцу на модерацию']);
}

mpApiJson(false, [], 'Неизвестное действие', 'INVALID_ACTION', 400);

/** Разбор URL репозитория только по whitelist хостов. */
function mpParseRepoUrl(string $url): ?array
{
    if (strlen($url) > 300 || preg_match('#^https://#i', $url) !== 1) {
        return null;
    }
    $p = parse_url($url);
    if (!is_array($p) || !isset($p['host'], $p['path'])) {
        return null;
    }
    $host = strtolower($p['host']);
    $path = trim($p['path'], '/');
    $path = (string) preg_replace('/\.git$/', '', $path);
    $seg = explode('/', $path);
    if ($host === 'github.com' && count($seg) >= 2) {
        return ['host' => 'github', 'owner' => $seg[0], 'slug' => $seg[1]];
    }
    if ($host === 'gitverse.ru' && count($seg) >= 2) {
        return ['host' => 'gitverse', 'owner' => $seg[0], 'slug' => $seg[1]];
    }
    if ($host === 'gitflic.ru' && count($seg) >= 3 && $seg[0] === 'project') {
        return ['host' => 'gitflic', 'owner' => $seg[1], 'slug' => $seg[2]];
    }
    return null;
}