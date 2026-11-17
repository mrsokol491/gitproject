#!/usr/bin/env bash
# gitproject-fetch.sh — fetch хаба из GitVerse с троттлингом (24ч), --force обходит.
# Портативно: remote gitverse создаётся сам; если origin уже смотрит на GitVerse — берём его.
set -euo pipefail
HUB="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd -- "$HUB"
REMOTE="gitverse"
REMOTE_URL="https://gitverse.ru/Sokol491/gitproject.git"
FORCE=0
[ "${1:-}" = "--force" ] && FORCE=1
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || { echo "✗ не git-репозиторий" >&2; exit 1; }
if git remote get-url "$REMOTE" >/dev/null 2>&1; then
  CUR="$(git remote get-url "$REMOTE")"
  if [ "$CUR" != "$REMOTE_URL" ]; then
    echo "ℹ remote $REMOTE: выравниваю URL на $REMOTE_URL" >&2
    git remote set-url "$REMOTE" "$REMOTE_URL"
  fi
elif [ "$(git remote get-url origin 2>/dev/null || true)" = "$REMOTE_URL" ]; then
  REMOTE="origin"
  echo "ℹ Использую remote origin (клон с GitVerse)" >&2
else
  echo "ℹ Добавляю remote $REMOTE → $REMOTE_URL" >&2
  git remote add "$REMOTE" "$REMOTE_URL"
fi
MARK="$HUB/data/last-fetch.txt"
mkdir -p "$HUB/data"
NOW="$(date +%s)"
if [ "$FORCE" -eq 0 ] && [ -f "$MARK" ]; then
  LAST="$(cat "$MARK" 2>/dev/null || echo 0)"
  case "$LAST" in ''|*[!0-9]*) LAST=0 ;; esac
  AGE=$(( NOW - LAST ))
  if [ "$AGE" -lt 86400 ]; then
    echo "✓ Fetch пропущен: последний был $(( AGE / 3600 ))ч назад (троттлинг 24ч)" >&2
    exit 0
  fi
fi
git fetch "$REMOTE" master --tags --force >&2 || { echo "✗ fetch не удался" >&2; exit 1; }
printf '%s\n' "$NOW" > "$MARK"
echo "✓ Fetch завершён ($REMOTE)" >&2