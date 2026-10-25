# Спецификация мульти-хост системы GitProject

Версия: 1.0
Дата: 2026-10-08
Статус: Утверждено

## 1. Архитектура

Система позволяет управлять репозиториями проектов на нескольких Git-хостах одновременно из единого рабочего пространства (`~/gitproject`).

### 1.1. Компоненты
- **Хаб**: `~/gitproject` — корневой репозиторий, содержащий конфигурацию, скрипты и веб-интерфейс.
- **Контейнер проектов**: `~/gitproject/projects/` — изолированная директория для клонов репозиториев. Не входит в коммит хаба.
- **Каталог хостов**: `config/hosts.json` — централизованная конфигурация поддерживаемых Git-хостов.
- **Credential Helper**: `bin/git-credential-multi.sh` — автоматическая аутентификация на разных хостах через токены из `.env`.

### 1.2. Схема переменных окружения (.env)
Для каждого хоста используется префикс (например, `GITFLIC`, `GITVERSE`):
- `<PREFIX>_HUB_USER` / `<PREFIX>_HUB_TOKEN` — доступ к репозиторию хаба.
- `<PREFIX>_PROJECT_USER` / `<PREFIX>_PROJECT_TOKEN` — доступ к проектам в `projects/`.
- Значение `placeholder` или пустая строка означает отсутствие токена.

## 2. Жизненный цикл операций

### 2.1. Клонирование (`git-project-clone.sh`)
- Интерактивный выбор хоста-источника (только со статусом `ready`).
- Проверка существования репозитория через `git ls-remote`.
- Автоматическое предложение добавить remote других настроенных хостов.

### 2.2. Синхронизация (`git-project-sync.sh`)
- **Одиночный режим**: синхронизация с `origin` (или указанным remote).
- **Мульти-режим (`--all`)**:
  1. Коммит локальных изменений (с защитой запрещённых путей).
  2. Для каждого remote:
     - `git fetch <remote>`
     - Анализ `merge-base` между локальной веткой и `<remote>/master`.
     - Если remote пуст: `git push <remote> master`.
     - Если локальная версия впереди: `git push <remote> master` (fast-forward).
     - Если истории расходятся: `git merge --allow-unrelated-histories -X ours <remote>/master`, затем `git push`.
     - Если локальная версия позади: операция блокируется (требует ручного разрешения или использования `git-project-migrate.sh`).

### 2.3. Миграция (`git-project-migrate.sh`)
- Показывает таблицу актуальности всех версий (локальная + remote) с подсветкой (🟢 свежий, 🟡 отстаёт, 🔴 расходится).
- Позволяет выбрать направление миграции (Откуда → Куда).
- Применяет `merge -X ours` или `reset --hard` (для локального каталога) без использования `force-push`.
- Требует подтверждения кодом `MIGRATE-<SRC>-TO-<TGT>` или простым `Д/Н`.

## 3. Безопасность

- **Секреты**: хранятся только в `.env` (права 600). Никогда не коммитятся и не выводятся в логи.
- **Credential Helper**: читает stdin целиком, избегает инъекций, молча выходит для неизвестных хостов.
- **Web-интерфейс**: `router.php` запрещает прямой доступ к `.env`, `bin/`, `data/`, `projects/`. API-эндпоинты изолированы в `public/api/`.
- **Basic Auth**: доступ к хабу из LAN защищён паролем `NETWORK_PASSWORD`.

## 4. Логирование

Все операции записываются в раздельные логи в директории `data/`:
- `data/workspace.log` — общие операции хаба.
- `data/workspace-error.log` — ошибки хаба.
- `data/<host>.log` (например, `gitflic.log`, `gitverse.log`) — операции, специфичные для конкретного хоста (клонирование, добавление remote, миграция).

Формат записи:
`[YYYY-MM-DD HH:MM:SS] <operation>: <slug> <details> (rc=<return_code>)`

## 5. Поддерживаемые хосты

| ID | Домен | Статус | Реестр ПО РФ | Особенности |
|---|---|---|---|---|
| `gitflic` | gitflic.ru | ready | да | URL: `/project/{owner}/{slug}.git`, регистр owner не важен, raw требует commit SHA. |
| `gitverse` | gitverse.ru | ready | да | URL: `/{owner}/{slug}.git`, регистр owner строгий (Sokol491), raw по имени ветки. |
| `sourcecraft` | git.sourcecraft.dev | ready | да | URL: `/{owner}/{slug}.git`, REST API на `api.sourcecraft.tech`. |
| `github` | github.com | integrating | нет | Стандартный GitHub API. |
| `gitlab` | gitlab.com | integrating | нет | Стандартный GitLab API. |
| `bitbucket` | bitbucket.org | integrating | нет | Стандартный Bitbucket API. |
| `gitea` | gitea.com | integrating | нет | Gitea-compatible API. |
| `codebase` | codebase.mos.ru | integrating | да | Московская платформа разработки. |
