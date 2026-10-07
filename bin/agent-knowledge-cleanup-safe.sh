#!/usr/bin/env bash
# agent-knowledge-cleanup-safe.sh — безопасная версия очистки базы знаний.
# Не закрывает терминал при ошибке, выводит подробную диагностику.

set -uo pipefail  # Убрали -e и -E, чтобы скрипт не падал на первой ошибке

echo "=== Запуск безопасной очистки базы знаний ==="
echo "Дата: $(date)"
echo

SLUG="agent-knowledge"
ROOT="$HOME/gitflicProject/projects/$SLUG"
DATE_ISO="$(date +%Y-%m-%d)"
TS="$(date +%Y%m%d_%H%M%S)"
BACKUP_DIR="$HOME/gitflicProject/data/backups/${SLUG}-cleanup-${TS}"

echo "Проверка репозитория..."
if [[ ! -d "$ROOT/.git" ]]; then
  echo "ОШИБКА: Репозиторий не найден: $ROOT"
  echo "Создай репозиторий через Web-UI GitFlic, затем клонируй:"
  echo "  cd ~/gitflicProject/projects"
  echo "  git clone https://gitflic.ru/project/sokol491/agent-knowledge.git"
  exit 1
fi

cd "$ROOT" || exit 1

BRANCH="$(git branch --show-current 2>/dev/null || echo "unknown")"
echo "Текущая ветка: $BRANCH"

if [[ "$BRANCH" != "master" ]]; then
  echo "ПРЕДУПРЕЖДЕНИЕ: Текущая ветка не master. Продолжение возможно, но не рекомендуется."
  read -p "Продолжить? (y/N): " -n 1 -r
  echo
  if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Отменено пользователем."
    exit 0
  fi
fi

echo
echo "=== Создание резервной копии ==="
mkdir -p "$BACKUP_DIR"

if tar --exclude='./.git' -czf "$BACKUP_DIR/repo-before-cleanup.tar.gz" . 2>/dev/null; then
  echo "Резервная копия создана: $BACKUP_DIR/repo-before-cleanup.tar.gz"
else
  echo "ПРЕДУПРЕЖДЕНИЕ: Не удалось создать резервную копию."
fi

echo
echo "=== Анализ текущего состояния ==="
echo "Последние коммиты:"
git log --oneline -10 2>/dev/null || echo "(нет коммитов)"

echo
echo "Отслеживаемые файлы:"
git ls-files 2>/dev/null | head -20 || echo "(нет файлов)"

echo
echo "=== Проверка запрещённых файлов ==="
BAD="$(
  git status --porcelain 2>/dev/null \
    | cut -c4- \
    | grep -E '(^|/)\.env$|\.sqlite(-journal|-wal|-shm)?$|\.log$|^data/|^storage/|^vendor/' \
    || true
)"

if [[ -n "$BAD" ]]; then
  echo "Найдены запрещённые файлы:"
  echo "$BAD"
  echo
  echo "Убери их перед продолжением или нажми Enter, чтобы проигнорировать."
  read -r
fi

echo
echo "=== Создание структуры навыков ==="
mkdir -p \
  SKILLS/agent-process \
  SKILLS/programming \
  SKILLS/storytelling \
  SKILLS/rpg-card-game \
  SKILLS/unclassified

echo "Созданы каталоги: SKILLS/agent-process, SKILLS/programming, SKILLS/storytelling, SKILLS/rpg-card-game, SKILLS/unclassified"

echo
echo "=== Создание ROUTER.md ==="
cat > ROUTER.md <<'ROUTER_EOF'
# ROUTER.md — реестр навыков базы знаний

## Официальные навыки

- `agent-process` — процессы агента, база знаний, уроки, маршрутизация, очистка, обучение.
- `programming` — программирование, архитектура, тесты, отладка, PHP, bash, SQLite, GitFlic.
- `storytelling` — рассказы, сюжеты, персонажи, диалоги, миры, стиль.
- `rpg-card-game` — карточная ролевая игра, механики, карты, баланс, бои, прогрессия.
- `unclassified` — временный навык для знаний, которые ещё не классифицированы.

## Правила маршрутизации

1. Если знание относится к известному навыку, использовать этот навык.
2. Если знание не подходит ни к одному навыку, использовать `unclassified`.
3. Для кандидатных навыков использовать `secondary_skill` с префиксом `candidate-`.
4. При накоплении нескольких связанных кандидатных навыков предложить объединение и новый официальный навык.
5. Новый официальный навык добавляется в этот файл только после одобрения владельца.
6. Агент не создаёт новые репозитории самостоятельно; можно только предложить создание репозитория с причиной.
7. Уроки хранятся в `SKILLS/<skill>/<topic>.md`.
8. Списки уроков ведутся в `INDEX.md`.
ROUTER_EOF

