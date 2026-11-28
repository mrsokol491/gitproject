# gitproject-add-host.sh

Интерактивный wizard добавления Git-хоста и учётных данных.

## Вызовы
    bin/admin/gitproject-add-host.sh              # wizard
    bin/admin/gitproject-add-host.sh --host <id>  # сразу выбрать хост
    bin/admin/gitproject-add-host.sh --list       # список хостов

## Шаги wizard
1. Выбор хоста из config/hosts.json (ready/integrating/disabled, признак «настроен»).
2. Ввод user и token (маскирование ввода).
3. Тест подключения до сохранения.
4. Запись в .env без перезаписи существующих значений.
5. Обновление config/hosts.json (новый хост или смена статуса).
6. Scope: HUB, PROJECT или оба.

## Ограничения
- exit 2 при отсутствии config/hosts.json.
- Поддержка российских хостов (реестр ПО РФ) наравне с международными.
