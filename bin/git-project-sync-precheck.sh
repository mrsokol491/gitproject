#!/usr/bin/env bash
# git-project-sync-precheck.sh — общие проверки перед sync проекта.
# Не является отдельным sync-скриптом; подключается обёртками.

set -Eeuo pipefail

GIT_PROJECT_WORKSPACE="${GIT_PROJECT_WORKSPACE:-$HOME/gitproject}"
GIT_PROJECT_PROJECTS="$GIT_PROJECT_WORKSPACE/projects"

if [[ -x "$GIT_PROJECT_WORKSPACE/bin/git-project-sync.sh" ]]; then
  GIT_PROJECT_SYNC_BIN="$GIT_PROJECT_WORKSPACE/bin/git-project-sync.sh"
elif [[ -x "$GIT_PROJECT_WORKSPACE/bin/gitflic-sync.sh" ]]; then
  GIT_PROJECT_SYNC_BIN="$GIT_PROJECT_WORKSPACE/bin/gitflic-sync.sh"
else
  GIT_PROJECT_SYNC_BIN="$GIT_PROJECT_WORKSPACE/bin/git-project-sync.sh"
fi

preflight_info() {
  echo "[precheck] $*"
}

preflight_die() {
  echo "[precheck][ERROR] $*" >&2
  exit 1
}