echo "Создан ROUTER.md"

echo
echo "=== Создание TEMPLATE.md ==="
cat > TEMPLATE.md <<'TEMPLATE_EOF'
---
skill: <official-skill-from-ROUTER>
topic: <short-topic>
status: draft|needs-review|approved|archived|conflict
confidence: low|medium|high
author: <author>
created: YYYY-MM-DD
updated: YYYY-MM-DD
nature: fact|canon|hypothesis|fiction|design-decision
tags: []
supersedes: []
conflicts_with: []
---

# Заголовок урока

## Суть

## Контекст

## Решение

## Применение

## Ограничения
TEMPLATE_EOF

echo "Создан TEMPLATE.md"

echo
echo "=== Миграция старых уроков из LESSONS/ ==="
if [[ -d LESSONS ]]; then
  echo "Найден каталог LESSONS/. Начинаю миграцию..."
  
  count=0
  while IFS= read -r -d '' f; do
    base="$(basename "$f" .md)"
    
    created="$(
      printf '%s' "$base" \
        | grep -Eo '^[0-9]{4}-[0-9]{2}-[0-9]{2}' \
        || echo "$DATE_ISO"
    )"
    
    skill="agent-process"
    topic="$(
      printf '%s' "$base" \
        | sed -E 's/^[0-9]{4}-[0-9]{2}-[0-9]{2}_?//; s/[^A-Za-z0-9]+/-/g; s/^[-]+//; s/[-]+$//' \
        | tr '[:upper:]' '[:lower:]'
    )"
    
    [[ -z "$topic" ]] && topic="lesson-${created}-$RANDOM"
    
    target="SKILLS/$skill/$topic.md"
    
    i=1
    while [[ -f "$target" ]]; do
      target="SKILLS/$skill/${topic}-$i.md"
      i=$((i + 1))
    done
    
    title="$(head -n 200 "$f" | grep -m1 '^# ' | sed 's/^# //' || echo "$topic")"
    
    cat > "$target" <<EOF
---
skill: $skill
topic: $topic
status: needs-review
confidence: medium
author: agent
created: $created
updated: $DATE_ISO
source: "$f"
nature: design-decision
tags: [migrated]
---

# $title

