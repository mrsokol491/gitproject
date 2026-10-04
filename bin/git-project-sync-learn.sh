#!/usr/bin/env bash
# git-project-sync-learn.sh — sync проекта с последующим созданием урока для агента.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/git-project-sync-precheck.sh"

AGENT_HOST="${AGENT_HOST:-${GIT_HOST:-gitflic}}"

if [[ "$AGENT_HOST" == "gitverse" ]]; then
  AGENT_OWNER="${GITVERSE_PROJECT_USER:-Sokol491}"
  AGENT_REMOTE_DEFAULT="https://gitverse.ru/${AGENT_OWNER}/agent-knowledge.git"
else
  AGENT_OWNER="${GITFLIC_PROJECT_USER:-sokol491}"
  AGENT_REMOTE_DEFAULT="https://gitflic.ru/project/${AGENT_OWNER}/agent-knowledge.git"
fi

AGENT_DIR="${AGENT_KNOWLEDGE_DIR:-$GIT_PROJECT_PROJECTS/agent-knowledge}"
AGENT_REMOTE="${AGENT_KNOWLEDGE_REMOTE:-$AGENT_REMOTE_DEFAULT}"

usage() {
  cat <<'USAGE_EOF'
Использование:
  git-project-sync-learn.sh <slug> [-m "сообщение"] [--lesson "текст урока"] [--rule "правило"] [--tags "теги"]

Описание:
  1. Выполняет предпроверки проекта.
  2. Если проверки пройдены, вызывает актуальный sync:
     - git-project-sync.sh, если есть;
     - иначе gitflic-sync.sh.
  3. После успешного sync создаёт урок в агенте и сохраняет его в agent-knowledge.

Переменные окружения:
  AGENT_KNOWLEDGE_DIR     локальный путь к базе знаний агента
  AGENT_KNOWLEDGE_REMOTE  git remote для agent-knowledge
  AGENT_HOST              gitflic или gitverse
  GIT_HOST                общий хост рабочего пространства
  GITFLIC_PROJECT_USER    владелец проектов на GitFlic
  GITVERSE_PROJECT_USER   владелец проектов на GitVerse

Пример:
  ~/gitflicProject/bin/git-project-sync-learn.sh visual-novel-engine \
    -m "fix: исправлены ошибки перед публикацией" \
    --lesson "Ошибка была в нарушении JSON-контракта." \
    --rule "Все API-ответы должны соответствовать {ok,data}/{ok,error,error_code}." \
    --tags "php, api, json"
USAGE_EOF
  exit 1
}

