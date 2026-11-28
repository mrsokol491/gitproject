#!/usr/bin/env bash
# gitproject-pull.sh — Безопасное обновление локальных репозиториев из основного хоста.
# Обновляет сам хаб (gitproject) и все проекты в контейнере projects/.
#
# ЗАПРЕЩЕНО: запускать через sudo.
# Использует --ff-only для предотвращения случайных merge-коммитов при обновлении.

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../lib/common.sh"

set -uo pipefail

HUB="${HUB_ROOT}"
PROJECTS_DIR="$HUB/projects"

echo "==> 1. Обновление хаба (gitproject)..."
if git -C "$HUB" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "    [gitproject] fetch..."
    git -C "$HUB" fetch origin >/dev/null 2>&1
    branch="$(git -C "$HUB" branch --show-current)"
    if [ -n "$branch" ]; then
        echo "    [gitproject] pull --ff-only origin $branch..."
        git -C "$HUB" pull --ff-only origin "$branch" || echo "    ВНИМАНИЕ: хаб не обновлён (возможны локальные незакоммиченные изменения)"
    else
        echo "    ВНИМАНИЕ: хаб в состоянии detached HEAD или без коммитов"
    fi
else
    echo "    Хаб не является git-репозиторием."
fi

echo ""
echo "==> 2. Обновление проектов в $PROJECTS_DIR..."
updated_count=0
skipped_count=0

for dir in "$PROJECTS_DIR"/*/; do
    [ -d "$dir" ] || continue
    slug="$(basename "$dir")"
    
    if git -C "$dir" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        echo "    [$slug] fetch..."
        git -C "$dir" fetch origin >/dev/null 2>&1
        branch="$(git -C "$dir" branch --show-current)"
        
        if [ -n "$branch" ]; then
            echo "    [$slug] pull --ff-only origin $branch..."
            if git -C "$dir" pull --ff-only origin "$branch" >/dev/null 2>&1; then
                ((updated_count++))
            else
                echo "    [$slug] ⚠ ВНИМАНИЕ: не удалось обновить (проверьте локальные изменения или конфликты)"
            fi
        else
            echo "    [$slug] пропуск (detached HEAD или нет коммитов)"
            ((skipped_count++))
        fi
    else
        echo "    [$slug] пропуск (не является git-репозиторием)"
        ((skipped_count++))
    fi
done

echo ""
echo "==> Обновление завершено."
echo "    Успешно обновлено репозиториев: $updated_count"
echo "    Пропущено: $skipped_count"