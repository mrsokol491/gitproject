# git-project-sync.sh

Синхронизация проектов projects/<slug>.

## Вызовы
    bin/git-project-sync.sh                        # интерактивное меню
    bin/git-project-sync.sh <slug>                 # push на origin (или первый remote)
    bin/git-project-sync.sh <slug> --all           # мульти-push на все remote
    bin/git-project-sync.sh <slug> --remote <r>    # push на конкретный remote
    bin/git-project-sync.sh <slug> --push          # push на первый remote
    bin/git-project-sync.sh <slug> --pull          # pull --ff-only с первого remote
    bin/git-project-sync.sh <slug> -m "сообщение"  # сообщение коммита
    bin/git-project-sync.sh status [host]          # таблица состояний
    bin/git-project-sync.sh fix <slug>             # подготовка проекта (remote+fetch+ветка)
    bin/git-project-sync.sh probe                  # матрица хостов из hosts.json

## Поведение --all
1. Автодобавление missing remote из .env (<PREFIX>_PROJECT_TOKEN, не placeholder).
2. Коммит локальных изменений с guard запрещённых путей (exit 5 при находках).
3. Push на каждый remote; сводка успех/ошибки.

## Ограничения
- exit 1 если проект не найден; exit 2 при неверных аргументах.
- Таблица status использует цветовые переменные из common.sh (RESET и др.).