SLUG=""
MESSAGE=""
LESSON=""
RULE=""
TAGS=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    -m|--message)
      MESSAGE="${2:-}"
      shift
      if [[ $# -gt 0 ]]; then
        shift
      fi
      ;;
    --lesson)
      LESSON="${2:-}"
      shift
      if [[ $# -gt 0 ]]; then
        shift
      fi
      ;;
    --rule)
      RULE="${2:-}"
      shift
      if [[ $# -gt 0 ]]; then
        shift
      fi
      ;;
    --tags)
      TAGS="${2:-}"
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

PROJECT_DIR="$GIT_PROJECT_PROJECTS/$SLUG"
BEFORE_SHA="$(git -C "$PROJECT_DIR" rev-parse HEAD 2>/dev/null || echo "(empty)")"

ARGS=("$SLUG")
if [[ -n "$MESSAGE" ]]; then
  ARGS+=(-m "$MESSAGE")
fi

"$GIT_PROJECT_SYNC_BIN" "${ARGS[@]}"

AFTER_SHA="$(git -C "$PROJECT_DIR" rev-parse HEAD 2>/dev/null || echo "(empty)")"

if [[ "$BEFORE_SHA" == "$AFTER_SHA" && -z "$LESSON" ]]; then
  echo "[learn] Изменений нет. Урок не создаётся. Чтобы создать урок вручную, передай --lesson."
  exit 0
fi

ensure_agent_knowledge_dir() {
  if [[ -d "$AGENT_DIR/.git" ]]; then
    if git -C "$AGENT_DIR" remote get-url origin >/dev/null 2>&1; then
      git -C "$AGENT_DIR" fetch origin master || preflight_info "Не удалось fetch agent-knowledge."
      git -C "$AGENT_DIR" pull --ff-only origin master || preflight_info "Не удалось обновить agent-knowledge; используем текущее состояние."
    fi

    mkdir -p "$AGENT_DIR/LESSONS"
    return 0
  fi

  if git ls-remote "$AGENT_REMOTE" >/dev/null 2>&1; then
    git clone "$AGENT_REMOTE" "$AGENT_DIR" || preflight_die "Не удалось клонировать $AGENT_REMOTE."
  else
    preflight_info "Репозиторий agent-knowledge не найден на удалённом хосте."
    preflight_info "Для публикации создай репозиторий через Web-UI, затем подключи origin."
    preflight_info "Создаём локальный черновик базы знаний."

    mkdir -p "$AGENT_DIR"
    git -C "$AGENT_DIR" init -b master
  fi

  mkdir -p "$AGENT_DIR/LESSONS"
}

ensure_agent_knowledge_dir

DATE_ISO="$(date +%Y-%m-%d)"
TS="$(date +%Y%m%d_%H%M%S)"
SAFE_SLUG="$(printf '%s' "$SLUG" | tr -c '[:alnum:]-' '-')"
LESSON_REL="LESSONS/${DATE_ISO}_${TS}_${SAFE_SLUG}_sync.md"

while [[ -f "$AGENT_DIR/$LESSON_REL" ]]; do
  LESSON_REL="LESSONS/${DATE_ISO}_${TS}_${SAFE_SLUG}_sync_${RANDOM}.md"
done

if [[ -z "$MESSAGE" ]]; then
  MESSAGE="sync: $SLUG"
fi

if [[ -z "$LESSON" ]]; then
  LESSON="Автоурок: проект $SLUG прошёл предпроверки и был синхронизирован."
fi

if [[ -z "$RULE" ]]; then
  RULE="Перед любым sync добиваться прохождения проверок и исправлять ошибки."
fi

TAGS_LINE="sync, ${SAFE_SLUG}"
if [[ -n "$TAGS" ]]; then
  TAGS_LINE+=", ${TAGS}"
fi

CHANGED_FILES="$(git -C "$PROJECT_DIR" diff --name-only "$BEFORE_SHA".."$AFTER_SHA" 2>/dev/null || true)"
LOG_LINES="$(git -C "$PROJECT_DIR" log --oneline "$BEFORE_SHA".."$AFTER_SHA" 2>/dev/null || true)"

cat > "$AGENT_DIR/$LESSON_REL" <<EOF
---
id: lesson-${TS}-${SAFE_SLUG}-sync
type: lesson
status: active
tags: [${TAGS_LINE}]
created: ${DATE_ISO}
project: ${SLUG}
before: ${BEFORE_SHA}
after: ${AFTER_SHA}
---

# Урок синхронизации: ${SLUG}

## Дата
$(date '+%Y-%m-%d %H:%M:%S')

## Проект
\`${SLUG}\`

## Команда синхронизации
\`git-project-sync-learn.sh ${SLUG} -m "${MESSAGE}"\`

## Сообщение коммита
${MESSAGE}

## Урок
${LESSON}

## Правило для агента
${RULE}

## Commits
\`\`\`text
${LOG_LINES:-нет новых коммитов}
\`\`\`

## Изменённые файлы
\`\`\`text
${CHANGED_FILES:-нет изменений}
\`\`\`

## Проверки
- Предпроверки пройдены.
- Ошибки до синхронизации должны быть исправлены.
- Использован актуальный sync: ${GIT_PROJECT_SYNC_BIN}
EOF

git -C "$AGENT_DIR" add "$LESSON_REL"

if ! git -C "$AGENT_DIR" diff --cached --quiet 2>/dev/null; then
  git -C "$AGENT_DIR" commit -m "learn(${SLUG}): sync lesson ${DATE_ISO}"
fi

if git -C "$AGENT_DIR" remote get-url origin >/dev/null 2>&1; then
  if [[ "$AGENT_DIR" == "$GIT_PROJECT_PROJECTS/agent-knowledge" && -x "$GIT_PROJECT_SYNC_BIN" ]]; then
    "$GIT_PROJECT_SYNC_BIN" agent-knowledge -m "learn(${SLUG}): sync lesson ${DATE_ISO}"
  else
    if ! git -C "$AGENT_DIR" push -u origin master; then
      preflight_die "Push agent-knowledge failed. Если истории независимы: cd $AGENT_DIR && git merge origin/master --allow-unrelated-histories -X ours"
    fi
  fi

  AGENT_SHA="$(git -C "$AGENT_DIR" rev-parse HEAD 2>/dev/null || true)"

  if [[ "$AGENT_HOST" == "gitverse" ]]; then
    echo "Урок опубликован: https://gitverse.ru/api/repos/${AGENT_OWNER}/agent-knowledge/raw/branch/master/${LESSON_REL}"
  else
    if [[ -n "$AGENT_SHA" ]]; then
      echo "Урок опубликован: https://gitflic.ru/project/${AGENT_OWNER}/agent-knowledge/blob/raw?file=${LESSON_REL}&commit=${AGENT_SHA}"
    else
      echo "Урок опубликован: https://gitflic.ru/project/${AGENT_OWNER}/agent-knowledge/blob?branch=master&file=${LESSON_REL}"
    fi
  fi
else
  echo "Урок сохранён локально: $AGENT_DIR/$LESSON_REL"
  echo "Для публикации создай/подключи origin: $AGENT_REMOTE"
fi
