<?php

declare(strict_types=1);

/**
 * Чистая логика меток версий хаба и дедупликации кнопок (без git, без exec).
 * Правила дедупликации зафиксированы владельцем:
 *  R1: stable == edge               -> показать только stable;
 *  R2: stable == current            -> current отображается как stable (без -r), кнопка stable скрыта;
 *  R3: edge == current              -> кнопка edge скрыта;
 *  R4: stable == edge == current    -> скрыты обе кнопки.
 * Покрыто тестами: tests/hub-version-test.php (bin/run-tests.php).
 */

function hvCurrentLabel(?string $localSha, ?string $tagAtHead, ?string $baseTag, string $appliedLabel): string
{
    if ($localSha === null) {
        return $appliedLabel !== '' ? $appliedLabel : 'неизвестно';
    }
    if ($tagAtHead !== null) {
        return $tagAtHead;
    }
    if ($baseTag !== null) {
        return $baseTag . '-r' . substr($localSha, 0, 6);
    }
    return substr($localSha, 0, 7);
}

function hvEdgeLabel(?string $edgeSha, ?string $baseTagEdge, ?string $stableTag, bool $edgeOnStableCommit): ?string
{
    if ($edgeSha === null || $stableTag === null) {
        return null;
    }
    if ($edgeOnStableCommit) {
        return $stableTag;
    }
    if ($baseTagEdge !== null) {
        return $baseTagEdge . '-r' . substr($edgeSha, 0, 6);
    }
    return substr($edgeSha, 0, 7);
}

/**
 * @return array{stable: ?string, edge: ?string} метки кнопок, которые нужно показать (null = скрыть)
 */
function hvButtons(string $current, ?string $stable, ?string $edge): array
{
    $showStable = ($stable !== null && $stable !== $current) ? $stable : null;
    $showEdge = ($edge !== null && $edge !== $current && $edge !== $stable) ? $edge : null;
    return ['stable' => $showStable, 'edge' => $showEdge];
}