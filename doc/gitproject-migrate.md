# gitproject-migrate.sh

Миграция проекта между Git-хостами с подсветкой актуальности версий.

## Вызовы
    bin/project/gitproject-migrate.sh <slug>                                # интерактивно
    bin/project/gitproject-migrate.sh <slug> --source <имя|local> --target <имя>

## Таблица версий
local + все remote: SHA, дата, состояние: 🟢 самый свежий | 🟡 отстаёт на N |
🔴 расходится | ⚪ не инициализирован.

## Правила (ТЗ-001 v2 §7)
- fast-forward (источник не отстаёт): прямой push.
- приёмник пуст: git push <target> <src>:refs/heads/<branch>.
- источник отстаёт: блокировка, предлагается обратное направление.
- расхождение историй: merge с приоритетом источника (-X ours в detached на источнике),
  без force-push; локальная ветка обновляется только fast-forward.

## Подтверждение
Код MIGRATE-<SOURCE>-TO-<TARGET> в верхнем регистре.

## Ограничения
- exit 2 если проект не git или ветка не определена.
