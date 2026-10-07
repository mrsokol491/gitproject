# GitProject Workspace Hub

Владелец: Александр (@sokol491).
Назначение: универсальная оболочка-хаб для работы с репозиториями на нескольких
Git-хостах (GitFlic, GitVerse и др.): индекс проектов, прокси-роутер
в docroot проектов, скрипты синхронизации/публикации/аудита, credential helper.

**Репозиторий:** https://gitflic.ru/project/sokol491/gitproject  
**Локальный путь:** `~/gitproject`  
**Прежнее имя:** GitFlic Dev Workspace (переименован 2026-10-07)

Правила разработки: STANDARD.md (приоритетный документ).
Эксплуатация: gitflic-workspace-guide.md (порты, systemd, git-доступ, changelog).

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
    │   ├── git-project-clone.sh    # интерактивное клонирование с выбором хоста
    │   ├── git-project-remote.sh   # управление remote (add/remove/list + актуальность)
    │   ├── lib/common.sh           # общие функции мульти-хост системы
    │   └── gitflic-*.sh            -> симлинки для обратной совместимости
    ├── config/
    │   └── hosts.json              # каталог хостов (ready/integrating/disabled)
    ├── projects/                   # контейнер репозиториев (в репозиторий хаба не входит)
    ├── assets/                     # ассеты хаба (css, img)
    ├── data/                       # логи: workspace.log, workspace-error.log, <host>.log
    ├── STANDARD.md                 # стандарт разработки
    └── gitflic-workspace-guide.md  # руководство по эксплуатации

## Установка (новое устройство / новый пользователь)

Требования: PHP 8.1+, git 2.30+, curl, systemd (опционально).

    # 1. Клонирование хаба
    git clone https://gitflic.ru/project/sokol491/gitproject.git ~/gitproject
    cd ~/gitproject

    # 2. Создать .env из шаблона и вписать свои значения
    cp .env.example .env 2>/dev/null || true
    chmod 600 .env
    #    GIT_HOST=gitflic.ru             — хост по умолчанию
    #    GITFLIC_HUB_USER / GITFLIC_HUB_TOKEN       — доступ к репозиторию хаба
    #    GITFLIC_PROJECT_USER / GITFLIC_PROJECT_TOKEN — доступ к проектам
    #    NETWORK_PASSWORD=<пароль>        — Basic Auth для доступа к хабу из LAN

    # 3. Права на скрипты и подключение credential helper
    chmod 700 bin/git-credential-multi.sh bin/git-project-*.sh
    git config --global credential.helper "$HOME/gitproject/bin/git-credential-multi.sh"
    git config --global user.name "Ваше Имя"
    git config --global user.email "ваш_email@example.com"

    # 4. Проверка helper (токен маскируется)
    printf 'protocol=https\nhost=gitflic.ru\npath=sokol491/gitproject.git\n' | bin/git-credential-multi.sh get | sed -E 's/^(password=).*/\1[скрыто]/'

    # 5. Запуск хаба
    php -S 0.0.0.0:8000 -t ~/gitproject ~/gitproject/router.php
    # или через systemd:
    systemctl --user enable --now gitproject-workspace.service
    sudo loginctl enable-linger $USER

    # 6. Клонирование проектов в контейнер (владелец — любой)
    git clone https://gitflic.ru/project/<владелец>/<slug>.git ~/gitproject/projects/<slug>
    ~/gitproject/bin/git-project-sync.sh fix <slug>

Обновление окружения хаба — только из репозитория sokol491 на GitFlic (фиксировано):
    ~/gitproject/bin/git-project-pull.sh

## Порты и URL

- 8000 — хаб (systemd: gitproject-workspace.service; ручной запуск:
  php -S 0.0.0.0:8000 -t ~/gitproject ~/gitproject/router.php)
- 8080 — занят сторонним проектом: не трогать и не занимать
- http://127.0.0.1:8000/ — хаб; http://127.0.0.1:8000/<slug>/ — проект

