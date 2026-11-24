<?php

declare(strict_types=1);

/**
 * Маркетплейс v0.1.0 — ядро: БД, хосты, fetch, markdown, аватарки.
 * Подключается из bin/marketplace.php (CLI) и public/api/marketplace.php (web).
 * БЕЗ ТОКЕНОВ: только публичные endpoint'ы (проверено 16-17.11.2026).
 * Без зависимостей: не требует composer, mbstring, внешних библиотек.
 */

const MP_CATEGORIES = ['dev-tools', 'ux-ui', 'components', 'learning', 'self-hosted'];

function mpRoot(): string
{
    return dirname(__DIR__);
}

function mpHosts(): array
{
    return [
        'gitverse' => [
            'domain'    => 'gitverse.ru',
            'name'      => 'GitVerse',
            'repo_url'  => 'https://gitverse.ru/%s/%s',
            'clone_url' => 'https://gitverse.ru/%s/%s.git',
            'api'       => 'https://gitverse.ru/api/repos/%s/%s',
            'raw'       => 'https://gitverse.ru/api/repos/%s/%s/raw/branch/%s/',
            'stats'     => true,
        ],
        'github' => [
            'domain'    => 'github.com',
            'name'      => 'GitHub',
            'repo_url'  => 'https://github.com/%s/%s',
            'clone_url' => 'https://github.com/%s/%s.git',
            'api'       => 'https://api.github.com/repos/%s/%s',
            'raw'       => 'https://raw.githubusercontent.com/%s/%s/%s/',
            'stats'     => true,
        ],
        'gitflic' => [
            'domain'    => 'gitflic.ru',
            'name'      => 'GitFlic',
            'repo_url'  => 'https://gitflic.ru/project/%s/%s',
            'clone_url' => 'https://gitflic.ru/project/%s/%s.git',
            'api'       => null,
            'raw'       => null,
            'stats'     => false,
        ],
    ];
}

function mpDb(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dir = mpRoot() . '/data';
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('data/ недоступна для записи');
    }
    $pdo = new PDO('sqlite:' . $dir . '/marketplace.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=30000');
    $pdo->exec('PRAGMA synchronous=NORMAL');
    $pdo->exec('PRAGMA foreign_keys=ON');
    mpSchema($pdo);
    return $pdo;
}

function mpSchema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS mp_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        host_id TEXT NOT NULL,
        owner TEXT NOT NULL,
        slug TEXT NOT NULL,
        default_branch TEXT NOT NULL DEFAULT \'master\',
        category TEXT NOT NULL DEFAULT \'dev-tools\',
        tags_json TEXT NOT NULL DEFAULT \'[]\',
        editorial_note TEXT NOT NULL DEFAULT \'\',
        avatar_override TEXT,
        canonical_key TEXT,
        status TEXT NOT NULL DEFAULT \'pending\',
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
        updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
        UNIQUE(host_id, owner, slug)
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS mp_cache (
        item_id INTEGER PRIMARY KEY REFERENCES mp_items(id) ON DELETE CASCADE,
        description TEXT,
        readme_html TEXT,
        readme_excerpt TEXT,
        avatar_url TEXT,
        avatar_file TEXT,
        stars INTEGER,
        forks INTEGER,
        open_issues INTEGER,
        releases_count INTEGER,
        last_release TEXT,
        topics_json TEXT,
        license TEXT,
        language TEXT,
        pushed_at TEXT,
        archived INTEGER NOT NULL DEFAULT 0,
        fetched_at TEXT,
        fetch_error TEXT
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mp_items_status ON mp_items(status, category)');
}

/** HTTPS GET без редиректов (SSRF), с лимитом размера и таймаутом. */
function mpHttp(string $url, int $maxBytes): ?array
{
    if (preg_match('#^https://#i', $url) !== 1) {
        return null;
    }
    $ctx = stream_context_create(['http' => [
        'method'          => 'GET',
        'timeout'         => 6,
        'follow_location' => 0,
        'ignore_errors'   => true,
        'header'          => "User-Agent: GitProject-Marketplace/0.1\r\nAccept: */*\r\n",
    ]]);
    $body = @file_get_contents($url, false, $ctx, 0, $maxBytes);
    if ($body === false || !isset($http_response_header) || !is_array($http_response_header)) {
        return null;
    }
    $status = 0;
    $ct = '';
    foreach ($http_response_header as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m) === 1) {
            $status = (int) $m[1];
        }
        if (stripos($h, 'content-type:') === 0) {
            $ct = strtolower(trim(substr($h, 13)));
        }
    }
    if ($status !== 200) {
        return null;
    }
    return ['body' => $body, 'ct' => $ct];
}

