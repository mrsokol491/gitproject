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
    │   ├── gitflic-sync.sh            # сверка/коммит/публикация (см. ниже)
    │   └── audit-project.sh           # аудит проекта перед паспортом
    ├── projects/                  # контейнер репозиториев (в репозиторий хаба не входит)
    ├── assets/                    # ассеты хаба (css, img)
    ├── data/                      # логи хаба (в репозиторий не входят)
    ├── STANDARD.md                # стандарт разработки
    ├── gitflic-workspace-guide.md # руководство по эксплуатации
    └── README.md                  # этот файл

## Порты и URL

- 8000 — хаб (systemd: gitflic-workspace.service; вручную:
  `php -S 0.0.0.0:8000 -t ~/gitflicProject ~/gitflicProject/router.php`)
- 8080 — занят сторонним проектом: не трогать и не занимать
- http://127.0.0.1:8000/ — хаб; http://127.0.0.1:8000/<slug>/ — проект

## Контейнер projects/ и состояние проектов

| slug | тип | docroot для хаба | публикация |
|---|---|---|---|
| archive | PHP | legacy `Project/` (до миграции) | ветка = upstream |
| searchengine | Java/Maven | не обслуживается (нет index в docroot) | ветка = upstream |
| pdf | Node.js (pdf.js) | не обслуживается (нет index в docroot) | ветка = upstream |
| visual-novel-engine | PHP + JS | `public/` | локально `qwen` -> GitFlic `master` |

Docroot проекта — только `public/` (STANDARD.md §3, §9); legacy-каталоги допускаются
временно с записью в data/workspace-error.log.

## Синхронизация и публикация (bin/gitflic-sync.sh)

- `gitflic-sync.sh <slug> [-m "сообщение"]` — полный цикл: fetch, сверка ahead/behind,
  коммит локальных изменений (защита запрещённых путей), интеграция upstream
  с приоритетом локальной версии (merge -X ours), push `локальная:upstream`, сверка хэшей.
- Режимы: `status [slug]`, `fix [slug]` (init/origin/контроль), `probe` (эндпоинты API).
- Цель публикации = upstream локальной ветки; без upstream — одноимённая ветка с `-u`.
- Запрещены: push --force, переписывание истории (guide §4).

## Безопасность

- Секреты только в `.env` (600); маскированный просмотр:
  `sed -E 's/=(.+)$/=[скрыто]/' ~/gitflicProject/.env`
- Токен GitFlic: ограниченные права, срок 1-2 дня, после работ отзывается.
- Из LAN хаб закрыт HTTP Basic Auth; с 127.0.0.1 — без пароля.
- Роутер: forbidden-список (.env, bin/, data/, projects/, *.md), защита path traversal
  (realpath + проверка префикса docroot).
- Особенность GitFlic: git-транспорт отвечает HTTP 500 на несуществующий репозиторий.
- Создание репозиториев через API не подтверждено (probe: 404/302) — через Web-UI.

## Добавление проекта

    git clone https://gitflic.ru/project/sokol491/<slug>.git ~/gitflicProject/projects/<slug>
    ~/gitflicProject/bin/gitflic-sync.sh fix <slug>
    ~/gitflicProject/bin/gitflic-sync.sh <slug>

## Логи

data/workspace.log, data/workspace-error.log — в репозиторий не попадают.