<?php

declare(strict_types=1);

/**
 * Тесты чистой логики меток и дедупликации кнопок (lib/hub-version.php).
 * Правила R1-R4 владельца + сценарии со скриншотов от 24.11.2026.
 */

require __DIR__ . '/../lib/hub-version.php';

function assertSame(mixed $expected, mixed $actual, string $msg): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($msg . ': ожидалось ' . var_export($expected, true) . ', получено ' . var_export($actual, true));
    }
}

// --- Метки текущей версии -------------------------------------------------------

function test_current_on_tag_has_no_suffix(): void
{
    assertSame('0.1.5', hvCurrentLabel('93827eeaaaa', '0.1.5', '0.1.5', ''), 'HEAD на теге');
}

function test_current_ahead_of_base(): void
{
    assertSame('0.1.5-r93827e', hvCurrentLabel('93827eeaaaa', null, '0.1.5', ''), 'HEAD впереди базы');
}

function test_current_without_tags(): void
{
    assertSame('93827ee', hvCurrentLabel('93827eeaaaa', null, null, ''), 'тегов нет');
}

function test_current_without_local_sha(): void
{
    assertSame('0.1.4', hvCurrentLabel(null, null, null, '0.1.4'), 'journal applied_label');
    assertSame('неизвестно', hvCurrentLabel(null, null, null, ''), 'пустой журнал');
}

// --- Метка edge ------------------------------------------------------------------

function test_edge_on_stable_commit_equals_stable(): void
{
    assertSame('0.1.5', hvEdgeLabel('93827eeaaaa', '0.1.5', '0.1.5', true), 'edge на коммите тега');
}

function test_edge_ahead_of_stable(): void
{
    assertSame('0.1.6-r77aa11', hvEdgeLabel('77aa11bbcc', '0.1.6', '0.1.5', false), 'edge впереди');
}

function test_edge_without_remote(): void
{
    assertSame(null, hvEdgeLabel(null, null, '0.1.5', false), 'remote нет');
}

// --- Правила дедупликации кнопок --------------------------------------------------

function test_rule1_stable_equals_edge(): void
{
    $b = hvButtons('0.1.6-r77aa11', '0.1.6', '0.1.6');
    assertSame('0.1.6', $b['stable'], 'R1 stable видна');
    assertSame(null, $b['edge'], 'R1 edge скрыта');
}

function test_rule2_stable_equals_current(): void
{
    $b = hvButtons('0.1.5', '0.1.5', '0.1.6-r77aa11');
    assertSame(null, $b['stable'], 'R2 stable скрыта');
    assertSame('0.1.6-r77aa11', $b['edge'], 'R2 edge видна');
}

function test_rule3_edge_equals_current(): void
{
    $b = hvButtons('0.1.6-r77aa11', '0.1.5', '0.1.6-r77aa11');
    assertSame('0.1.5', $b['stable'], 'R3 stable видна');
    assertSame(null, $b['edge'], 'R3 edge скрыта');
}

function test_rule4_all_equal(): void
{
    $b = hvButtons('0.1.5', '0.1.5', '0.1.5');
    assertSame(null, $b['stable'], 'R4 stable скрыта');
    assertSame(null, $b['edge'], 'R4 edge скрыта');
}

function test_buttons_all_distinct(): void
{
    $b = hvButtons('0.1.7-r112233', '0.1.5', '0.1.6-r77aa11');
    assertSame('0.1.5', $b['stable'], 'все различны: stable');
    assertSame('0.1.6-r77aa11', $b['edge'], 'все различны: edge');
}

function test_buttons_without_tags(): void
{
    $b = hvButtons('abc1234', null, '0.1.6-r77aa11');
    assertSame(null, $b['stable'], 'тегов нет: stable скрыта');
    assertSame('0.1.6-r77aa11', $b['edge'], 'тегов нет: edge видна');
}

function test_buttons_without_edge(): void
{
    $b = hvButtons('0.1.6-r77aa11', '0.1.5', null);
    assertSame('0.1.5', $b['stable'], 'remote нет: stable видна');
    assertSame(null, $b['edge'], 'remote нет: edge скрыта');
}

// --- Сценарии со скриншотов 24.11.2026 --------------------------------------------

function test_screenshot_head_on_tag_equals_master(): void
{
    // HEAD == тег 0.1.5 == gitverse/master: current без -r, обе кнопки скрыты (R4).
    $current = hvCurrentLabel('93827eeaaaa', '0.1.5', '0.1.5', '');
    $edge = hvEdgeLabel('93827eeaaaa', '0.1.5', '0.1.5', true);
    $b = hvButtons($current, '0.1.5', $edge);
    assertSame('0.1.5', $current, 'скриншот 1/3: current');
    assertSame(null, $b['stable'], 'скриншот 1/3: stable скрыта');
    assertSame(null, $b['edge'], 'скриншот 1/3: edge скрыта');
}

function test_screenshot_head_equals_edge_ahead_of_tag(): void
{
    // HEAD == master, впереди тега 0.1.5: current == edge -> edge скрыта (R3), stable видна.
    $current = hvCurrentLabel('73a6e4bbbb', null, '0.1.6', '');
    $edge = hvEdgeLabel('73a6e4bbbb', '0.1.6', '0.1.6', false);
    $b = hvButtons($current, '0.1.6', $edge);
    assertSame($current, $edge, 'скриншот 2: current совпадает с edge');
    assertSame('0.1.6', $b['stable'], 'скриншот 2: stable видна');
    assertSame(null, $b['edge'], 'скриншот 2: edge скрыта');
}