/** GitFlic: SHA и ветка по умолчанию через git-протокол, без токена (проверено). */
function mpGitFlicSha(string $owner, string $slug, string &$branch): ?string
{
    $url = sprintf('https://gitflic.ru/project/%s/%s.git', $owner, $slug);
    $cmd = 'GIT_TERMINAL_PROMPT=0 git ls-remote --symref ' . escapeshellarg($url) . ' HEAD 2>/dev/null';
    $out = [];
    $rc = 0;
    exec($cmd, $out, $rc);
    if ($rc !== 0) {
        return null;
    }
    $sha = null;
    foreach ($out as $line) {
        if (preg_match('#^ref:\s+refs/heads/(\S+)\s+HEAD$#', $line, $m) === 1) {
            $branch = $m[1];
        }
        if (preg_match('/^([0-9a-f]{40})\s+HEAD$/', $line, $m) === 1) {
            $sha = $m[1];
        }
    }
    return $sha;
}

/** Резолв URL: абсолютные http(s), //, относительные с dot-сегментами; прочие схемы — запрет. */
function mpResolveUrl(string $url, string $base): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $url) === 1) {
        return $url;
    }
    if (str_starts_with($url, '#')) {
        return $url;
    }
    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url) === 1) {
        return null;
    }
    $clean = static function (string $path): string {
        $seg = [];
        foreach (explode('/', $path) as $s) {
            if ($s === '' || $s === '.') {
                continue;
            }
            if ($s === '..') {
                array_pop($seg);
                continue;
            }
            $seg[] = $s;
        }
        return implode('/', $seg);
    };
    if (str_ends_with($base, '=')) {
        return $base . $clean(ltrim($url, '/'));
    }
    $p = parse_url($base);
    if (!is_array($p) || !isset($p['scheme'], $p['host'])) {
        return null;
    }
    $basePath = $p['path'] ?? '/';
    $dir = substr($basePath, 0, (int) strrpos($basePath, '/'));
    $path = str_starts_with($url, '/') ? $url : $dir . '/' . $url;
    return $p['scheme'] . '://' . $p['host'] . '/' . $clean($path);
}

/** Аватар: поле репо -> первая не-бейдж картинка README -> null (монограмма). */
function mpExtractAvatar(string $md, string $rawBase, string $repoAvatar): ?string
{
    if ($repoAvatar !== '' && preg_match('#^https://#i', $repoAvatar) === 1) {
        return $repoAvatar;
    }
    $cands = [];
    if (preg_match_all('/!\[[^\]]*\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', $md, $m) > 0) {
        $cands = array_merge($cands, $m[1]);
    }
    if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $md, $m) > 0) {
        $cands = array_merge($cands, $m[1]);
    }
    $badges = ['shields.io', 'badgen.net', 'badge.fury.io', 'travis-ci.org', 'circleci.com', '/actions/workflows/', '/workflows/'];
    foreach ($cands as $u) {
        $skip = false;
        foreach ($badges as $b) {
            if (stripos($u, $b) !== false) {
                $skip = true;
                break;
            }
        }
        if ($skip) {
            continue;
        }
        $abs = mpResolveUrl($u, $rawBase);
        if ($abs !== null && preg_match('#^https://#i', $abs) === 1) {
            return $abs;
        }
    }
    return null;
}

/** Прокси-кэш аватара: whitelist хостов, <=2 МБ, только растровые форматы (SVG отклоняется). */
function mpProxyAvatar(string $url, int $itemId): ?string
{
    $host = parse_url($url, PHP_URL_HOST);
    $allow = ['gitverse.ru', 'gitflic.ru', 'github.com', 'raw.githubusercontent.com',
        'user-images.githubusercontent.com', 'objects.githubusercontent.com', 'camo.githubusercontent.com'];
    if (!is_string($host) || !in_array(strtolower($host), $allow, true)) {
        return null;
    }
    $resp = mpHttp($url, 2097152);
    if ($resp === null) {
        return null;
    }
    $extMap = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico'];
    $ct = trim(explode(';', $resp['ct'])[0]);
    if (!isset($extMap[$ct])) {
        return null;
    }
    $dir = mpRoot() . '/data/marketplace/img';
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        return null;
    }
    foreach (glob($dir . '/' . $itemId . '.*') ?: [] as $old) {
        @unlink($old);
    }
    $file = $itemId . '.' . $extMap[$ct];
    $tmp = tempnam($dir, 'av_');
    if ($tmp === false || file_put_contents($tmp, $resp['body'], LOCK_EX) === false) {
        if ($tmp !== false) {
            @unlink($tmp);
        }
        return null;
    }
    if (!rename($tmp, $dir . '/' . $file)) {
        @unlink($tmp);
        return null;
    }
    return $file;
}

