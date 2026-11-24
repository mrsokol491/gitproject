#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Минимальный тест-раннер хаба: подключает tests/*-test.php и вызывает test_*().
 * Без composer/composer-runtime: сканирование + assert-исключения + exit-код.
 * Запуск: php bin/run-tests.php
 */

$testsDir = __DIR__ . '/../tests';
if (!is_dir($testsDir)) {
    fwrite(STDERR, "✗ Каталог tests/ не найден\n");
    exit(2);
}

foreach (glob($testsDir . '/*-test.php') ?: [] as $file) {
    require_once $file;
}

$pass = 0;
$fail = 0;
foreach (get_defined_functions()['user'] as $fn) {
    if (!str_starts_with($fn, 'test_')) {
        continue;
    }
    try {
        $fn();
        $pass++;
        fwrite(STDOUT, "PASS  $fn\n");
    } catch (Throwable $e) {
        $fail++;
        fwrite(STDOUT, "FAIL  $fn: " . $e->getMessage() . "\n");
    }
}

fwrite(STDOUT, sprintf("Итого: %d passed, %d failed\n", $pass, $fail));
exit($fail > 0 ? 1 : 0);