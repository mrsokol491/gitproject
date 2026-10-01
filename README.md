# GitFlic Dev Workspace (gitflicProject)

Владелец: Александр (@sokol491).
Назначение: оболочка-хаб для работы с репозиториями GitFlic: индекс проектов,
прокси-роутер в docroot проектов, скрипты синхронизации/публикации и аудита,
credential helper.
Правила разработки: STANDARD.md (приоритетный документ).
Эксплуатация: gitflic-workspace-guide.md (порты, systemd, git-доступ, changelog).

## Структура

    ~/gitflicProject/
    ├── router.php                 # роутер хаба: /<slug>/... -> projects/<slug>/<docroot>/...
    ├── index.php                  # главная: список проектов из projects/
    ├── .env                       # секреты (600): GIT_HOST, GITFLIC_USER, GITFLIC_TOKEN, NETWORK_PASSWORD
    ├── bin/
    │   ├── git-credential-gitflic.sh  # credential helper, токен из .env (700)
    │   ├── gitflic-sync.sh            # интерактивная синхронизация проектов projects/<slug>
    │   ├── gitflic-sync-hub.sh        # синхронизация и публикация самого хаба
    │   └── audit-project.sh           # аудит проекта перед паспортом
    ├── projects/                  # контейнер репозиториев (в репозиторий хаба не входит)
    ├── assets/                    # ассеты хаба (css, img)
    ├── data/                      # логи хаба (в репозиторий не входят)
    ├── STANDARD.md                # стандарт разработки
    ├── gitflic-workspace-guide.md # руководство по эксплуатации
    └── README.md                  # этот файл

## Установка (новое устройство / новый пользователь GitFlic)

Требования: PHP 8.1+, git 2.30+, curl, systemd (опционально).

    # 1. Клонирование хаба (slug хаба — gitflicproject, нижний регистр!)
    git clone https://gitflic.ru/project/sokol491/gitflicproject.git ~/gitflicProject
    cd ~/gitflicProject

    # 2. Создать .env из шаблона и вписать свои значения
    cp .env.example .env 2>/dev/null || true
    chmod 600 .env
    #    GIT_HOST=gitflic.ru            — хост Git-системы
    #    GITFLIC_USER=<ваш логин>       — владелец репозиториев (любой пользователь хоста)
    #    GITFLIC_TOKEN=<ваш токен>      — настройки GitFlic -> API-токены, права read/write project
    #    NETWORK_PASSWORD=<пароль>      — Basic Auth для доступа к хабу из LAN

    # 3. Права на скрипты и подключение credential helper
    chmod 700 bin/*.sh
    git config --global credential.https://gitflic.ru.helper "$HOME/gitflicProject/bin/git-credential-gitflic.sh"
    git config --global user.name "Ваше Имя"
    git config --global user.email "ваш_email@example.com"

    # 4. Проверка helper (токен маскируется)
    printf 'protocol=https\nhost=gitflic.ru\n' | bin/git-credential-gitflic.sh get | sed -E 's/^(password=).*/\1[скрыто]/'

    # 5. Запуск хаба
    php -S 0.0.0.0:8000 -t ~/gitflicProject ~/gitflicProject/router.php
    # или через systemd:
    systemctl --user enable --now gitflic-workspace.service
    sudo loginctl enable-linger $USER

    # 6. Клонирование проектов в контейнер (владелец — любой)
    git clone https://gitflic.ru/project/<владелец>/<slug>.git ~/gitflicProject/projects/<slug>
    ~/gitflicProject/bin/gitflic-sync.sh fix <slug>

Обновление окружения хаба — только из репозитория sokol491:
    ~/gitflicProject/bin/gitflic-update.sh

## Порты и URL

- 8000 — хаб (systemd: gitflic-workspace.service; ручной запуск:
  php -S 0.0.0.0:8000 -t ~/gitflicProject ~/gitflicProject/router.php)
- 8080 — занят сторонним проектом: не трогать и не занимать
- http://127.0.0.1:8000/ — хаб; http://127.0.0.1:8000/<slug>/ — проект

## Контейнер projects/ и состояние проектов

| slug | тип | docroot для хаба | публикация |
|---|---|---|---|
| archive | PHP | legacy Project/ (до миграции) | master |
| searchengine | Java/Maven | не обслуживается (нет index в docroot) | master |
| pdf | Node.js (pdf.js) | не обслуживается (нет index в docroot) | master |
| visual-novel-engine | PHP + JS | public/ | master |

