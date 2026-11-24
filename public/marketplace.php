<?php

declare(strict_types=1);

/**
 * Маркетплейс: серверный рендер сетки и страницы записи.
 * Роутер §1.7 направляет /marketplace и /marketplace/item/<id>/ сюда.
 */

require __DIR__ . '/../lib/marketplace-core.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['mp_csrf'])) {
    $_SESSION['mp_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string) $_SESSION['mp_csrf'];

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; img-src 'self' https: data:; style-src 'self'; script-src 'self'; connect-src 'self'; base-uri 'self'; frame-ancestors 'none'");

function mpViewFmt(?int $n): string
{
    if ($n === null) {
        return '—';
    }
    if ($n >= 1000000) {
        return rtrim(rtrim(number_format($n / 1000000, 1, '.', ''), '0'), '.') . 'M';
    }
    if ($n >= 1000) {
        return rtrim(rtrim(number_format($n / 1000, 1, '.', ''), '0'), '.') . 'k';
    }
    return (string) $n;
}

function mpViewAgo(?string $iso): string
{
    if ($iso === null || $iso === '') {
        return 'ещё не загружено';
    }
    $ts = strtotime($iso);
    if ($ts === false) {
        return 'давно';
    }
    $d = time() - $ts;
    if ($d < 3600) {
        return max(1, intdiv($d, 60)) . ' мин назад';
    }
    if ($d < 86400) {
        return intdiv($d, 3600) . ' ч назад';
    }
    return intdiv($d, 86400) . ' дн назад';
}

function mpViewCat(string $cat): string
{
    return match ($cat) {
        'ux-ui' => '🎨 UX/UI',
        'components' => '🧩 Компоненты',
        'learning' => '📚 Обучение',
        'self-hosted' => '🚀 Self-hosted',
        default => '🛠 Инструменты',
    };
}

function mpViewHostClass(string $host): string
{
    return match ($host) {
        'github' => 'mp-host--github',
        'gitflic' => 'mp-host--gitflic',
        default => 'mp-host--gitverse',
    };
}

$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/marketplace/');
$path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/marketplace/');
$path = (string) preg_replace('#/+#', '/', $path);

if ($path === '/marketplace') {
    header('Location: /marketplace/', true, 301);
    exit;
}

$pdo = mpDb();
$hosts = mpHosts();
$categories = MP_CATEGORIES;

if (preg_match('#^/marketplace/item/(\d+)/?$#', $path, $m) === 1) {
    $st = $pdo->prepare('SELECT i.*, c.description, c.readme_html, c.readme_excerpt, c.avatar_file,
                                c.stars, c.forks, c.open_issues, c.releases_count, c.last_release, c.topics_json,
                                c.license, c.language, c.pushed_at, c.archived, c.fetched_at, c.fetch_error
                         FROM mp_items i LEFT JOIN mp_cache c ON c.item_id = i.id
                         WHERE i.id = :id AND i.status = \'approved\'');
    $st->execute([':id' => (int) $m[1]]);
    $row = $st->fetch();
    if (!is_array($row)) {
        http_response_code(404);
        $item = null;
        include __DIR__ . '/../views/marketplace-item.php';
        exit;
    }
    $h = $hosts[$row['host_id']];
    $slug = (string) $row['slug'];
    $item = [
        'id' => (int) $row['id'],
        'host_id' => (string) $row['host_id'],
        'host_name' => $h['name'],
        'host_domain' => $h['domain'],
        'host_stats' => (bool) $h['stats'],
        'owner' => (string) $row['owner'],
        'slug' => $slug,
        'category' => (string) $row['category'],
        'description' => (string) ($row['description'] ?? $row['readme_excerpt'] ?? ''),
        'editorial_note' => (string) ($row['editorial_note'] ?? ''),
        'readme_html' => (string) ($row['readme_html'] ?? ''),
        'stars' => $row['stars'] !== null ? (int) $row['stars'] : null,
        'forks' => $row['forks'] !== null ? (int) $row['forks'] : null,
        'open_issues' => $row['open_issues'] !== null ? (int) $row['open_issues'] : null,
        'releases_count' => $row['releases_count'] !== null ? (int) $row['releases_count'] : null,
        'last_release' => $row['last_release'] !== null ? (string) $row['last_release'] : null,
        'license' => $row['license'] !== null ? (string) $row['license'] : null,
        'language' => $row['language'] !== null ? (string) $row['language'] : null,
        'pushed_at' => $row['pushed_at'] !== null ? (string) $row['pushed_at'] : null,
        'archived' => (int) ($row['archived'] ?? 0) === 1,
        'topics' => is_array(json_decode((string) ($row['topics_json'] ?? '[]'), true)) ? json_decode((string) $row['topics_json'], true) : [],
        'tags' => is_array(json_decode((string) ($row['tags_json'] ?? '[]'), true)) ? json_decode((string) $row['tags_json'], true) : [],
        'fetched_at' => $row['fetched_at'] !== null ? (string) $row['fetched_at'] : null,
        'fetch_error' => $row['fetch_error'] !== null ? (string) $row['fetch_error'] : null,
        'repo_url' => sprintf($h['repo_url'], $row['owner'], $slug),
        'clone_url' => sprintf($h['clone_url'], $row['owner'], $slug),
        'cloned' => is_dir(mpRoot() . '/projects/' . $slug),
        'project_page' => is_dir(mpRoot() . '/projects/' . $slug) ? '/hub/project/' . rawurlencode($slug) . '/' : null,
        'avatar' => '/api/marketplace.php?action=img&id=' . (int) $row['id'],
    ];
    include __DIR__ . '/../views/marketplace-item.php';
    exit;
}

$cat = (string) ($_GET['cat'] ?? '');
$host = (string) ($_GET['host'] ?? '');
$lang = (string) ($_GET['lang'] ?? '');
$q = trim((string) ($_GET['q'] ?? ''));
$sort = (string) ($_GET['sort'] ?? 'stars');
$page = max(1, (int) ($_GET['page'] ?? 1));
if ($cat !== '' && !in_array($cat, $categories, true)) {
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
$filters = ['cat' => $cat, 'host' => $host, 'lang' => $lang, 'q' => $q, 'sort' => $sort];

$where = ["i.status = 'approved'"];
$args = [];
if ($cat !== '') {
    $where[] = 'i.category = :cat';
    $args[':cat'] = $cat;
}
if ($host !== '') {
    $where[] = 'i.host_id = :host';
    $args[':host'] = $host;
}
if ($lang !== '') {
    $where[] = 'c.language = :lang';
    $args[':lang'] = $lang;
}
if ($q !== '') {
    $where[] = '(i.owner LIKE :q OR i.slug LIKE :q OR c.description LIKE :q OR i.tags_json LIKE :q)';
    $args[':q'] = '%' . $q . '%';
}
$whereSql = implode(' AND ', $where);
$order = match ($sort) {
    'updated' => 'c.pushed_at DESC NULLS LAST, i.id DESC',
    'added' => 'i.created_at DESC, i.id DESC',
    default => 'c.stars DESC NULLS LAST, i.id DESC',
};
$per = 24;
$cnt = $pdo->prepare('SELECT COUNT(*) FROM mp_items i LEFT JOIN mp_cache c ON c.item_id = i.id WHERE ' . $whereSql);
$cnt->execute($args);
$total = (int) $cnt->fetchColumn();
$pages = max(1, (int) ceil($total / $per));
$page = min($page, $pages);
$st = $pdo->prepare('SELECT i.*, c.description, c.readme_excerpt, c.stars, c.forks, c.language, c.archived,
                            c.topics_json, c.fetched_at, c.fetch_error
                     FROM mp_items i LEFT JOIN mp_cache c ON c.item_id = i.id
                     WHERE ' . $whereSql . ' ORDER BY ' . $order . ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
$st->execute($args);
$items = [];
foreach ($st->fetchAll() as $r) {
    $h = $hosts[$r['host_id']];
    $slug = (string) $r['slug'];
    $tags = json_decode((string) ($r['tags_json'] ?? '[]'), true);
    $items[] = [
        'id' => (int) $r['id'],
        'host_id' => (string) $r['host_id'],
        'host_name' => $h['name'],
        'owner' => (string) $r['owner'],
        'slug' => $slug,
        'category' => (string) $r['category'],
        'description' => (string) ($r['description'] ?? $r['readme_excerpt'] ?? ''),
        'stars' => $r['stars'] !== null ? (int) $r['stars'] : null,
        'forks' => $r['forks'] !== null ? (int) $r['forks'] : null,
        'language' => $r['language'] !== null ? (string) $r['language'] : null,
        'archived' => (int) ($r['archived'] ?? 0) === 1,
        'tags' => is_array($tags) ? $tags : [],
        'fetched_at' => $r['fetched_at'] !== null ? (string) $r['fetched_at'] : null,
        'avatar' => '/api/marketplace.php?action=img&id=' . (int) $r['id'],
        'page' => '/marketplace/item/' . (int) $r['id'] . '/',
        'cloned' => is_dir(mpRoot() . '/projects/' . $slug),
    ];
}
$languages = $pdo->query('SELECT DISTINCT c.language FROM mp_cache c JOIN mp_items i ON i.id = c.item_id
                          WHERE i.status = \'approved\' AND c.language IS NOT NULL AND c.language != \'\'
                          ORDER BY c.language')->fetchAll(PDO::FETCH_COLUMN);
$pendingCount = (int) $pdo->query('SELECT COUNT(*) FROM mp_items WHERE status = \'pending\'')->fetchColumn();

include __DIR__ . '/../views/marketplace.php';