/** Локальная монограмма-SVG (безопасна: генерируется нами). */
function mpMonogram(string $name): string
{
    $clean = (string) preg_replace('/[^A-Za-z0-9]+/', '', $name);
    $ini = strtoupper(substr($clean !== '' ? $clean : 'MP', 0, 2));
    $hue = crc32($name) % 360;
    return '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96">'
        . '<rect width="96" height="96" rx="14" fill="hsl(' . $hue . ', 45%, 28%)"/>'
        . '<text x="48" y="60" font-family="-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif" '
        . 'font-size="34" font-weight="700" fill="#e8e4f3" text-anchor="middle">'
        . htmlspecialchars($ini, ENT_QUOTES) . '</text></svg>';
}

/** Inline-элементы markdown поверх УЖЕ экранированного текста (escape-first). */
function mpInline(string $e, string $rawBase): string
{
    $codes = [];
    $e = (string) preg_replace_callback('/`([^`]+)`/', static function (array $m) use (&$codes): string {
        $codes[] = '<code>' . $m[1] . '</code>';
        return "\x00" . (count($codes) - 1) . "\x00";
    }, $e);
    $e = (string) preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', static function (array $m) use ($rawBase): string {
        $url = mpResolveUrl(htmlspecialchars_decode($m[2], ENT_QUOTES), $rawBase);
        if ($url === null) {
            return $m[1];
        }
        return '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '" alt="' . $m[1] . '" loading="lazy" referrerpolicy="no-referrer">';
    }, $e);
    $e = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m) use ($rawBase): string {
        $url = mpResolveUrl(htmlspecialchars_decode($m[2], ENT_QUOTES), $rawBase);
        if ($url === null) {
            return $m[1];
        }
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
    }, $e);
    $e = (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $e);
    $e = (string) preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $e);
    $e = (string) preg_replace('/(?<!\*)\*([^*\s][^*]*)\*(?!\*)/', '<em>$1</em>', $e);
    $e = (string) preg_replace('/(?<![\w_])_([^_\s][^_]*)_(?![\w_])/', '<em>$1</em>', $e);
    $e = (string) preg_replace('/~~([^~]+)~~/', '<del>$1</del>', $e);
    $e = (string) preg_replace_callback('/\x00(\d+)\x00/', static function (array $m) use ($codes): string {
        return $codes[(int) $m[1]] ?? '';
    }, $e);
    return $e;
}

/**
 * Markdown -> HTML, escape-first: весь текст экранируется ДО трансформаций,
 * сырой HTML показывается как текст. Поддержка: заголовки #, списки, цитаты,
 * fenced-код, таблицы GFM, hr, bold/italic/strike, code, ссылки, картинки.
 */
