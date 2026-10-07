<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$projectsDir = dirname(__DIR__, 2) . '/projects';
$projects = [];

if (is_dir($projectsDir)) {
    foreach (scandir($projectsDir) as $item) {
        if ($item === '.' || $item === '..') continue;
        if (is_dir("$projectsDir/$item/.git")) {
            $projects[] = $item;
        }
    }
}

echo json_encode(['ok' => true, 'data' => $projects], JSON_UNESCAPED_UNICODE);
