# gitproject-remote.sh

Управление remote проекта с подсветкой актуальности версий.

## Вызовы
    bin/project/gitproject-remote.sh <slug> --list
    bin/project/gitproject-remote.sh <slug> --add <domain>
    bin/project/gitproject-remote.sh <slug> --add <domain> --owner <owner>
    bin/project/gitproject-remote.sh <slug> --remove <remote>

## --list
Таблица: СЕРВЕР | URL | SHA | ДАТА | СТАТУС (✅ актуален | ⚠ отстаёт на N | ⚪ не инициализирован).
Самый свежий коммит среди remote показывается отдельно.

## --add
- Требует статус хоста ready в hosts.json (integrating → отказ с подсказкой).
- owner берётся из .env (<PREFIX>_PROJECT_USER) или запрашивается.
- Проверка существования репозитория на хосте до добавления.
- После добавления — fetch; при пустом репозитории WARN.

## --remove
Удаление remote с подтверждением ввода.

## Ограничения
- exit 2 если проект не найден / ветка не определена / remote уже существует.
