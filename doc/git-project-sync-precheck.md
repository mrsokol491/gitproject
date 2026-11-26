# git-project-sync-precheck.sh

Библиотека общих проверок перед sync проекта. Не самостоятельный sync-скрипт:
подключается обёртками (source).

## Окружение
- GIT_PROJECT_WORKSPACE (по умолчанию HUB_ROOT) — корень workspace.
- GIT_PROJECT_PROJECTS = $GIT_PROJECT_WORKSPACE/projects.
- Требует исполняемый bin/git-project-sync.sh, иначе exit 2.

## Функции
- preflight_info / preflight_die — лог и аварийный выход.
- is_forbidden_path <path> — запрещённые пути: .env*, *.log, *.sqlite*,
  .phpunit/.phpstan кеши, data/, storage/, vendor/, node_modules/;
  исключения: data/.gitkeep, storage/.gitkeep, .env.example.
- check_forbidden_files <dir> — проверка индекса и untracked; при находках exit 1.
- has_composer_script <name> — наличие composer-скрипта.
- check_markdown_quality <dir> — проверка .md через anti-ai-slop (опционально;
  пропускается если skill или python3 отсутствуют); CHANGELOG/LICENSE исключены.

## Ограничения
- set -Eeuo pipefail: ошибки наследуются вызывающим скриптом.