function mpMarkdown(string $md, string $rawBase): string
{
    $md = str_replace(["\r\n", "\r", "\x00"], ["\n", "\n", ''], $md);
    if (strlen($md) > 524288) {
        $md = substr($md, 0, 524288);
    }
    $out = [];
    $para = [];
    $list = null;
    $items = [];
    $quote = [];
    $table = [];
    $code = null;

    $flushPara = static function () use (&$para, &$out, $rawBase): void {
        if ($para !== []) {
            $out[] = '<p>' . mpInline(htmlspecialchars(implode(' ', $para), ENT_QUOTES), $rawBase) . '</p>';
            $para = [];
        }
    };
    $flushList = static function () use (&$list, &$items, &$out, $rawBase): void {
        if ($list !== null) {
            $lis = '';
            foreach ($items as $it) {
                $lis .= '<li>' . mpInline(htmlspecialchars($it, ENT_QUOTES), $rawBase) . '</li>';
            }
            $out[] = '<' . $list . '>' . $lis . '</' . $list . '>';
            $list = null;
            $items = [];
        }
    };
    $flushQuote = static function () use (&$quote, &$out, $rawBase): void {
        if ($quote !== []) {
            $out[] = '<blockquote><p>' . mpInline(htmlspecialchars(implode(' ', $quote), ENT_QUOTES), $rawBase) . '</p></blockquote>';
            $quote = [];
        }
    };
    $flushTable = static function () use (&$table, &$out, $rawBase): void {
        if (count($table) >= 2 && preg_match('/^\|?[\s:\-|]+\|?$/', $table[1]) === 1) {
            $split = static function (string $row): array {
                return array_map('trim', explode('|', trim($row, "| \t")));
            };
            $html = '<table><thead><tr>';
            foreach ($split($table[0]) as $cell) {
                $html .= '<th>' . mpInline(htmlspecialchars($cell, ENT_QUOTES), $rawBase) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            $n = count($table);
            for ($i = 2; $i < $n; $i++) {
                $html .= '<tr>';
                foreach ($split($table[$i]) as $cell) {
                    $html .= '<td>' . mpInline(htmlspecialchars($cell, ENT_QUOTES), $rawBase) . '</td>';
                }
                $html .= '</tr>';
            }
            $out[] = $html . '</tbody></table>';
        } else {
            foreach ($table as $row) {
                $out[] = '<p>' . mpInline(htmlspecialchars($row, ENT_QUOTES), $rawBase) . '</p>';
            }
        }
        $table = [];
    };
    $flushAll = static function () use ($flushPara, $flushList, $flushQuote, $flushTable): void {
        $flushPara();
        $flushList();
        $flushQuote();
        $flushTable();
    };

    foreach (explode("\n", $md) as $line) {
        if ($code !== null) {
            if (preg_match('/^```/', trim($line)) === 1) {
                $out[] = '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES) . '</code></pre>';
                $code = null;
            } else {
                $code[] = $line;
            }
            continue;
        }
        $t = trim($line);
        if (str_starts_with($t, '```')) {
            $flushAll();
            $code = [];
            continue;
        }
        if ($t === '') {
            $flushAll();
            continue;
        }
        if ($table !== [] && !str_starts_with($t, '|')) {
            $flushTable();
        }
        if (str_starts_with($t, '|')) {
            $flushPara();
            $flushList();
            $flushQuote();
            $table[] = $t;
            continue;
        }
        if (preg_match('/^(#{1,6})\s+(.+)$/', $t, $m) === 1) {
            $flushAll();
            $n = strlen($m[1]);
            $out[] = '<h' . $n . '>' . mpInline(htmlspecialchars($m[2], ENT_QUOTES), $rawBase) . '</h' . $n . '>';
            continue;
        }
        if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $t) === 1) {
            $flushAll();
            $out[] = '<hr>';
            continue;
        }
        if (preg_match('/^>\s?(.*)$/', $t, $m) === 1) {
            $flushPara();
            $flushList();
            $quote[] = $m[1];
            continue;
        }
        if (preg_match('/^[-*+]\s+(.+)$/', $t, $m) === 1) {
            $flushPara();
            $flushQuote();
            if ($list !== 'ul') {
                $flushList();
                $list = 'ul';
            }
            $items[] = $m[1];
            continue;
        }
        if (preg_match('/^\d+[.)]\s+(.+)$/', $t, $m) === 1) {
            $flushPara();
            $flushQuote();
            if ($list !== 'ol') {
                $flushList();
                $list = 'ol';
            }
            $items[] = $m[1];
            continue;
        }
        $flushList();
        $flushQuote();
        $para[] = $t;
    }
    if ($code !== null) {
        $out[] = '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES) . '</code></pre>';
    }
    $flushAll();
    return implode("\n", $out);
}

/**Plain-text выжимка из README для описания/анонса. */
function mpPlainExcerpt(string $md, int $len): string
{
    $t = (string) preg_replace('/```.*?```/s', ' ', $md);
    $t = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $t);
    $t = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $t);
    $t = (string) preg_replace('/<[^>]+>/', '', $t);
    $t = (string) preg_replace('/[#>*_`|~]+/', ' ', $t);
    $t = trim((string) preg_replace('/\s+/u', ' ', $t));
    if (preg_match('/^.{0,' . $len . '}/us', $t, $m) === 1) {
        $t = $m[0];
    }
    return $t;
}

