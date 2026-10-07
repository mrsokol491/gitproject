# GitProject Workspace Hub

Централизованный хаб для управления, проксирования и изоляции проектов Git.

Владелец: Александр (@sokol491).
Назначение: универсальная оболочка-хаб для работы с репозиториями на нескольких Git-хостах:
+ ✅ GitFlic (gitflic.ru) — российский Git-хостинг (реестр ПО РФ)
+ ✅ GitVerse (gitverse.ru) — российский Git-хостинг от Сбера (реестр ПО РФ)
+ ✅ SourceCraft (sourcecraft.dev) — платформа от Яндекса (Yandex B2B Tech)
+ ⚠️ GitHub (github.com) — международный Git-хостинг
+ ⚠️ GitLab (gitlab.com) — международный Git-хостинг
+ ⚠️ Bitbucket (bitbucket.org) — международный Git-хостинг от Atlassian
+ ⚠️ Gitea (gitea.com) — международный Git-хостинг
+ ⚠️ CodeBase (codebase.mos.ru) — московская платформа разработки (реестр ПО РФ)


    + ✅ - Интегрирован
    + ⚠️ - В процессе интеграции

- индекс проектов, прокси-роутер в docroot проектов, скрипты синхронизации/публикации/аудита, credential helper.

- Репозиторий: https://gitflic.ru/project/sokol491/gitproject
- Локальный путь: ~/gitproject

Правила разработки: STANDARD.md (приоритетный документ).
Эксплуатация: gitproject-workspace-guide.md (порты, systemd, git-доступ, changelog).

## Структура

    ~/gitproject/
    ├── router.php                  # роутер хаба: /<slug>/... -> projects/<slug>/<docroot>/...
    ├── index.php                   # главная: список проектов из projects/
    ├── .env                        # секреты (600): GIT_HOST, <HOST>_HUB_*, <HOST>_PROJECT_*, NETWORK_PASSWORD
    ├── .gitignore                  # хаба: /projects/, /data/*, .env, *.log, *.sqlite*, *.bak-*
    ├── README.md                   # этот файл
    ├── bin/
    │   ├── git-credential-multi.sh # мульти-хост credential helper (700)
    │   ├── git-project-sync.sh     # интерактивная синхронизация проектов projects/<slug>
    │   ├── git-project-update.sh   # публикация самого хаба
    │   ├── git-project-pull.sh     # обновление хаба и проектов из remote
    │   ├── git-project-clone.sh    # интерактивное клонирование с выбором хоста
    │   ├── git-project-remote.sh   # управление remote (add/remove/list + актуальность)
    │   ├── audit-project.sh        # аудит проекта перед паспортом
    │   ├── lib/common.sh           # общие функции мульти-хост системы
    ├── config/
    │   └── hosts.json              # каталог хостов (ready/integrating/disabled)
    ├── projects/                   # контейнер репозиториев (в репозиторий хаба не входит)
    ├── assets/                     # ассеты хаба (css, img)
    ├── data/                       # логи: workspace.log, workspace-error.log, <host>.log
    ├── STANDARD.md                 # стандарт разработки
    └── gitproject-workspace-guide.md  # руководство по эксплуатации

## Установка (новое устройство / новый пользователь)

Требования: PHP 8.1+, git 2.30+, curl, systemd (опционально).

    1. Клонирование хаба
       git clone https://gitflic.ru/project/sokol491/gitproject.git ~/gitproject
       cd ~/gitproject

    2. Создать .env из шаблона и вписать свои значения
       cp .env.example .env 2>/dev/null || true
       chmod 600 .env
       GIT_HOST=gitflic.ru             — хост по умолчанию
       GITFLIC_HUB_USER / GITFLIC_HUB_TOKEN       — доступ к репозиторию хаба
       GITFLIC_PROJECT_USER / GITFLIC_PROJECT_TOKEN — доступ к проектам
       NETWORK_PASSWORD=<пароль>        — Basic Auth для доступа к хабу из LAN

    3. Права на скрипты и подключение credential helper
       chmod 700 bin/git-credential-multi.sh bin/git-project-*.sh
       git config --global credential.helper "$HOME/gitproject/bin/git-credential-multi.sh"
       git config --global user.name "Ваше Имя"
       git config --global user.email "ваш_email@example.com"

    4. Проверка helper (токен маскируется)
       printf 'protocol=https\nhost=gitflic.ru\npath=sokol491/gitproject.git\n' \
         | bin/git-credential-multi.sh get | sed -E 's/^(password=).*/\1[скрыто]/'

    5. Запуск хаба
       php -S 0.0.0.0:8000 -t ~/gitproject ~/gitproject/router.php
       или через systemd:
       systemctl --user enable --now gitproject-workspace.service
       sudo loginctl enable-linger $USER

    6. Клонирование проектов в контейнер (владелец — любой)
       git clone https://gitflic.ru/project/<владелец>/<slug>.git ~/gitproject/projects/<slug>
       ~/gitproject/bin/git-project-sync.sh fix <slug>

