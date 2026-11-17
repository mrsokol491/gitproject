#!/usr/bin/env bash
# gitproject-fetch.sh — fetch хаба из GitVerse с троттлингом (24ч), флаг --force обходит.
set -euo pipefail
HUB="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd -- "$HUB"
FORCE=0
[ "${1:-}" = "--force" ] && FORCE=1
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
git fetch gitverse master --tags --force >&2 || { echo "✗ fetch не удался" >&2; exit 1; }
printf '%s\n' "$NOW" > "$MARK"
echo "✓ Fetch завершён" >&2