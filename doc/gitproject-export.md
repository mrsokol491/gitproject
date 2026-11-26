# gitproject-export.sh

Zip-экспорт отслеживаемых файлов проекта.

## Вызов
    bin/gitproject-export.sh <slug>

## Поведение
- git archive --format=zip HEAD → data/exports/<slug>-<stamp>.zip.
- Только tracked-файлы: .git, vendor, untracked, .env исключены по определению.
- Лимит размера tracked-дерева: 100 МБ (иначе отказ).
- Ротация: хранятся последние 5 архивов проекта.
- stdout: путь архива; stderr: лог.

## Ограничения
- slug только [a-zA-Z0-9_-]; exit 1 при не-git проекте или превышении лимита.
