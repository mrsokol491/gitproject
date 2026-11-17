#!/usr/bin/env bash
# gitproject-update.sh — обновление хаба из GitVerse: stable (релиз-тег) или edge (master).
# Обновление: merge -X theirs. Даунгрейд: общие файлы к цели, локальные новые сохраняются.
# Идемпотентность: общие файлы совпадают → только метка версии. Backup + stash. Без force-push.
# Портативно: remote gitverse создаётся сам; origin на GitVerse используется как есть.
set -euo pipefail
HUB="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
REMOTE="gitverse"; REMOTE_URL="https://gitverse.ru/Sokol491/gitproject.git"; BRANCH="master"
CHANNEL="${1:-stable}"; ASSUME_YES=0
[ "${2:-}" = "--yes" ] && ASSUME_YES=1
log(){ printf '%s\n' "$*" >&2; }
die(){ log "✗ ОШИБКА: $*"; exit 1; }
case "$CHANNEL" in stable|edge) ;; *) die "канал: stable|edge" ;; esac
cd -- "$HUB"
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || die "не git-репозиторий"
if git remote get-url "$REMOTE" >/dev/null 2>&1; then
  CUR="$(git remote get-url "$REMOTE")"
  if [ "$CUR" != "$REMOTE_URL" ]; then
    log "ℹ remote $REMOTE: выравниваю URL на $REMOTE_URL"
    git remote set-url "$REMOTE" "$REMOTE_URL"
  fi
elif [ "$(git remote get-url origin 2>/dev/null || true)" = "$REMOTE_URL" ]; then
  REMOTE="origin"
  log "ℹ Использую remote origin (клон с GitVerse)"
else
  log "ℹ Добавляю remote $REMOTE → $REMOTE_URL"
  git remote add "$REMOTE" "$REMOTE_URL"
fi
GD="$(git rev-parse --absolute-git-dir)"
if [ -e "$GD/MERGE_HEAD" ] || [ -d "$GD/rebase-merge" ] || [ -d "$GD/rebase-apply" ]; then
  die "незавершённый merge/rebase: сначала --abort"
fi
git fetch "$REMOTE" "$BRANCH" --tags --force >&2 || die "fetch не удался"
LOCAL_SHA="$(git rev-parse HEAD)"
EDGE_SHA="$(git rev-parse "refs/remotes/$REMOTE/$BRANCH")"
STABLE_TAG="$(git tag --list --sort=-v:refname | while IFS= read -r t; do
  case "$t" in *-*) continue ;; esac
  ts="$(git rev-list -n1 "$t" 2>/dev/null || true)"
  [ -n "$ts" ] || continue
  if git merge-base --is-ancestor "$ts" "$EDGE_SHA" 2>/dev/null; then printf '%s' "$t"; break; fi
done)"
[ -n "$STABLE_TAG" ] || die "стабильных тегов нет"
STABLE_SHA="$(git rev-list -n1 "$STABLE_TAG")"
if [ "$CHANNEL" = stable ]; then
  TARGET_SHA="$STABLE_SHA"; TARGET_LABEL="$STABLE_TAG"
else
  TARGET_SHA="$EDGE_SHA"; TARGET_LABEL="$STABLE_TAG-r$(git rev-parse --short=6 "$EDGE_SHA")"
fi
BEHIND="$(git rev-list --count "$LOCAL_SHA..$TARGET_SHA" 2>/dev/null || echo '?')"
AHEAD="$(git rev-list --count "$TARGET_SHA..$LOCAL_SHA" 2>/dev/null || echo '?')"
DIRTY="$(git status --porcelain --untracked-files=no)"
IS_ANCESTOR=0
git merge-base --is-ancestor "$TARGET_SHA" "$LOCAL_SHA" 2>/dev/null && IS_ANCESTOR=1
COMMON_DIFF="$(git diff --name-only --diff-filter=d "$TARGET_SHA" "$LOCAL_SHA" -- $(git ls-tree -r --name-only "$TARGET_SHA") 2>/dev/null || true)"
log "Канал: $CHANNEL | цель: $TARGET_LABEL (${TARGET_SHA:0:7}) | remote: $REMOTE"
log "Локально: ${LOCAL_SHA:0:7} | позади: $BEHIND, впереди: $AHEAD"
if [ -z "$COMMON_DIFF" ] && [ -z "$DIRTY" ]; then
  mkdir -p "$HUB/data"
  printf '%s\n' "$TARGET_LABEL" > "$HUB/data/version.txt"
  log "✓ Уже на $TARGET_LABEL (общие файлы совпадают), метка записана"
  exit 0
fi
DOWNGRADE=0
if [ "$IS_ANCESTOR" -eq 1 ]; then
  DOWNGRADE=1
  log "⚠ Даунгрейд: $TARGET_LABEL старше HEAD — общие файлы вернутся к цели, локальные новые сохранятся"
fi
if [ "$ASSUME_YES" -ne 1 ]; then
  [ -t 0 ] || die "нужен --yes без tty"
  printf 'Обновить до %s? (Д/Н): ' "$TARGET_LABEL" >&2
  IFS= read -r a || true
  case "$a" in Д|д|Y|y) ;; *) die "отменено" ;; esac
fi
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="backup/pre-update-$STAMP"
git show-ref --verify --quiet "refs/heads/$BACKUP" && BACKUP="$BACKUP-$$"
git branch -- "$BACKUP" "$LOCAL_SHA"
log "Backup: $BACKUP"
if [ -n "$DIRTY" ]; then
  git stash push -m "pre-update-$STAMP" >&2 || die "stash не удался"
  log "Правки в stash (вернуть: git stash pop)"
fi
if [ "$DOWNGRADE" -eq 1 ]; then
  git read-tree -u --reset "$TARGET_SHA" >&2
  while IFS= read -r f; do
    [ -n "$f" ] || continue
    git cat-file -e "$TARGET_SHA:$f" 2>/dev/null && continue
    blob="$(git rev-parse "$LOCAL_SHA:$f")"
    mode="$(git ls-tree "$LOCAL_SHA" -- "$f" | awk '{print $1}')"
    git update-index --add --cacheinfo "$mode,$blob,$f"
    git checkout-index -f -- "$f"
  done < <(git diff --name-only --diff-filter=A "$TARGET_SHA" "$LOCAL_SHA")
  if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    git commit -qm "update: downgrade $CHANNEL -> $TARGET_LABEL (локальные файлы сохранены)" >&2
  else
    git commit -q --allow-empty -m "update: downgrade $CHANNEL -> $TARGET_LABEL" >&2
  fi
else
  if ! git merge --no-edit -X theirs "$TARGET_SHA" -m "update: $CHANNEL -> $TARGET_LABEL" >&2; then
    git merge --abort 2>/dev/null || true
    die "конфликт: откат, backup $BACKUP"
  fi
fi
mkdir -p "$HUB/data"
printf '%s\n' "$TARGET_LABEL" > "$HUB/data/version.txt"
log "✓ Обновлено до $TARGET_LABEL ($(git rev-parse --short=7 HEAD)), backup: $BACKUP"