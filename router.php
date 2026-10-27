<?php

declare(strict_types=1);

/**
 * Роутер хаба GitProject Workspace (корень workspace).
 * Версия 2.2 от 2026-10-27.
 *
 * Обязанности:
 *  - Basic Auth для LAN: с адресов НЕ loopback требует пароль NETWORK_PASSWORD
 *    из .env (guide раздел 8); с 127.0.0.1 / ::1 — без пароля;
 *  - отдаёт сам хаб: / и /assets/* (return false -> файл исполняет php -S);
 *  - проксирует /<slug>/... в projects/<slug>/<docroot>/...;
 *  - docroot: public/ (STANDARD.md §3, §9); ВРЕМЕННО допускаются legacy-кандидаты
 *    (Project, www, ...) до миграции проектов, каждое использование — в error-log;
 *  - запрещает прямой доступ к служебному: .env, bin/, data/, projects/, *.md;
 *  - защита от path traversal через realpath-проверки.
 *
 * Запуск (systemd, guide раздел 5):
 *   php -S 0.0.0.0:8000 -t ~/gitproject ~/gitproject/router.php
 * Ручной запуск для отладки:
 *   cd ~/gitproject && php -S 127.0.0.1:8000 router.php
 */

// --- Пути хаба --------------------------------------------------------------
$hubRoot      = __DIR__;
$projectsRoot = $hubRoot . '/projects';
$envFile      = $hubRoot . '/.env';

// Кандидаты docroot: public первым (STANDARD), legacy — временно.
$docrootCandidates = ['public', 'Project', 'project', 'www', 'web', 'html'];

// --- Запрошенный путь без query ---------------------------------------------
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = is_string($uri) ? rawurldecode($uri) : '/';
$uri = (string) (preg_replace('#/+#', '/', $uri) ?? '/');

// --- 0) Basic Auth для LAN (guide раздел 8) ---------------------------------
$remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$isLoopback = in_array($remoteAddr, ['127.0.0.1', '::1'], true);

if (!$isLoopback) {
    // Читаем из .env ТОЛЬКО NETWORK_PASSWORD, секреты не выводим.
    $networkPassword = '';
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'NETWORK_PASSWORD=')) {
                $networkPassword = trim(substr($line, strlen('NETWORK_PASSWORD=')));
                break;
            }
        }
    }

    if ($networkPassword === '') {
        // Пароль не настроен — доступ из LAN запрещаем полностью.
        error_log('HUB: NETWORK_PASSWORD не задан в .env — доступ из LAN запрещён');
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "503 Service Unavailable: auth not configured\n";
        return true;
    }

    $sentPassword = $_SERVER['PHP_AUTH_PW'] ?? null;
    if ($sentPassword === null || !hash_equals($networkPassword, $sentPassword)) {
        header('WWW-Authenticate: Basic realm="GitProject Workspace Hub", charset="UTF-8"');
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo "401 Unauthorized\n";
        return true;
    }
}

// --- 1) Сам хаб: / и /assets/* ----------------------------------------------
if ($uri === '/' || $uri === '/index.php') {
    // Отдаём новую панель управления из public/index.php
    $file = __DIR__ . '/public/index.php';
    if (is_file($file)) {
        $_SERVER['SCRIPT_FILENAME'] = $file;
        require $file;
        return true;
    }
    return false; // fallback на корень, если public/index.php вдруг удалён
}
if ($uri === '/assets' || str_starts_with($uri, '/assets/')) {
    return false; // статика хаба: assets/css/, assets/img/
}

// --- 1.5) API и админ-панель хаба (из папки public/) ------------------------
if ($uri === '/admin.php') {
    $file = __DIR__ . '/public/admin.php';
    if (is_file($file)) {
        $_SERVER['SCRIPT_FILENAME'] = $file;
        require $file;
        return true;
    }
}
if (str_starts_with($uri, '/api/')) {
    $file = __DIR__ . '/public' . $uri;
    if (is_file($file)) {
        $_SERVER['SCRIPT_FILENAME'] = $file;
        require $file;
        return true;
    }
}

