<?php

declare(strict_types=1);

/**
 * API создания нового проекта: projects/<slug>/ + git init + README.md
 * POST: {"slug": "my-project"}
 * Ответ: {ok: true, data: {slug, path}} или {ok: false, error: "..."}
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Метод не разрешён', 'error_code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$input = json_decode(file_get_contents('php://input'), true);
$slug = trim((string) ($input['slug'] ?? ''));

if ($slug === '') {
    echo json_encode(['ok' => false, 'error' => 'Название проекта пустое', 'error_code' => 'EMPTY_SLUG'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

// Валидация slug: только [a-z0-9_-], не начинать с -, длина 1-50
if (!preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $slug)) {
    echo json_encode(['ok' => false, 'error' => 'Некорректное название: только латиница, цифры, - и _, не начинать с -', 'error_code' => 'INVALID_SLUG'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$projectsDir = dirname(__DIR__, 2) . '/projects';
$projectPath = $projectsDir . '/' . $slug;

if (is_dir($projectPath)) {
    echo json_encode(['ok' => false, 'error' => "Проект '$slug' уже существует", 'error_code' => 'ALREADY_EXISTS'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

if (!is_writable($projectsDir)) {
    echo json_encode(['ok' => false, 'error' => 'Нет прав на запись в projects/', 'error_code' => 'PERMISSION_DENIED'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

// Создание папки
if (!mkdir($projectPath, 0755, true)) {
    echo json_encode(['ok' => false, 'error' => 'Не удалось создать папку', 'error_code' => 'MKDIR_FAILED'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

// Git init
chdir($projectPath);
exec('git init 2>&1', $initOutput, $initRc);
if ($initRc !== 0) {
    rmdir($projectPath);
    echo json_encode(['ok' => false, 'error' => 'git init не удался: ' . implode("\n", $initOutput), 'error_code' => 'GIT_INIT_FAILED'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

// README.md
$readme = "# $slug\n\nНовый проект GitProject Workspace Hub.\n";
file_put_contents("$projectPath/README.md", $readme);

// .gitignore
$gitignore = ".env\ndata/\nstorage/\nvendor/\n*.log\n*.sqlite\n*.sqlite-journal\n*.sqlite-wal\n*.sqlite-shm\n";
file_put_contents("$projectPath/.gitignore", $gitignore);

// Начальный коммит
exec('git add -A 2>&1', $addOutput, $addRc);
exec('git commit -m "Initial commit" 2>&1', $commitOutput, $commitRc);

echo json_encode([
    'ok' => true,
    'data' => [
        'slug' => $slug,
        'path' => $projectPath,
        'init_log' => implode("\n", $initOutput),
        'commit_log' => implode("\n", $commitOutput),
    ],
], JSON_UNESCAPED_UNICODE);