Обновление окружения хаба — только из репозитория sokol491 на GitFlic (фиксировано):
    ~/gitproject/bin/git-project-pull.sh

## Порты и URL

- 8000 — хаб (systemd: gitproject-workspace.service)
- http://127.0.0.1:8000/ — хаб
    http://127.0.0.1:8000/< slug >/ — проект

## Команды управления

Все скрипты в bin/ требуют права 700. Токены подставляются из .env через
credential helper; в выводе команд маскируются ([скрыто]).

### Интерактивная синхронизация проектов (bin/git-project-sync.sh)

Запуск без аргументов открывает меню: нумерованный список проектов с цветным
состоянием; выбор — номера через запятую (1,3), all — все, q — выход.

    ~/gitproject/bin/git-project-sync.sh                 # интерактивное меню
    ~/gitproject/bin/git-project-sync.sh <slug>          # один проект без меню
    ~/gitproject/bin/git-project-sync.sh <slug> -m "сообщение"
    ~/gitproject/bin/git-project-sync.sh status          # таблица состояний
    ~/gitproject/bin/git-project-sync.sh fix <slug>      # init/origin/контроль

### Публикация хаба (bin/git-project-update.sh)

    ~/gitproject/bin/git-project-update.sh -m "Тест"     # публикация с сообщением
    ~/gitproject/bin/git-project-update.sh               # авто-сообщение с датой
    ~/gitproject/bin/git-project-update.sh status        # состояние хаба
    ~/gitproject/bin/git-project-update.sh fix           # подготовка к первой публикации

### Мульти-хост система

    ~/gitproject/bin/git-project-clone.sh                # мастер клонирования с выбором хоста
    ~/gitproject/bin/git-project-remote.sh <slug> --list # remote + актуальность
    ~/gitproject/bin/git-project-remote.sh <slug> --add <domain>
    ~/gitproject/bin/git-project-migrate.sh <slug>           # миграция между хостами с подсветкой актуальности

### Credential helper (bin/git-credential-multi.sh)

Вызывается только git, вручную не нужен. Проверка БЕЗ вывода токена:

    printf 'protocol=https\nhost=gitflic.ru\n' \
      | ~/gitproject/bin/git-credential-multi.sh get \
      | sed -E 's/^(password=).*/\1[скрыто]/'

### Сервис хаба (systemd)

    systemctl --user status gitproject-workspace.service --no-pager
    systemctl --user restart gitproject-workspace.service
    systemctl --user enable gitproject-workspace.service
    sudo loginctl enable-linger $USER

### Диагностика

    ss -tlnp | grep :8000
    curl -sI http://127.0.0.1:8000/ | head -n 1
    tail -n 20 ~/gitproject/data/workspace.log
    tail -n 20 ~/gitproject/data/workspace-error.log

## Безопасность

- Секреты только в .env (600); маскированный просмотр:
  sed -E 's/=(.+)$/=[скрыто]/' ~/gitproject/.env
- Токены: ограниченные права, срок 1-2 дня, после работ отзываются.
- Из LAN хаб закрыт HTTP Basic Auth; с 127.0.0.1 — без пароля.
- Роутер: forbidden-список (.env, bin/, data/, projects/, *.md), защита path
  traversal (realpath + проверка префикса docroot).
- Особенность GitFlic: git-транспорт отвечает HTTP 500 на несуществующий репозиторий.
- Создание репозиториев через API не подтверждено (probe: 404/302) — через Web-UI.

## Логи

data/workspace.log, data/workspace-error.log, data/<host>.log — в репозиторий не попадают.
Test credential helper
