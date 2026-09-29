#!/usr/bin/env bash
# audit-project.sh — полный аудиторский отчёт по проекту workspace в файл.
# Использование: ~/gitflicProject/bin/audit-project.sh <slug>
# Пример:        chmod 700 ~/gitflicProject/bin/audit-project.sh archive
set -eu

proj="${1:?Нужен slug проекта, например: archive}"
root="$HOME/gitflicProject/projects/$proj"
[ -d "$root" ] || { echo "Нет такого проекта: $root"; exit 1; }

# Отчёты кладём ВНЕ репозиториев — в коммиты они не попадут
mkdir -p "$HOME/gitflicProject/data/audits"
out="$HOME/gitflicProject/data/audits/${proj}-$(date +%Y%m%d_%H%M%S).txt"

cd "$root"
{
  echo "===== AUDIT $proj, $(date '+%Y-%m-%d %H:%M:%S') ====="
  echo "--- pwd: $(pwd)"
  echo "--- ветка: $(git branch --show-current), последний коммит: $(git log --oneline -1)"
  echo "--- php: $(php -v | head -n 1)"
  echo
  echo "--- список файлов (без .git):"
  find . -not -path './.git*' -type f | sort
  echo
  echo "--- data/: "
  ls -la data/ 2>/dev/null || echo "data/ отсутствует"
  echo "--- composer.json:"
  [ -f composer.json ] && cat composer.json || echo "composer.json отсутствует"
  echo
  echo "--- содержимое файлов:"
  while IFS= read -r f; do
    echo
    echo "===== FILE: $f ====="
    cat "$f"
  done < <(find . -not -path './.git*' -type f | sort)
  echo
  echo "--- история коммитов (последние 20):"
  git log --oneline | head -n 20
} > "$out" 2>&1

# В терминал — только короткая безопасная сводка
echo "Отчёт сохранён: $out"
echo "Размер: $(wc -l < "$out") строк, $(wc -c < "$out") байт"
echo "Файлы репозитория:"
find . -not -path './.git*' -type f | sort | head -n 50
# Контроль секретов: номера строк есть, значения маскированы
grep -nE '(password|token|secret|api[_-]?key)[[:space:]]*=' "$out" \
  | sed -E 's/=[^ ]*/=[скрыто]/' | head -n 10 || echo "Строк, похожих на секреты, нет"