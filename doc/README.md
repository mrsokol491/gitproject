# Документация bin-скриптов GitProject Workspace Hub

Оглавление инструкций (по одному файлу на скрипт):

| Скрипт | Назначение |
| --- | --- |
| [audit-php-security.sh](audit-php-security.md) | аудит PHP на CVE-2026-109618/9 и конфигурацию php.ini |
| [audit-project.sh](audit-project.md) | полный аудиторский отчёт по проекту в файл |
| [git-credential-multi.sh](git-credential-multi.md) | credential helper нескольких Git-хостов |
| [git-project-add-host.sh](git-project-add-host.md) | wizard добавления Git-хоста и токенов |
| [git-project-clone.sh](git-project-clone.md) | интерактивное клонирование с выбором хоста |
| [git-project-migrate.sh](git-project-migrate.md) | миграция проекта между хостами |
| [git-project-pull.sh](git-project-pull.md) | безопасное обновление хаба и проектов (--ff-only) |
| [git-project-remote.sh](git-project-remote.md) | управление remote проекта + актуальность |
| [git-project-remove.sh](git-project-remove.md) | удаление проекта local/host/all |
| [git-project-sync-precheck.sh](git-project-sync-precheck.md) | библиотека предпроверок перед sync |
| [git-project-sync.sh](git-project-sync.md) | синхронизация проектов: push/pull/all/status/fix/probe |
| [gitproject-export.sh](gitproject-export.md) | zip-экспорт tracked-файлов проекта |
| [gitproject-fetch.sh](gitproject-fetch.md) | fetch хаба из GitVerse с троттлингом 24ч |
| [gitproject-force-update.sh](gitproject-force-update.md) | принудительное обновление хаба (reset --hard) |
| [gitproject-rollback.sh](gitproject-rollback.md) | откат хаба к backup-ветке |
| [gitproject-sync-hub.sh](gitproject-sync-hub.md) | мульти-push хаба на все remote + теги |
| [gitproject-update.sh](gitproject-update.md) | обновление хаба: stable / edge |

Общие соглашения:
- Интерактив и логи — в stderr; машинные значения (пути, sha) — в stdout.
- Корень репозитория вычисляется динамически (`HUB_ROOT` из `bin/lib/common.sh`),
  жёстких путей `/home/user/gitproject` нет.
- `set -u`: все переменные окружения определены до использования.
- Коды подтверждения: `DELETE-LOCAL`, `DELETE-<HOST>`, `DELETE-ALL`,
  `MIGRATE-<SOURCE>-TO-<TARGET>`, `EDGE` (в UI).
- Запрещённые к публикации пути: `.env*`, `data/`, `storage/`, `vendor/`,
  `node_modules/`, `*.log`, `*.sqlite*`, кеши тестов; исключения — `.gitkeep`, `.env.example`.
- Запуск через sudo запрещён для скриптов обновления.