/** Fetch одной записи: мета -> ветка -> README -> описание -> аватар. */
function mpFetchItem(array $item): array
{
    $hosts = mpHosts();
    $host = $hosts[$item['host_id']] ?? null;
    $c = [
        'description' => null, 'readme_html' => null, 'readme_excerpt' => null,
        'avatar_url' => null, 'avatar_file' => null,
        'stars' => null, 'forks' => null, 'open_issues' => null,
        'releases_count' => null, 'last_release' => null,
        'topics_json' => '[]', 'license' => null, 'language' => null,
        'pushed_at' => null, 'archived' => 0, 'fetch_error' => null,
        'branch' => $item['default_branch'] !== '' ? $item['default_branch'] : 'master',
    ];
    if ($host === null) {
        $c['fetch_error'] = 'неизвестный хост';
        return $c;
    }
    $owner = $item['owner'];
    $slug = $item['slug'];
    $branch = $c['branch'];
    $metaDesc = '';
    $repoAvatar = '';

    if ($item['host_id'] === 'gitflic') {
        $sha = mpGitFlicSha($owner, $slug, $branch);
        if ($sha === null) {
            $c['fetch_error'] = 'git ls-remote: репозиторий недоступен';
            return $c;
        }
        $c['branch'] = $branch;
        $rawBase = sprintf('https://gitflic.ru/project/%s/%s/blob/raw?commit=%s&file=', $owner, $slug, $sha);
    } else {
        $resp = mpHttp(sprintf($host['api'], $owner, $slug), 262144);
        if ($resp === null) {
            $c['fetch_error'] = 'API недоступен (лимит или сеть)';
            return $c;
        }
        $meta = json_decode($resp['body'], true);
        if (!is_array($meta)) {
            $c['fetch_error'] = 'API: не JSON';
            return $c;
        }
        if ($item['host_id'] === 'gitverse') {
            $c['stars'] = isset($meta['stars_count']) ? (int) $meta['stars_count'] : null;
            $c['forks'] = isset($meta['forks_count']) ? (int) $meta['forks_count'] : null;
            $c['open_issues'] = isset($meta['open_issues_count']) ? (int) $meta['open_issues_count'] : null;
            $c['releases_count'] = isset($meta['release_counter']) ? (int) $meta['release_counter'] : null;
            $lr = $meta['last_release'] ?? null;
            $c['last_release'] = is_array($lr) ? (string) ($lr['tag_name'] ?? $lr['name'] ?? '') : (is_string($lr) ? $lr : null);
            $c['pushed_at'] = isset($meta['updated_at']) ? (string) $meta['updated_at'] : null;
            $repoAvatar = (string) ($meta['avatar_url'] ?? '');
        } else {
            $c['stars'] = isset($meta['stargazers_count']) ? (int) $meta['stargazers_count'] : null;
            $c['forks'] = isset($meta['forks_count']) ? (int) $meta['forks_count'] : null;
            $c['open_issues'] = isset($meta['open_issues_count']) ? (int) $meta['open_issues_count'] : null;
            $lic = $meta['license'] ?? null;
            $c['license'] = is_array($lic) ? (string) ($lic['spdx_id'] ?? '') : null;
            $c['pushed_at'] = isset($meta['pushed_at']) ? (string) $meta['pushed_at'] : null;
        }
        $c['archived'] = !empty($meta['archived']) ? 1 : 0;
        $c['language'] = isset($meta['language']) && is_string($meta['language']) ? $meta['language'] : null;
        $topics = isset($meta['topics']) && is_array($meta['topics'])
            ? array_values(array_filter($meta['topics'], 'is_string')) : [];
        $c['topics_json'] = (string) (json_encode($topics, JSON_UNESCAPED_UNICODE) ?: '[]');
        $metaDesc = trim((string) ($meta['description'] ?? ''));
        if (isset($meta['default_branch']) && is_string($meta['default_branch']) && $meta['default_branch'] !== '') {
            $branch = $meta['default_branch'];
        }
        $c['branch'] = $branch;
        $rawBase = sprintf($host['raw'], $owner, $slug, $branch);
    }

    $md = null;
    foreach (['README.md', 'readme.md', 'README.MD', 'Readme.md'] as $name) {
        $resp = mpHttp($rawBase . $name, 524288);
        if ($resp !== null && $resp['body'] !== '') {
            $md = $resp['body'];
            break;
        }
    }
    if ($md === null) {
        $c['fetch_error'] = 'README не найден';
        return $c;
    }
    $c['readme_html'] = mpMarkdown($md, $rawBase);
    $c['readme_excerpt'] = mpPlainExcerpt($md, 240);
    $c['description'] = $metaDesc !== '' ? $metaDesc : mpPlainExcerpt($md, 200);

    $avatarUrl = mpExtractAvatar($md, $rawBase, $repoAvatar);
    if ($avatarUrl !== null) {
        $c['avatar_url'] = $avatarUrl;
        $c['avatar_file'] = mpProxyAvatar($avatarUrl, (int) $item['id']);
    }
    return $c;
}

