# git-project-remove.sh

Удаление проекта с выбором области: local / host / all.

## Вызовы
    bin/git-project-remove.sh <slug>             # интерактивный выбор области
    bin/git-project-remove.sh <slug> --local     # только локально
    bin/git-project-remove.sh <slug> --host <id> # с конкретного хоста
    bin/git-project-remove.sh <slug> --all       # со всех хостов

## Подтверждения
- local: DELETE-LOCAL
- host: DELETE-<HOST в верхнем регистре> (например DELETE-GITVERSE)
- all: DELETE-ALL

## Поведение и ограничения
- local: rm -rf каталога проекта; репозитории на хостах не затрагиваются.
- host: API-удаление репозитория НЕ реализовано — скрипт удаляет remote локально
  и instructит удалить репозиторий вручную через Web-UI хоста.
- Операции логируются (log_to_host).