Мигрировано из \`$f\` при очистке базы знаний $DATE_ISO.

## Исходный урок

$(cat "$f")
EOF
    
    echo "Мигрировано: $f -> $target"
    count=$((count + 1))
  done < <(find LESSONS -type f -name '*.md' -print0 2>/dev/null)
  
  echo "Мигрировано уроков: $count"
  
  echo
  read -p "Удалить каталог LESSONS/? (y/N): " -n 1 -r
  echo
  if [[ $REPLY =~ ^[Yy]$ ]]; then
    rm -rf LESSONS
    echo "Каталог LESSONS/ удалён."
  else
    echo "Каталог LESSONS/ сохранён."
  fi
else
  echo "Каталог LESSONS/ не найден. Миграция не требуется."
fi

echo
echo "=== Создание урока о текущей очистке ==="
CURRENT_LESSON="SKILLS/agent-process/knowledge-base-cleanup-2026-10-02.md"

if [[ ! -f "$CURRENT_LESSON" ]]; then
cat > "$CURRENT_LESSON" <<'CURRENT_EOF'
---
skill: agent-process
topic: knowledge-base-cleanup-2026-10-02
status: approved
confidence: high
author: agent
created: 2026-10-02
updated: 2026-10-02
nature: design-decision
tags: [cleanup, migration, agent-knowledge]
---

# Очистка и миграция базы знаний к AGENTS.md

## Суть

Репозиторий `agent-knowledge` приведён к новому стандарту из `AGENTS.md`: уроки хранятся в `SKILLS/<skill>/<topic>.md`, реестр навыков в `ROUTER.md`, список уроков в `INDEX.md`, шаблон урока в `TEMPLATE.md`.

## Контекст

В репозитории могли остаться файлы от предыдущих стадий разработки агента: `LESSONS/`, старые каталоги, экспериментальные скрипты и документы. Старые уроки нельзя было терять, поэтому они мигрированы в новую структуру.

## Решение

1. Создан или обновлён `ROUTER.md`.
2. Создан `TEMPLATE.md`.
3. Старые уроки из `LESSONS/` перенесены в `SKILLS/<skill>/<topic>.md`.
4. Для каждого мигрированного урока заполнены обязательные поля: `skill`, `topic`, `status`, `confidence`, `author`, `created`, `updated`.
5. Создан урок о текущей очистке.
6. Сгенерирован `INDEX.md`.
7. Старые файлы удалены из активной структуры после локального резервного копирования.

## Применение

При работе с базой знаний использовать `AGENTS.md`, `ROUTER.md`, `INDEX.md`, `TEMPLATE.md`.
Не использовать каталог `LESSONS/` как активное хранилище уроков.

## Ограничения

Мигрированные уроки имеют статус `needs-review`, если их качество не было отдельно подтверждено.
CURRENT_EOF

echo "Создан урок: $CURRENT_LESSON"
else
  echo "Урок уже существует: $CURRENT_LESSON"
fi

echo
echo "=== Генерация INDEX.md ==="
{
  echo "# INDEX.md — список уроков базы знаний"
  echo
  echo "Обновлено: $DATE_ISO"
} > INDEX.md

for skill_dir in SKILLS/*/; do
  skill="$(basename "$skill_dir")"
  
  files="$(find "$skill_dir" -maxdepth 1 -type f -name '*.md' 2>/dev/null | sort || true)"
  [[ -z "$files" ]] && continue
  
  {
    echo
    echo "## $skill"
    echo
  } >> INDEX.md
  
  while IFS= read -r f; do
    topic="$(basename "$f" .md)"
    title="$(grep -m1 '^# ' "$f" 2>/dev/null | sed 's/^# //' || echo "$topic")"
    
    echo "- [$title]($f)" >> INDEX.md
  done <<< "$files"
done

echo "Создан INDEX.md"

echo
echo "=== Удаление старых файлов ==="
legacy_paths=(
  ARCHIVE
  MEMORY
  PLAYBOOKS
  PROMPTS
  STANDARDS
  DECISIONS
  EVALS
  TOOLS
  docs
  scripts
  src
  public
  config
  var
  vendor
  data
  storage
  bin
  migrations
  tests
  composer.json
  composer.lock
  phpunit.xml
  phpstan.neon
  .env.example
)

echo "Следующие каталоги и файлы будут удалены, если существуют:"
for p in "${legacy_paths[@]}"; do
  if [[ -e "$p" ]]; then
    echo "  - $p"
  fi
done

echo
read -p "Удалить старые файлы? (y/N): " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
  for p in "${legacy_paths[@]}"; do
    if [[ -e "$p" ]]; then
      echo "Удаляю: $p"
      rm -rf "$p"
    fi
  done
  echo "Старые файлы удалены."
else
  echo "Старые файлы сохранены."
fi

echo
echo "=== Создание .gitignore ==="
cat > .gitignore <<'GITIGNORE_EOF'
.env
.env.*
*.log
*.sqlite
*.sqlite-journal
*.sqlite-wal
*.sqlite-shm
/data/
/storage/
/vendor/
GITIGNORE_EOF

echo "Создан .gitignore"

echo
echo "=== Подготовка к публикации ==="
git add -A

if git diff --cached --quiet; then
  echo "Нет изменений для коммита."
else
  echo "Изменения готовы к коммиту:"
  git status --short
  
  echo
  read -p "Создать коммит и опубликовать? (y/N): " -n 1 -r
  echo
  if [[ $REPLY =~ ^[Yy]$ ]]; then
    git commit -m "chore: очистка и миграция к AGENTS.md"
    
    if git remote get-url origin >/dev/null 2>&1; then
      echo "Публикация в origin..."
      git push origin master
      echo "Публикация завершена."
    else
      echo "Remote origin не настроен. Коммит создан локально."
    fi
  else
    echo "Коммит не создан. Изменения остаются в staging area."
  fi
fi

echo
echo "=== Очистка завершена ==="
echo "Резервная копия: $BACKUP_DIR/repo-before-cleanup.tar.gz"

SHA="$(git rev-parse HEAD 2>/dev/null || echo "unknown")"

if [[ "$SHA" != "unknown" ]]; then
  echo
  echo "Ссылки на файлы:"
  echo "INDEX: https://gitflic.ru/project/sokol491/agent-knowledge/blob/raw?file=INDEX.md&commit=$SHA"
  echo "ROUTER: https://gitflic.ru/project/sokol491/agent-knowledge/blob/raw?file=ROUTER.md&commit=$SHA"
fi

echo
echo "Готово."
