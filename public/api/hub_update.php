<?php
declare(strict_types=1);

/**
 * API: обновление самого Hub (корневой репозиторий)
 * GET  /api/hub_update.php?action=status   — проверить наличие обновлений
 * POST /api/hub_update.php?action=pull     — применить обновления (merge)
 */

header('Content-Type: application/json; charset=utf-8');

function strip_ansi($text) {
    return preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $text);
}

$workspace = dirname(__DIR__, 2);
$hubRoot = $workspace;
$hubGitDir = "$hubRoot/.git";

if (!is_dir($hubGitDir)) {
    echo json_encode(['ok' => false, 'error' => 'Не git-репозиторий', 'error_code' => 'NOT_A_REPO'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// --- Status check -----------------------------------------------------------
if ($action === 'status') {
    $result = [
        'ok' => true,
        'data' => [
            'is_git' => true,
            'current_branch' => '',
            'current_commit' => '',
            'has_updates' => false,
            'ahead' => 0,
            'behind' => 0,
            'changed_files' => 0,
            'log' => [],
        ],
    ];

    // Current branch
    $headFile = "$hubRoot/.git/HEAD";
    if (is_file($headFile)) {
        $head = trim(file_get_contents($headFile));
        if (str_starts_with($head, 'ref: ')) {
            $refPath = substr($head, 5);
            $refFile = "$hubRoot/.git/$refPath";
            if (is_file($refFile)) {
                $result['data']['current_branch'] = trim(file_get_contents($refFile));
            }
        } else {
            $result['data']['current_branch'] = '(detached)';
        }
    }

    // Current commit
    $output = [];
    @exec(sprintf('git -C %s rev-parse HEAD 2>&1', escapeshellarg($hubRoot)), $output, $rc);
    if ($rc === 0) {
        $result['data']['current_commit'] = trim(implode('', $output));
    }

    // Fetch origin
    $fetchOutput = [];
    @exec(sprintf('git -C %s fetch origin 2>&1', escapeshellarg($hubRoot)), $fetchOutput, $fetchRc);

    // Compare with origin/main or origin/master
    $branch = $result['data']['current_branch'];
    $originRef = "origin/$branch";
    if ($branch === '' || $branch === '(detached)') {
        $originRef = 'origin/main';
    }

    $output = [];
    @exec(sprintf('git -C %s rev-parse %s 2>&1', escapeshellarg($hubRoot), escapeshellarg($originRef)), $output, $rc);
    if ($rc === 0 && !empty(implode('', $output))) {
        $originCommit = trim(implode('', $output));
        $localCommit = $result['data']['current_commit'];

        if ($localCommit !== $originCommit) {
            // Check behind/ahead
            $output = [];
            @exec(sprintf('git -C %s rev-list --count %s..%s 2>&1', escapeshellarg($hubRoot), escapeshellarg($originCommit), escapeshellarg($localCommit)), $output, $rc);
            $ahead = ($rc === 0 && is_numeric(implode('', $output))) ? (int) implode('', $output) : 0;

            $output = [];
            @exec(sprintf('git -C %s rev-list --count %s..%s 2>&1', escapeshellarg($hubRoot), escapeshellarg($localCommit), escapeshellarg($originCommit)), $output, $rc);
            $behind = ($rc === 0 && is_numeric(implode('', $output))) ? (int) implode('', $output) : 0;

            $result['data']['has_updates'] = ($behind > 0);
            $result['data']['ahead'] = $ahead;
            $result['data']['behind'] = $behind;

            // Changed files summary
            if ($behind > 0) {
                $output = [];
                @exec(sprintf('git -C %s diff --stat %s...%s 2>&1', escapeshellarg($hubRoot), escapeshellarg($localCommit), escapeshellarg($originCommit)), $output, $rc);
                if ($rc === 0 && !empty(implode('', $output))) {
                    $diffText = implode("\n", $output);
                    // Extract file count from last line
                    $lastLine = array_pop(explode("\n", $diffText));
                    if (preg_match('/(\d+) files? changed?/', $lastLine, $m)) {
                        $result['data']['changed_files'] = (int) $m[1];
                    }
                    // Extract commit messages (last 5)
                    $output = [];
                    @exec(sprintf('git -C %s log --oneline %s...%s 2>&1', escapeshellarg($hubRoot), escapeshellarg($localCommit), escapeshellarg($originCommit)), $output, $rc);
                    if ($rc === 0) {
                        $result['data']['log'] = array_slice($output, -5);
                    }
                }
            }
        }
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Pull updates -----------------------------------------------------------
if ($action === 'pull') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'error' => 'Метод не разрешён', 'error_code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $output = [];
    $log = [];

    $log[] = '=== Обновление Hub ===';

    // Fetch
    $log[] = 'fetch origin...';
    @exec(sprintf('git -C %s fetch origin 2>&1', escapeshellarg($hubRoot)), $output, $rc);
    $log[] = strip_ansi(implode("\n", $output));

    // Current branch
    $output = [];
    @exec(sprintf('git -C %s branch --show-current 2>&1', escapeshellarg($hubRoot)), $output, $rc);
    $branch = trim(implode('', $output));

    if ($branch === '') {
        $log[] = 'Ошибка: не определена текущая ветка';
        echo json_encode(['ok' => false, 'error' => 'Не определена ветка', 'error_code' => 'NO_BRANCH', 'data' => ['log' => $log]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Pull with merge (not rebase)
    $log[] = "pull origin $branch (merge)...";
    $output = [];
    @exec(sprintf('git -C %s pull --no-edit origin %s 2>&1', escapeshellarg($hubRoot), escapeshellarg($branch)), $output, $rc);
    $log[] = strip_ansi(implode("\n", $output));

    if ($rc === 0) {
        // Get summary of what changed
        $output = [];
        $localBefore = '';
        @exec(sprintf('git -C %s rev-parse HEAD~1 2>&1', escapeshellarg($hubRoot)), $output, $rc2);
        if ($rc2 === 0 && !empty(implode('', $output))) {
            $localBefore = trim(implode('', $output));
        }
        $output = [];
        @exec(sprintf('git -C %s rev-parse HEAD 2>&1', escapeshellarg($hubRoot)), $output, $rc2);
        if ($rc2 === 0 && !empty(implode('', $output))) {
            $localAfter = trim(implode('', $output));
        }

        $changedFiles = 0;
        if (isset($localAfter) && isset($localBefore) && $localBefore !== $localAfter) {
            $output = [];
            @exec(sprintf('git -C %s diff --stat %s...%s 2>&1', escapeshellarg($hubRoot), escapeshellarg($localBefore), escapeshellarg($localAfter)), $output, $rc2);
            if ($rc2 === 0 && !empty(implode('', $output))) {
                $diffText = implode("\n", $output);
                $lastLine = array_pop(explode("\n", $diffText));
                if (preg_match('/(\d+) files? changed?/', $lastLine, $m)) {
                    $changedFiles = (int) $m[1];
                }
            }
        }

        $log[] = "Обновлено: $changedFiles файлов изменено";
        $log[] = 'Успех';

        echo json_encode([
            'ok' => true,
            'data' => [
                'log' => $log,
                'changed_files' => $changedFiles,
                'branch' => $branch,
            ],
        ], JSON_UNESCAPED_UNICODE);
    } else {
        $log[] = 'Ошибка: конфликт слияния или ошибка pull';
        echo json_encode([
            'ok' => false,
            'error' => 'Ошибка обновления Hub',
            'error_code' => 'PULL_FAILED',
            'data' => ['log' => $log],
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Неизвестное действие', 'error_code' => 'INVALID_ACTION'], JSON_UNESCAPED_UNICODE);
