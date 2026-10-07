<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$workspace = dirname(__DIR__, 2);
$hostsFile = "$workspace/config/hosts.json";
$envFile = "$workspace/.env";

if (!file_exists($hostsFile) || !file_exists($envFile)) {
    echo json_encode(['ok' => false, 'error' => 'Конфигурация не найдена', 'error_code' => 'CONFIG_MISSING'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$hosts = json_decode(file_get_contents($hostsFile), true);
$envContent = file_get_contents($envFile);

$result = [];
foreach ($hosts as $id => $config) {
    $prefix = $config['env_prefix'] ?? strtoupper($id);
    
    // Проверяем наличие реального токена (не пустого и не placeholder)
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
