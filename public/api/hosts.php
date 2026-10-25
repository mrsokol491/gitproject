<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$workspace = dirname(__DIR__, 2);
$hostsFile = "$workspace/config/hosts.json";
$envFile = "$workspace/.env";

// --- POST: добавление хоста -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!file_exists($hostsFile) || !file_exists($envFile)) {
        echo json_encode(['ok' => false, 'error' => 'Конфигурация не найдена', 'error_code' => 'CONFIG_MISSING'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $hostId = $input['host_id'] ?? '';
    $domain = $input['domain'] ?? '';
    $user = $input['user'] ?? '';
    $token = $input['token'] ?? '';
    $scope = $input['scope'] ?? 'BOTH'; // HUB|PROJECT|BOTH
    $urlTemplate = $input['url_template'] ?? '';
    $prefix = $input['prefix'] ?? '';

    // Валидация
    if (empty($hostId) || empty($domain) || empty($user) || empty($token)) {
        echo json_encode(['ok' => false, 'error' => 'Заполните все поля', 'error_code' => 'MISSING_FIELDS'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }

    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $hostId)) {
        echo json_encode(['ok' => false, 'error' => 'Некорректный ID хоста (только буквы, цифры, _-)', 'error_code' => 'INVALID_HOST_ID'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }

    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $domain)) {
        echo json_encode(['ok' => false, 'error' => 'Некорректный домен', 'error_code' => 'INVALID_DOMAIN'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
        echo json_encode(['ok' => false, 'error' => 'Некорректный префикс (только буквы, цифры, _)', 'error_code' => 'INVALID_PREFIX'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }

    if (empty($urlTemplate)) {
        $urlTemplate = "https://{$domain}/{owner}/{slug}.git";
    }

    $hosts = json_decode(file_get_contents($hostsFile), true) ?: [];

    // Тест подключения
    $testUrl = "https://{$domain}/{$user}/test-connection-test.git";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $testUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERPWD => "{$user}:{$token}",
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $authFailed = ($httpCode === 401 || $httpCode === 403 || strpos($curlError ?? '', 'authentication') !== false);
    $repoNotFound = ($httpCode === 404);

    if ($authFailed) {
        echo json_encode([
            'ok' => false,
            'error' => 'Ошибка аутентификации. Проверьте пользователя и токен.',
            'error_code' => 'AUTH_FAILED',
            'data' => ['http_code' => $httpCode],
        ], JSON_UNESCAPED_UNICODE);
        exit(1);
    }

    // Запись в .env
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $newLines = [];
    $existingKeys = [];

    if ($scope === 'HUB' || $scope === 'BOTH') {
        $existingKeys[] = "{$prefix}_HUB_USER";
        $existingKeys[] = "{$prefix}_HUB_TOKEN";
    }
    if ($scope === 'PROJECT' || $scope === 'BOTH') {
        $existingKeys[] = "{$prefix}_PROJECT_USER";
        $existingKeys[] = "{$prefix}_PROJECT_TOKEN";
    }

    foreach ($lines as $line) {
        $skip = false;
        foreach ($existingKeys as $key) {
            if (preg_match('/^' . preg_quote($key, '/') . '=/i', $line)) {
                $skip = true;
                break;
            }
        }
        if (!$skip) {
            $newLines[] = $line;
        }
    }

    $timestamp = date('Y-m-d');
    $scopesToAdd = [];
    if ($scope === 'BOTH') {
        $scopesToAdd = ['HUB', 'PROJECT'];
    } else {
        $scopesToAdd = [$scope];
    }

    foreach ($scopesToAdd as $s) {
        $newLines[] = "";
        $newLines[] = "# {$s} токены для {$domain} (добавлено {$timestamp})";
        $newLines[] = "{$prefix}_{$s}_USER={$user}";
        $newLines[] = "{$prefix}_{$s}_TOKEN={$token}";
    }

    file_put_contents($envFile, implode("\n", $newLines) . "\n");

    // Обновление hosts.json
    $isNewHost = !isset($hosts[$hostId]);

    if ($isNewHost) {
        $hosts[$hostId] = [
            'host' => $domain,
            'status' => 'ready',
            'env_prefix' => $prefix,
            'url_template' => $urlTemplate,
            'registry' => 'unknown',
        ];
    } else {
        $hosts[$hostId]['status'] = 'ready';
        $hosts[$hostId]['host'] = $domain;
        $hosts[$hostId]['env_prefix'] = $prefix;
        $hosts[$hostId]['url_template'] = $urlTemplate;
    }

    file_put_contents($hostsFile, json_encode($hosts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    echo json_encode([
        'ok' => true,
        'data' => [
            'message' => $isNewHost ? "Хост {$hostId} добавлен" : "Хост {$hostId} обновлён",
            'host_id' => $hostId,
            'domain' => $domain,
            'configured' => true,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- GET: список хостов -----------------------------------------------------
if (!file_exists($hostsFile) || !file_exists($envFile)) {
    echo json_encode(['ok' => false, 'error' => 'Конфигурация не найдена', 'error_code' => 'CONFIG_MISSING'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$hosts = json_decode(file_get_contents($hostsFile), true);
$envContent = file_get_contents($envFile);

$result = [];
foreach ($hosts as $id => $config) {
    $prefix = $config['env_prefix'] ?? strtoupper($id);
    $hasHubToken = (bool) preg_match("/^{$prefix}_HUB_TOKEN=(?!placeholder$).+/m", $envContent);
    $hasProjectToken = (bool) preg_match("/^{$prefix}_PROJECT_TOKEN=(?!placeholder$).+/m", $envContent);
    
    $result[] = [
        'id' => $id,
        'host' => $config['host'] ?? 'unknown',
        'status' => $config['status'] ?? 'unknown',
        'registry' => $config['registry'] ?? 'unknown',
        'configured' => $hasHubToken || $hasProjectToken,
    ];
}

echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
