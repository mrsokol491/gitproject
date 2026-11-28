# gitproject-sync-hub.sh

Мульти-push самого хаба на все настроенные remote + публикация тегов.

## Вызовы
    bin/hub/gitproject-sync-hub.sh
    bin/hub/gitproject-sync-hub.sh --tag <version> "<message>"

## Поведение
1. Автодобавление remote из .env (<PREFIX>_HUB_TOKEN, не placeholder) по hosts.json.
2. Коммит локальных изменений с guard запрещённых путей (exit 5).
3. Push ветки на все remote; сводка.
4. --tag: аннотированный тег создаётся (если ещё нет) и пушится на все remote;
   при ошибках push ветки тег не публикуется.
5. Освежение записи хаба в маркетплейсе, если bin/marketplace.php существует.

## Ограничения
- exit 1 если нет remote; exit 2 если не git-репозиторий/ветка не определена.
- Тег не создаётся повторно: существующий только до-пушивается.