## Контейнер projects/ и состояние проектов

| slug | тип | docroot для хаба | публикация |
|---|---|---|---|
| archive | PHP | legacy Project/ (до миграции) | master |
| searchengine | Java/Maven | не обслуживается (нет index в docroot) | master |
| pdf | Node.js (pdf.js) | не обслуживается (нет index в docroot) | master |
| visual-novel-engine | PHP + JS | public/ | master |
| virtual-cloud | PHP | public/ | master |

Правило ветвления: только master, локально и на хостах.
Docroot проекта — только public/ (STANDARD.md §3, §9); legacy-каталоги допускаются
временно с записью в data/workspace-error.log.

## Команды управления

Все скрипты в bin/ требуют права 700. Токены подставляются из .env через
credential helper; в выводе команд маскируются ([скрыто]).
Старые имена (gitflic-sync.sh и др.) работают через симлинки.

### 1. Интерактивная синхронизация проектов (bin/git-project-sync.sh)

Запуск без аргументов открывает меню: нумерованный список проектов с цветным
состоянием; выбор — номера через запятую (1,3), all — все, q — выход.

    ~/gitproject/bin/git-project-sync.sh                 # интерактивное меню
    ~/gitproject/bin/git-project-sync.sh <slug>          # один проект без меню
    ~/gitproject/bin/git-project-sync.sh <slug> -m "сообщение"
    ~/gitproject/bin/git-project-sync.sh status          # таблица состояний
    ~/gitproject/bin/git-project-sync.sh fix <slug>      # init/origin/контроль
    ~/gitproject/bin/git-project-sync.sh probe           # матрица эндпоинтов API

### 2. Публикация хаба (bin/git-project-update.sh)

    ~/gitproject/bin/git-project-update.sh -m "Тест"     # публикация с сообщением
    ~/gitproject/bin/git-project-update.sh               # авто-сообщение с датой
    ~/gitproject/bin/git-project-update.sh status        # состояние хаба
    ~/gitproject/bin/git-project-update.sh fix           # подготовка к первой публикации
    git -C ~/gitproject ls-remote origin refs/heads/master && git -C ~/gitproject rev-parse master

### 3. Мульти-хост система

    ~/gitproject/bin/git-project-clone.sh                # мастер клонирования с выбором хоста
    ~/gitproject/bin/git-project-remote.sh <slug> --list # remote + актуальность
    ~/gitproject/bin/git-project-remote.sh <slug> --add <domain>

### 4. Credential helper (bin/git-credential-multi.sh)

Вызывается только git, вручную не нужен. Проверка БЕЗ вывода токена:

    printf 'protocol=https\nhost=gitflic.ru\n' \
      | ~/gitproject/bin/git-credential-multi.sh get \
      | sed -E 's/^(password=).*/\1[скрыто]/'

### 5. Аудит проекта (bin/audit-project.sh)

    ~/gitproject/bin/audit-project.sh <slug>

### 6. Сервис хаба (systemd)

    systemctl --user status gitproject-workspace.service --no-pager
    systemctl --user restart gitproject-workspace.service
    systemctl --user enable gitproject-workspace.service
    sudo loginctl enable-linger $USER

### 7. Диагностика

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

## Добавление проекта

    # 1. Клонирование в контейнер (владелец — любой пользователь хоста)
    git clone https://gitflic.ru/project/<владелец>/<slug>.git ~/gitproject/projects/<slug>

    # 2. Подготовка (init, если надо; origin, контроль)
    ~/gitproject/bin/git-project-sync.sh fix <slug>

    # 3. Публикация ветки
    ~/gitproject/bin/git-project-sync.sh <slug> -m "sync: первая публикация <slug>"

    # 4. Проверка доступности в хабе
    curl -sI http://127.0.0.1:8000/<slug>/ | head -n 1

## Логи

data/workspace.log, data/workspace-error.log — в репозиторий не попадают.