/** Полный успех/частичный: COALESCE сохраняет старый контент при временных сбоях. */
function mpSaveCache(PDO $pdo, int $id, array $c): void
{
    $sql = 'INSERT INTO mp_cache
        (item_id, description, readme_html, readme_excerpt, avatar_url, avatar_file,
         stars, forks, open_issues, releases_count, last_release, topics_json,
         license, language, pushed_at, archived, fetched_at, fetch_error)
        VALUES
        (:id, :description, :readme_html, :readme_excerpt, :avatar_url, :avatar_file,
         :stars, :forks, :open_issues, :releases_count, :last_release, :topics_json,
         :license, :language, :pushed_at, :archived, :fetched_at, :fetch_error)
        ON CONFLICT(item_id) DO UPDATE SET
         description=COALESCE(excluded.description, mp_cache.description),
         readme_html=COALESCE(excluded.readme_html, mp_cache.readme_html),
         readme_excerpt=COALESCE(excluded.readme_excerpt, mp_cache.readme_excerpt),
         avatar_url=COALESCE(excluded.avatar_url, mp_cache.avatar_url),
         avatar_file=COALESCE(excluded.avatar_file, mp_cache.avatar_file),
         stars=excluded.stars, forks=excluded.forks, open_issues=excluded.open_issues,
         releases_count=excluded.releases_count, last_release=excluded.last_release,
         topics_json=excluded.topics_json, license=excluded.license, language=excluded.language,
         pushed_at=excluded.pushed_at, archived=excluded.archived,
         fetched_at=excluded.fetched_at, fetch_error=excluded.fetch_error';
    $pdo->prepare($sql)->execute([
        ':id' => $id,
        ':description' => $c['description'],
        ':readme_html' => $c['readme_html'],
        ':readme_excerpt' => $c['readme_excerpt'],
        ':avatar_url' => $c['avatar_url'],
        ':avatar_file' => $c['avatar_file'],
        ':stars' => $c['stars'],
        ':forks' => $c['forks'],
        ':open_issues' => $c['open_issues'],
        ':releases_count' => $c['releases_count'],
        ':last_release' => $c['last_release'],
        ':topics_json' => $c['topics_json'],
        ':license' => $c['license'],
        ':language' => $c['language'],
        ':pushed_at' => $c['pushed_at'],
        ':archived' => $c['archived'],
        ':fetched_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ':fetch_error' => $c['fetch_error'],
    ]);
}

/** Полный сбой: только метка ошибки, кэш не затирается. */
function mpMarkError(PDO $pdo, int $id, string $error): void
{
    $sql = 'INSERT INTO mp_cache (item_id, fetched_at, fetch_error)
        VALUES (:id, :now, :err)
        ON CONFLICT(item_id) DO UPDATE SET fetched_at=excluded.fetched_at, fetch_error=excluded.fetch_error';
    $pdo->prepare($sql)->execute([':id' => $id, ':now' => gmdate('Y-m-d\TH:i:s\Z'), ':err' => $error]);
}

/** Валидация записи каталога (seed/add/submit). */
function mpValidateEntry(array $it): array
{
    $hosts = mpHosts();
    $host = (string) ($it['host'] ?? '');
    $owner = (string) ($it['owner'] ?? '');
    $slug = (string) ($it['slug'] ?? '');
    $cat = (string) ($it['category'] ?? 'dev-tools');
    $note = (string) ($it['note'] ?? '');
    if (!isset($hosts[$host])) {
        return [false, 'неизвестный хост: ' . $host];
    }
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,38}$/', $owner) !== 1) {
        return [false, 'некорректный owner: ' . $owner];
    }
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $slug) !== 1) {
        return [false, 'некорректный slug: ' . $slug];
    }
    if (!in_array($cat, MP_CATEGORIES, true)) {
        return [false, 'неизвестная категория: ' . $cat];
    }
    if (strlen($note) > 500) {
        return [false, 'note длиннее 500 байт'];
    }
    return [true, ''];
}