#!/usr/bin/env bash
# gitproject-export.sh <slug> — zip tracked-файлов проекта в data/exports/<slug>-<stamp>.zip
# git archive: только отслеживаемые файлы, .git/vendor/untracked/.env исключены по определению.
set -euo pipefail
HUB="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="${1:-}"
log(){ printf '%s\n' "$*" >&2; }
die(){ log "✗ ОШИБКА: $*"; exit 1; }
case "$SLUG" in
  ''|*[!a-zA-Z0-9_-]*) die "некорректный slug" ;;
esac
REPO="$HUB/projects/$SLUG"
[ -d "$REPO/.git" ] || die "проект не найден или не git"
command -v git >/dev/null || die "git не найден"
# Лимит размера tracked-дерева: 100 МБ
SIZE="$(git -C "$REPO" ls-tree -r -l HEAD | awk '{s+=$4} END {print s+0}')"
if [ "$SIZE" -gt 104857600 ]; then die "проект больше 100 МБ ($SIZE байт) — экспорт запрещён"; fi
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT_DIR="$HUB/data/exports"
mkdir -p "$OUT_DIR"
OUT="$OUT_DIR/$SLUG-$STAMP.zip"
[ -e "$OUT" ] && OUT="$OUT_DIR/$SLUG-$STAMP-$$.zip"
git -C "$REPO" archive --format=zip -o "$OUT" HEAD || die "archive не удался"
# Ротация: храним последние 5 архивов проекта
ls -1t "$OUT_DIR/$SLUG-"*.zip 2>/dev/null | tail -n +6 | while IFS= read -r old; do rm -f -- "$old"; done
printf '%s\n' "$OUT"
log "✓ Экспорт: $(basename "$OUT") ($(stat -c%s "$OUT") байт)"