// --- 2) Запрет прямого доступа к служебным файлам хаба ----------------------
$forbidden = [
    '.env', '.git', '.gitignore',
    'bin', 'data', 'projects',
    'STANDARD.md', 'gitproject-workspace-guide.md',
];
foreach ($forbidden as $blocked) {
    if ($uri === '/' . $blocked || str_starts_with($uri, '/' . $blocked . '/')) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 Forbidden\n";
        return true;
    }
}

// --- 3) Разбор /<slug>/<path...> --------------------------------------------
$parts   = explode('/', trim($uri, '/'));
$slug    = (string) ($parts[0] ?? '');
$relPath = implode('/', array_slice($parts, 1));

// whitelist slug: строчные, цифры, -, _ (STANDARD.md раздел 4)
if (preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $slug) !== 1) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found\n";
    return true;
}

// --- 4) Проект существует и лежит строго внутри projects/ -------------------
$realProjects = realpath($projectsRoot);
$realProject  = realpath($projectsRoot . '/' . $slug);
if ($realProjects === false || $realProject === false
    || !str_starts_with($realProject . '/', $realProjects . '/')) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found: project missing\n";
    return true;
}

// --- 5) Docroot: public/ первым, legacy — временно --------------------------
$docroot = null;
foreach ($docrootCandidates as $candidate) {
    $dir = $realProject . '/' . $candidate;
    if (is_dir($dir) && (is_file($dir . '/index.php') || is_file($dir . '/index.html'))) {
        $docroot = realpath($dir) ?: null;
        if ($candidate !== 'public') {
            error_log(sprintf(
                'HUB: legacy docroot "%s" у проекта "%s" — требуется миграция на public/',
                $candidate,
                $slug
            ));
        }
        break;
    }
}
if ($docroot === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found: project has no docroot with index\n";
    return true;
}

// /slug -> /slug/ (относительные ссылки проекта работают)
if ($relPath === '' && !str_ends_with($uri, '/')) {
    header('Location: /' . $slug . '/', true, 301);
    return true;
}

// --- 6) Целевой файл внутри docroot ------------------------------------------
$target = ($relPath === '') ? $docroot . '/index.php' : $docroot . '/' . $relPath;
$real   = realpath($target);

if ($real === false
    || ($real !== $docroot && !str_starts_with($real . '/', $docroot . '/'))) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found\n";
    return true;
}
if (is_dir($real)) {
    $real .= '/index.php';
    if (!is_file($real)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "404 Not Found\n";
        return true;
    }
}

// --- 7) Статику отдаём сами (URI проекта не совпадает с путём на диске) ------
$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
if ($ext !== 'php') {
    $mime = match ($ext) {
        'css'         => 'text/css; charset=utf-8',
        'js', 'mjs'   => 'application/javascript; charset=utf-8',
        'json', 'map' => 'application/json; charset=utf-8',
        'svg'         => 'image/svg+xml',
        'png'         => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif'         => 'image/gif',
        'webp'        => 'image/webp',
        'ico'         => 'image/x-icon',
        'woff'        => 'font/woff',
        'woff2'       => 'font/woff2',
        'ttf'         => 'font/ttf',
        'txt'         => 'text/plain; charset=utf-8',
        'html', 'htm' => 'text/html; charset=utf-8',
        'pdf'         => 'application/pdf',
        default       => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . (string) filesize($real));
    readfile($real);
    return true;
}

// --- 8) PHP проекта исполняем в его docroot ----------------------------------
$_SERVER['APP_BASE_PATH']   = '/' . $slug; // база для относительных ссылок проекта
$_SERVER['SCRIPT_FILENAME'] = $real;
$_SERVER['SCRIPT_NAME']     = '/' . $slug . '/' . ltrim($relPath, '/');
$_SERVER['PHP_SELF']        = $_SERVER['SCRIPT_NAME'];

chdir(dirname($real));
require $real;
return true;