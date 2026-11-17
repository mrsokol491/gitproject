#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * bin/marketplace.php — CLI маркетплейса (курирование владельцем).
 * Команды: init | import | refresh [--force] [--limit=N] |
 *          add <host> <owner> <slug> [--cat=X] [--note=...] |
 *          list [--pending] | approve <id> | reject <id>
 * Прогресс — в stderr, итоговые значения — в stdout (cli-wizard-ux-patterns).
 */

require __DIR__ . '/../lib/marketplace-core.php';

function mpOut(string $s): void
{
    fwrite(STDOUT, $s . "\n");
}

function mpErr(string $s): void
{
    fwrite(STDERR, $s . "\n");
}

function mpOpt(array $argv, string $name, ?string $default = null): ?string
{
    foreach ($argv as $a) {
        if (str_starts_with($a, '--' . $name . '=')) {
            return substr($a, strlen($name) + 3);
        }
    }
    return $default;
}

function mpFlag(array $argv, string $name): bool
{
    return in_array('--' . $name, $argv, true);
}

$cmd = $argv[1] ?? 'help';
$pdo = mpDb();

switch ($cmd) {
    case 'init':
        mpOut('✓ Схема data/marketplace.sqlite готова (WAL, busy_timeout=30000)');
        break;

    case 'import':
        $file = mpRoot() . '/config/marketplace.json';
        if (!is_file($file)) {
            mpErr('✗ Нет config/marketplace.json');
            exit(1);
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
            mpErr('✗ Неверный формат seed-файла');
            exit(1);
        }
        $added = 0;
        foreach ($data['items'] as $it) {
            if (!is_array($it)) {
                continue;
            }
            [$ok, $msg] = mpValidateEntry($it);
            if (!$ok) {
                mpErr('✗ Пропуск: ' . $msg);
                continue;
            }
            $tags = isset($it['tags']) && is_array($it['tags']) ? array_values(array_filter($it['tags'], 'is_string')) : [];
            $st = $pdo->prepare('INSERT OR IGNORE INTO mp_items
                (host_id, owner, slug, category, editorial_note, tags_json, canonical_key, status)
                VALUES (:h, :o, :s, :c, :n, :t, :k, \'approved\')');
            $st->execute([
                ':h' => $it['host'],
                ':o' => $it['owner'],
                ':s' => $it['slug'],
                ':c' => $it['category'] ?? 'dev-tools',
                ':n' => (string) ($it['note'] ?? ''),
                ':t' => (string) (json_encode($tags, JSON_UNESCAPED_UNICODE) ?: '[]'),
                ':k' => strtolower($it['owner'] . '/' . $it['slug']),
            ]);
            $added += $st->rowCount();
        }
        mpOut('✓ Импорт: добавлено ' . $added);
        break;

    case 'refresh':
        $lock = fopen(mpRoot() . '/data/marketplace.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            mpErr('✗ refresh уже выполняется (lock)');
            exit(1);
        }
        $force = mpFlag($argv, 'force');
        $limit = max(1, min(50, (int) (mpOpt($argv, 'limit', '20') ?? '20')));
        $rows = $pdo->query('SELECT i.*, c.fetched_at AS last_fetch
            FROM mp_items i LEFT JOIN mp_cache c ON c.item_id = i.id
            WHERE i.status = \'approved\'
            ORDER BY (c.fetched_at IS NULL) DESC, c.fetched_at ASC
            LIMIT ' . $limit)->fetchAll();
        $done = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($rows as $item) {
            if (!$force && $item['last_fetch'] !== null && strtotime((string) $item['last_fetch']) > time() - 86400) {
                $skipped++;
                continue;
            }
            $label = $item['host_id'] . '/' . $item['owner'] . '/' . $item['slug'];
            $c = mpFetchItem($item);
            $totalFail = $c['readme_html'] === null && $c['stars'] === null && $c['description'] === null;
            if ($totalFail) {
                mpMarkError($pdo, (int) $item['id'], (string) $c['fetch_error']);
                mpErr('✗ ' . $label . ': ' . $c['fetch_error']);
                $failed++;
                continue;
            }
            mpSaveCache($pdo, (int) $item['id'], $c);
            if ($c['branch'] !== $item['default_branch']) {
                $pdo->prepare('UPDATE mp_items SET default_branch = :b, updated_at = datetime(\'now\') WHERE id = :id')
                    ->execute([':b' => $c['branch'], ':id' => (int) $item['id']]);
            }
            mpErr('✓ ' . $label . ($c['fetch_error'] !== null ? ' (частично: ' . $c['fetch_error'] . ')' : ''));
            $done++;
        }
        flock($lock, LOCK_UN);
        fclose($lock);
        mpOut('Обновлено: ' . $done . ', пропущено (TTL 24ч): ' . $skipped . ', ошибок: ' . $failed);
        exit($failed > 0 ? 1 : 0);

    case 'add':
        $it = [
            'host' => $argv[2] ?? '',
            'owner' => $argv[3] ?? '',
            'slug' => $argv[4] ?? '',
            'category' => mpOpt($argv, 'cat', 'dev-tools'),
            'note' => mpOpt($argv, 'note', ''),
        ];
        [$ok, $msg] = mpValidateEntry($it);
        if (!$ok) {
            mpErr('✗ ' . $msg);
            exit(1);
        }
        $st = $pdo->prepare('INSERT INTO mp_items
            (host_id, owner, slug, category, editorial_note, canonical_key, status)
            VALUES (:h, :o, :s, :c, :n, :k, \'approved\')');
        try {
            $st->execute([
                ':h' => $it['host'], ':o' => $it['owner'], ':s' => $it['slug'],
                ':c' => $it['category'], ':n' => $it['note'],
                ':k' => strtolower($it['owner'] . '/' . $it['slug']),
            ]);
        } catch (PDOException) {
            mpErr('✗ Запись уже существует');
            exit(1);
        }
        mpOut('✓ Добавлено, id=' . $pdo->lastInsertId() . '. Запустите refresh для загрузки контента.');
        break;

    case 'list':
        $where = mpFlag($argv, 'pending') ? 'WHERE i.status = \'pending\'' : '';
        $rows = $pdo->query('SELECT i.id, i.host_id, i.owner, i.slug, i.status, c.stars, c.fetched_at
            FROM mp_items i LEFT JOIN mp_cache c ON c.item_id = i.id ' . $where . ' ORDER BY i.id')->fetchAll();
        printf("%-4s %-9s %-36s %-9s %7s  %s\n", 'ID', 'ХОСТ', 'OWNER/SLUG', 'СТАТУС', 'STARS', 'FETCHED');
        foreach ($rows as $r) {
            printf("%-4s %-9s %-36s %-9s %7s  %s\n",
                $r['id'], $r['host_id'], $r['owner'] . '/' . $r['slug'], $r['status'],
                $r['stars'] ?? '—', $r['fetched_at'] ?? '—');
        }
        break;

    case 'approve':
    case 'reject':
        $id = (int) ($argv[2] ?? 0);
        if ($id < 1) {
            mpErr('✗ Укажите id: marketplace.php ' . $cmd . ' <id>');
            exit(1);
        }
        $st = $pdo->prepare('UPDATE mp_items SET status = :s, updated_at = datetime(\'now\') WHERE id = :id');
        $st->execute([':s' => $cmd === 'approve' ? 'approved' : 'rejected', ':id' => $id]);
        if ($st->rowCount() === 0) {
            mpErr('✗ Запись не найдена');
            exit(1);
        }
        mpOut('✓ id=' . $id . ' → ' . ($cmd === 'approve' ? 'approved' : 'rejected'));
        break;

    default:
        mpErr('Использование: marketplace.php <команда>');
        mpErr('  init                          — создать схему БД');
        mpErr('  import                        — импорт config/marketplace.json (approved)');
        mpErr('  refresh [--force] [--limit=N] — обновить кэш (TTL 24ч, батч 20, lock)');
        mpErr('  add <host> <owner> <slug> [--cat=X] [--note=...] — добавить запись');
        mpErr('  list [--pending]              — список записей');
        mpErr('  approve <id> | reject <id>    — модерация предложений');
        mpErr('Хосты: gitverse, github, gitflic. Категории: ' . implode(', ', MP_CATEGORIES));
        mpErr('GitHub без токена: 60 API-запросов/ч — не более 20 GitHub-записей за прогон.');
        exit($cmd === 'help' ? 0 : 2);
}