Правило ветвления: только master, локально и на GitFlic.
Docroot проекта — только public/ (STANDARD.md §3, §9); legacy-каталоги допускаются
временно с записью в data/workspace-error.log.

## Команды управления

Все скрипты в bin/ требуют права 700. Токен подставляется из .env через
credential helper; в выводе команд маскируется ([скрыто]).

### 1. Интерактивная синхронизация проектов (bin/gitflic-sync.sh)

Запуск без аргументов открывает меню: нумерованный список проектов с цветным
состоянием; выбор — номера через запятую (1,3), all — все, q — выход.

    ~/gitflicProject/bin/gitflic-sync.sh                 # интерактивное меню
    ~/gitflicProject/bin/gitflic-sync.sh <slug>          # один проект без меню
    ~/gitflicProject/bin/gitflic-sync.sh <slug> -m "сообщение"
    ~/gitflicProject/bin/gitflic-sync.sh status          # таблица состояний
    ~/gitflicProject/bin/gitflic-sync.sh fix <slug>      # init/origin/контроль
    ~/gitflicProject/bin/gitflic-sync.sh probe           # матрица эндпоинтов API

### 2. Публикация хаба (bin/gitflic-sync-hub.sh)

    ~/gitflicProject/bin/gitflic-sync-hub.sh -m "Тест"   # публикация с сообщением
    ~/gitflicProject/bin/gitflic-sync-hub.sh             # авто-сообщение с датой
    ~/gitflicProject/bin/gitflic-sync-hub.sh status      # состояние хаба
    ~/gitflicProject/bin/gitflic-sync-hub.sh fix         # подготовка к первой публикации
    git -C ~/gitflicProject ls-remote origin refs/heads/master && git -C ~/gitflicProject rev-parse master

Факт 2026-09-29: фактический slug репозитория хаба на GitFlic — gitflicproject
(нижний регистр); git-транспорт регистрозависим, Web-UI открывает оба написания.

### 3. Credential helper (bin/git-credential-gitflic.sh)

Вызывается только git, вручную не нужен. Проверка БЕЗ вывода токена:

    printf 'protocol=https\nhost=gitflic.ru\n' \
      | ~/gitflicProject/bin/git-credential-gitflic.sh get \
      | sed -E 's/^(password=).*/\1[скрыто]/'

### 4. Аудит проекта (bin/audit-project.sh)

    ~/gitflicProject/bin/audit-project.sh <slug>

### 5. Сервис хаба (systemd)

    systemctl --user status gitflic-workspace.service --no-pager
    systemctl --user restart gitflic-workspace.service
    systemctl --user enable gitflic-workspace.service
    sudo loginctl enable-linger $USER

### 6. Диагностика

    ss -tlnp | grep :8000
    curl -sI http://127.0.0.1:8000/ | head -n 1
    tail -n 20 ~/gitflicProject/data/workspace.log
    tail -n 20 ~/gitflicProject/data/workspace-error.log

## Безопасность

- Секреты только в .env (600); маскированный просмотр:
  sed -E 's/=(.+)$/=[скрыто]/' ~/gitflicProject/.env
- Токен GitFlic: ограниченные права, срок 1-2 дня, после работ отзывается.
- Из LAN хаб закрыт HTTP Basic Auth; с 127.0.0.1 — без пароля.
- Роутер: forbidden-список (.env, bin/, data/, projects/, *.md), защита path
  traversal (realpath + проверка префикса docroot).
- Особенность GitFlic: git-транспорт отвечает HTTP 500 на несуществующий репозиторий.
- Создание репозиториев через API не подтверждено (probe: 404/302) — через Web-UI.

## Добавление проекта

    # 1. Клонирование в контейнер (владелец — любой пользователь хоста)
    git clone https://gitflic.ru/project/<владелец>/<slug>.git ~/gitflicProject/projects/<slug>

    # 2. Подготовка (init, если надо; origin, контроль)
    ~/gitflicProject/bin/gitflic-sync.sh fix <slug>

    # 3. Публикация ветки
    ~/gitflicProject/bin/gitflic-sync.sh <slug> -m "sync: первая публикация <slug>"

    # 4. Проверка доступности в хабе
    curl -sI http://127.0.0.1:8000/<slug>/ | head -n 1

## Логи

data/workspace.log, data/workspace-error.log — в репозиторий не попадают.
