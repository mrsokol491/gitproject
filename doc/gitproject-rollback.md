# gitproject-rollback.sh

Откат хаба к backup-ветке.

## Вызовы
    bin/hub/gitproject-rollback.sh list
    bin/hub/gitproject-rollback.sh <branch> [--yes]

## Поведение
- list: таблица backup/pre-* (branch, sha, дата), сортировка по имени убыв.
- Откат только к backup/pre-*; иначе отказ.
- Текущее состояние сохраняется в backup/pre-rollback-<stamp>.
- Незакоммиченные правки → stash.
- read-tree -u --reset к цели + сохранение локальных новых файлов + commit --allow-empty.
- Без --yes требуется tty и подтверждение Д/Н.

## Ограничения
- exit 1 если ветка не найдена или не backup/pre-*.
