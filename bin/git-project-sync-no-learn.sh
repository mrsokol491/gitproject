#!/usr/bin/env bash
# git-project-sync-no-learn.sh — безопасный обычный sync проекта без обучения агента.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/git-project-sync-precheck.sh"

usage() {
  cat <<'USAGE_EOF'
Использование:
  git-project-sync-no-learn.sh <slug> [-m "сообщение"]

Описание:
  1. Выполняет предпроверки проекта:
     - только ветка master;
     - запрещённые файлы;
     - конфликты слияния;
     - composer validate/install/test/stan для PHP;
     - php -l по отслеживаемым PHP-файлам;
     - mvn test для Maven;
     - npm test для Node, если test объявлен.
  2. Если проверки пройдены, вызывает актуальный sync:
     - git-project-sync.sh, если есть;
     - иначе gitflic-sync.sh.
  3. Не создаёт уроки и не сохраняет знания агента.
USAGE_EOF
  exit 1
}

SLUG=""
MESSAGE=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    -m|--message)
      MESSAGE="${2:-}"
      shift
      if [[ $# -gt 0 ]]; then
        shift
      fi
      ;;
    -h|--help)
      usage
      ;;
    *)
      if [[ -z "$SLUG" ]]; then
        SLUG="$1"
        shift
      else
        echo "Неизвестный аргумент: $1" >&2
        usage
      fi
      ;;
  esac
done

[[ -n "$SLUG" ]] || usage

preflight_project "$SLUG"

ARGS=("$SLUG")
if [[ -n "$MESSAGE" ]]; then
  ARGS+=(-m "$MESSAGE")
fi

exec "$GIT_PROJECT_SYNC_BIN" "${ARGS[@]}"
