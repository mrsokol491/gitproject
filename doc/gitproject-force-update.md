# gitproject-force-update.sh

Принудительное обновление хаба из GitVerse: сервер всегда прав (fetch + reset --hard).

## Вызов
    bin/gitproject-force-update.sh [ветка] [--yes] [--clean] [-h|--help]

## Флаги
- ветка: ветка GitVerse (по умолчанию текущая; detached → master).
- --yes: без подтверждения Д/Н; GIT_TERMINAL_PROMPT=0.
- --clean: удалить untracked перед переключением, КРОМЕ .env, data/, storage/,
  vendor/, projects/ и git-ignored.
- -h/--help: справка.

## Bаoхранность
- Локальные коммиты → backup-ветка backup/pre-force-<timestamp>.
- Незакоммиченные правки → git stash.
- Секреты и данные не трогаются.
- Лог событий: data/gitverse.ru.log.

## Ограничения
- ЗАПРЕЩЕНО через sudo/root.
- Отказ при незавершённом merge/rebase/cherry-pick.
