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
    ├── .env                       # секреты (600): NETWORK_PASSWORD, GITFLIC_TOKEN, GITFLIC_USER
    ├── bin/
    │   ├── git-credential-gitflic.sh  # credential helper, токен из .env (700)
    │   ├── gitflic-sync.sh            # синхронизация проектов projects/<slug>
    │   ├── gitflic-sync-hub.sh        # синхронизация и публикация самого хаба
    │   └── audit-project.sh           # аудит проекта перед паспортом
    ├── projects/                  # контейнер репозиториев (в репозиторий хаба не входит)
    ├── assets/                    # ассеты хаба (css, img)
    ├── data/                      # логи хаба (в репозиторий не входят)
    ├── STANDARD.md                # стандарт разработки
    ├── gitflic-workspace-guide.md # руководство по эксплуатации
    └── README.md                  # этот файл

## Порты и URL

- 8000 — хаб (systemd: gitflic-workspace.service; ручной запуск:
  php -S 0.0.0.0:8000 -t ~/gitflicProject ~/gitflicProject/router.php)
- http://127.0.0.1:8000/ — хаб; http://127.0.0.1:8000/<slug>/ — проект

## Контейнер projects/ и состояние проектов

| slug | тип | docroot для хаба | публикация |
|---|---|---|---|
| archive | PHP | legacy Project/ (до миграции) | ветка = upstream |
| searchengine | Java/Maven | не обслуживается (нет index в docroot) | ветка = upstream |
| pdf | Node.js (pdf.js) | не обслуживается (нет index в docroot) | ветка = upstream |
| visual-novel-engine | PHP + JS | public/ | локально qwen -> GitFlic master |

Docroot проекта — только public/ (STANDARD.md §3, §9); legacy-каталоги допускаются
временно с записью в data/workspace-error.log.

## Команды управления

Все скрипты в bin/ требуют права 700. Токен подставляется из .env через
credential helper; в выводе команд маскируется ([скрыто]).

### 1. Публикация хаба (bin/gitflic-sync-hub.sh)

Полный цикл для репозитория самого хаба: fetch → сверка → коммит локальных
изменений (защита запрещённых путей: .env, data/, projects/) → merge upstream
с приоритетом локального → push → сверка хэшей.

    # 1.1 Публикация изменений хаба с сообщением (пример из задачи)
    ~/gitflicProject/bin/gitflic-sync-hub.sh -m "Тест"

    # 1.2 Публикация с осмысленным сообщением
    ~/gitflicProject/bin/gitflic-sync-hub.sh -m "docs: обновление README с примерами команд"

    # 1.3 Публикация без сообщения (авто-сообщение с датой)
    ~/gitflicProject/bin/gitflic-sync-hub.sh

    # 1.4 Состояние хаба (git? ветка/upstream? грязь? репозиторий на GitFlic?)
    ~/gitflicProject/bin/gitflic-sync-hub.sh status

    # 1.5 Подготовка к первой публикации (init, origin, .gitignore, data/.gitkeep)
    ~/gitflicProject/bin/gitflic-sync-hub.sh fix

    # 1.6 Сверка хэшей после публикации (значения должны совпасть)
    git -C ~/gitflicProject ls-remote origin refs/heads/master && git -C ~/gitflicProject rev-parse master

Факт 2026-09-29: фактический slug репозитория хаба на GitFlic — gitflicproject
(нижний регистр); git-транспорт регистрозависим, Web-UI открывает оба написания.

### 2. Синхронизация проектов контейнера (bin/gitflic-sync.sh)

Полный цикл для репозиториев в projects/<slug>: тот же принцип, что и у хаба.
Цель публикации = upstream локальной ветки; без upstream — одноимённая ветка с -u.

    # 2.1 Публикация проекта с сообщением
    ~/gitflicProject/bin/gitflic-sync.sh visual-novel-engine -m "refactor: доводка переносов"

    # 2.2 Публикация проекта без сообщения
    ~/gitflicProject/bin/gitflic-sync.sh archive

    # 2.3 Таблица всех проектов: локальный git? репозиторий? ветка? грязь?
    ~/gitflicProject/bin/gitflic-sync.sh status

    # 2.4 Статус одного проекта
    ~/gitflicProject/bin/gitflic-sync.sh status visual-novel-engine

    # 2.5 Подготовка проекта (init/origin/контроль, без публикации)
    ~/gitflicProject/bin/gitflic-sync.sh fix visual-novel-engine

    # 2.6 Матрица эндпоинтов API GitFlic (сверка probe)
    ~/gitflicProject/bin/gitflic-sync.sh probe

### 3. Credential helper (bin/git-credential-gitflic.sh)

Вызывается только git, вручную не нужен. Проверка БЕЗ вывода токена:

    printf 'protocol=https\nhost=gitflic.ru\n' \
      | ~/gitflicProject/bin/git-credential-gitflic.sh get \
      | sed -E 's/^(password=).*/\1[скрыто]/'

### 4. Аудит проекта (bin/audit-project.sh)

Заполняет PROJECT PASSPORT (STANDARD.md §12); секреты не присылать:

    ~/gitflicProject/bin/audit-project.sh visual-novel-engine

### 5. Сервис хаба (systemd)

    # Статус и перезапуск после правок router.php / index.php / bin/
    systemctl --user status gitflic-workspace.service --no-pager
    systemctl --user restart gitflic-workspace.service

    # Автозапуск при загрузке системы (до входа в сеанс)
    systemctl --user enable gitflic-workspace.service
    sudo loginctl enable-linger $USER

    # Ручной запуск, если systemd недоступен
    php -S 0.0.0.0:8000 -t ~/gitflicProject ~/gitflicProject/router.php

### 6. Диагностика

    # Порт слушает? HTTP-код главной (ожид. 200)?
    ss -tlnp | grep :8000
    curl -sI http://127.0.0.1:8000/ | head -n 1

    # Логи хаба
    tail -n 20 ~/gitflicProject/data/workspace.log
    tail -n 20 ~/gitflicProject/data/workspace-error.log

    # Связка публикации хаба и доступность репозитория (регистр slug важен!)
    git -C ~/gitflicProject ls-remote origin refs/heads/master
    git -C ~/gitflicProject rev-parse master
    git ls-remote https://gitflic.ru/project/sokol491/gitflicproject.git | head -3

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

    # 1. Клонирование в контейнер
    git clone https://gitflic.ru/project/sokol491/<slug>.git ~/gitflicProject/projects/<slug>

    # 2. Подготовка (init, если надо; origin, контроль)
    ~/gitflicProject/bin/gitflic-sync.sh fix <slug>

    # 3. Публикация ветки
    ~/gitflicProject/bin/gitflic-sync.sh <slug> -m "sync: первая публикация <slug>"

    # 4. Проверка доступности в хабе
    curl -sI http://127.0.0.1:8000/<slug>/ | head -n 1

## Логи

data/workspace.log, data/workspace-error.log — в репозиторий не попадают.
README_EOF