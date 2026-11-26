# gitproject-update.sh

Обновление хаба из GitVerse: stable (релиз-тег) или edge (master).

## Вызов
    bin/gitproject-update.sh [stable|edge] [--yes]

## Поведение
- Identity-guard: repo-local user.name/email, если глобальный не настроен.
- Remote gitverse создаётся/выравнивается; origin на GitVerse используется как есть.
- fetch --tags --force; stable = старший semver-тег, предок edge.
- Обновление: merge -X theirs. Даунгрейд НЕ блокируется: общие файлы к цели,
  локальные новые сохраняются, история в backup, правки в stash.
- Идемпотентность: общие файлы совпадают → только метка data/version.txt.
- Backup: backup/pre-update-<stamp>; без --yes требуется tty и Д/Н.
- После обновления — refresh записи хаба в маркетплейсе (если CLI существует).

## Ограничения
- exit 1 при незавершённом merge/rebase, отсутствии тегов,失败的 fetch/merge.
- Без force-push.