is_forbidden_path() {
  local p="$1"

  # Разрешённые служебные исключения.
  case "$p" in
    data/.gitkeep|storage/.gitkeep|.env.example|*/.env.example)
      return 1
      ;;
  esac

  # Запрещённые пути по STANDARD.md и защитами синхронизации.
  case "$p" in
    .env|.env.*|*/.env|*/.env.*)
      return 0
      ;;
    *.log)
      return 0
      ;;
    *.sqlite|*.sqlite-journal|*.sqlite-wal|*.sqlite-shm)
      return 0
      ;;
    .phpunit.result.cache|.phpunit.cache|.phpstan.cache)
      return 0
      ;;
    */.phpunit.result.cache|*/.phpunit.cache|*/.phpstan.cache)
      return 0
      ;;
    data/*|storage/*|vendor/*|node_modules/*)
      return 0
      ;;
  esac

  return 1
}

check_forbidden_files() {
  local dir="$1"
  local bad=""
  local p

  while IFS= read -r -d '' p; do
    if is_forbidden_path "$p"; then
      bad+="$p"$'\n'
    fi
  done < <(git -C "$dir" ls-files --cached --others --exclude-standard -z)

  if [[ -n "$bad" ]]; then
    printf '%s' "$bad" >&2
    preflight_die "Найдены запрещённые файлы. Убери их из публикации и проверь .gitignore."
  fi
}

has_composer_script() {
  local script="$1"

  composer run-script --list | awk -v s="$script" '$1 == s { found = 1 } END { exit !found }'
}


check_markdown_quality() {
  local dir="$1"
  local agent_knowledge="$HOME/gitproject/projects/agent-knowledge"
  local slopcheck="$agent_knowledge/skills/anti-ai-slop/scripts/slopcheck.py"
  local rules_ru="$agent_knowledge/skills/anti-ai-slop/scripts/rules-ru.json"

  # Проверка доступна только если anti-ai-slop установлен
  if [[ ! -f "$slopcheck" ]] || [[ ! -f "$rules_ru" ]]; then
    preflight_info "Markdown: anti-ai-slop не установлен, проверка пропущена"
    return 0
  fi

  if ! command -v python3 >/dev/null 2>&1; then
    preflight_info "Markdown: python3 не найден, проверка пропущена"
    return 0
  fi

  preflight_info "Markdown: проверка качества .md файлов через anti-ai-slop"

  local md_files=()
  while IFS= read -r -d '' file; do
    # Пропускаем vendor/, node_modules/, data/, storage/
    case "$file" in
      */vendor/*|*/node_modules/*|data/*|storage/*|vendor/*|node_modules/*) continue ;;
    esac

    # Пропускаем CHANGELOG.md и LICENSE на любой глубине
    case "$(basename "$file")" in
      CHANGELOG.md|LICENSE|LICENSE.md|CHANGELOG) continue ;;
    esac

    md_files+=("$file")
  done < <(git -C "$dir" ls-files -z -- '*.md')

  if [[ ${#md_files[@]} -eq 0 ]]; then
    preflight_info "Markdown: .md файлы не найдены"
    return 0
  fi

  local failed=0
  local checked=0
  local threshold=90

  for file in "${md_files[@]}"; do
    local exit_code=0
    local full_path="$dir/$file"

    if [[ ! -f "$full_path" ]]; then
      continue
    fi

    python3 "$slopcheck" "$full_path" --rules "$rules_ru" --gate "$threshold" --quiet >/dev/null 2>&1 || exit_code=$?

    checked=$((checked + 1))

    if [[ $exit_code -eq 0 ]]; then
      preflight_info "Markdown: $file [OK]"
    elif [[ $exit_code -eq 1 ]]; then
      preflight_info "Markdown: $file [FAIL] (score < $threshold)"
      failed=$((failed + 1))
    else
      preflight_info "Markdown: $file [SKIP] (ошибка запуска)"
    fi
  done

  if [[ $failed -gt 0 ]]; then
    preflight_die "Markdown: $failed файлов ниже порога качества ($threshold/100). Исправь находки перед синхронизацией."
  fi

  preflight_info "Markdown: проверено $checked файлов, все прошли порог $threshold"
}

check_remotes_available() {
  local dir="$1"
  
  # Получаем список remote
  local remotes
  remotes="$(git -C "$dir" remote 2>/dev/null || true)"
  
  check_remotes_available "$dir"

  if [[ -z "$remotes" ]]; then
    preflight_die "У проекта нет настроенных remote. Добавь через: git-project-remote.sh $slug --add <domain>"
  fi
  
  # Проверяем, что хотя бы один remote доступен
  local found_ready=0
  while IFS= read -r remote; do
    [[ -z "$remote" ]] && continue
    if git -C "$dir" ls-remote "$remote" HEAD >/dev/null 2>&1; then
      found_ready=1
      preflight_info "Remote доступен: $remote"
      break
    fi
  done <<< "$remotes"
  
  if [[ $found_ready -eq 0 ]]; then
    preflight_die "Ни один remote недоступен. Проверь: git-project-remote.sh $slug --list"
  fi
}
run_project_checks() {
  local dir="$1"

  pushd "$dir" >/dev/null

  # Если есть PHP-файлы, но нет composer.json — это нарушение STANDARD.md.
  if [[ ! -f composer.json ]] && git ls-files -z -- '*.php' | grep -zq .; then
    preflight_die "Найдены PHP-файлы, но нет composer.json. STANDARD.md требует Composer даже без зависимостей."
  fi

  if [[ -f composer.json ]]; then
    command -v composer >/dev/null 2>&1 || preflight_die "Найден composer.json, но composer не установлен."
    command -v php >/dev/null 2>&1 || preflight_die "Найден composer.json, но php не установлен."

    preflight_info "PHP: composer validate"
    composer validate --no-check-publish

    preflight_info "PHP: composer install"
    composer install --no-interaction --prefer-dist --no-progress

    if ! has_composer_script test; then
      preflight_die "В composer.json нет script 'test'. Добавь по STANDARD.md: \"test\": \"phpunit\"."
    fi

    if ! has_composer_script stan; then
      preflight_die "В composer.json нет script 'stan'. Добавь по STANDARD.md: \"stan\": \"phpstan analyse src\"."
    fi

    preflight_info "PHP: composer test"
    composer test

    preflight_info "PHP: composer stan"
    composer stan
  fi

  if [[ -f pom.xml ]]; then
    command -v mvn >/dev/null 2>&1 || preflight_die "Найден pom.xml, но mvn не установлен."
    preflight_info "Java: mvn test"
    mvn -B -q test
  fi

  if [[ -f package.json ]]; then
    if command -v node >/dev/null 2>&1 && node -e "const p=require('./package.json'); process.exit(p.scripts && p.scripts.test ? 0 : 1)" >/dev/null 2>&1; then
      command -v npm >/dev/null 2>&1 || preflight_die "В package.json объявлен npm test, но npm не установлен."
      preflight_info "Node: npm test"
      npm test
    fi
  if command -v php >/dev/null 2>&1; then
    preflight_info "PHP: php -l по всем отслеживаемым *.php"
    local f
    while IFS= read -r -d '' f; do
      php -l "$f" >/dev/null || preflight_die "php -l failed: $f"
    done < <(git ls-files -z -- '*.php')
  fi

  check_markdown_quality "$dir"

  popd >/dev/null
}

preflight_project() {
  local slug="$1"
  local dir="$GIT_PROJECT_PROJECTS/$slug"

  [[ -n "$slug" ]] || preflight_die "Не указан slug проекта."
  [[ -d "$dir/.git" ]] || preflight_die "Проект не найден: $dir"
  [[ -x "$GIT_PROJECT_SYNC_BIN" ]] || preflight_die "Не найден исполняемый файл: $GIT_PROJECT_SYNC_BIN"

  local branch
  branch="$(git -C "$dir" branch --show-current)"
  [[ "$branch" == "master" ]] || preflight_die "Текущая ветка '$branch'. Разрешён только master."

  if ! git -C "$dir" remote get-url origin >/dev/null 2>&1; then
    preflight_die "Нет remote origin. Выполни: $GIT_PROJECT_SYNC_BIN fix $slug"
  fi

  if [[ -n "$(git -C "$dir" ls-files -u)" ]]; then
    preflight_die "Есть неразрешённые конфликты слияния."
  fi

  check_forbidden_files "$dir"

  run_project_checks "$dir"

  # Повторная проверка: тесты/сборка могли создать служебные файлы.
  check_forbidden_files "$dir"

  preflight_info "Предпроверки пройдены для проекта: $slug"
}
