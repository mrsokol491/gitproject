#!/usr/bin/env bash
# gitproject-rollback.sh — откат хаба к backup-ветке: list | <branch> [--yes]
set -euo pipefail
HUB="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd -- "$HUB"
log(){ printf '%s\n' "$*" >&2; }
die(){ log "✗ ОШИБКА: $*"; exit 1; }
CMD="${1:-list}"
if [ "$CMD" = list ]; then
  git for-each-ref 'refs/heads/backup/pre-*' --sort=-refname \
    --format='%(refname:short)%09%(objectname:short)%09%(creatordate:format:%d.%m.%Y %H:%M)'
  exit 0
fi
ASSUME_YES=0
[ "${2:-}" = "--yes" ] && ASSUME_YES=1
git show-ref --verify --quiet "refs/heads/$CMD" || die "ветка не найдена: $CMD"
case "$CMD" in backup/pre-*) ;; *) die "откат только к backup/pre-*" ;; esac
TARGET="$(git rev-parse "$CMD")"
LOCAL="$(git rev-parse HEAD)"
if [ "$TARGET" = "$LOCAL" ]; then log "✓ Уже на этом состоянии"; exit 0; fi
DIRTY="$(git status --porcelain --untracked-files=no)"
log "Откат: ${LOCAL:0:7} -> ${TARGET:0:7} ($CMD)"
if [ "$ASSUME_YES" -ne 1 ]; then
  [ -t 0 ] || die "нужен --yes без tty"
  printf 'Откатить? (Д/Н): ' >&2
  IFS= read -r a || true
  case "$a" in Д|д|Y|y) ;; *) die "отменено" ;; esac
fi
STAMP="$(date +%Y%m%d-%H%M%S)"
NEWBACK="backup/pre-rollback-$STAMP"
git show-ref --verify --quiet "refs/heads/$NEWBACK" && NEWBACK="$NEWBACK-$$"
git branch -- "$NEWBACK" "$LOCAL"
log "Backup текущего: $NEWBACK"
if [ -n "$DIRTY" ]; then git stash push -m "pre-rollback-$STAMP" >&2; fi
git read-tree -u --reset "$TARGET" >&2
while IFS= read -r f; do
  [ -n "$f" ] || continue
  git cat-file -e "$TARGET:$f" 2>/dev/null && continue
  blob="$(git rev-parse "$LOCAL:$f")"
  mode="$(git ls-tree "$LOCAL" -- "$f" | awk '{print $1}')"
  git update-index --add --cacheinfo "$mode,$blob,$f"
  git checkout-index -f -- "$f"
done < <(git diff --name-only --diff-filter=A "$TARGET" "$LOCAL")
git commit -q --allow-empty -m "rollback: -> $CMD" >&2
log "✓ Откат выполнен ($(git rev-parse --short=7 HEAD)), текущее сохранено в $NEWBACK"