# GitProject Workspace Hub — Руководство по работе

Версия 2.9 от 2026-10-27. Владелец: Александр (@sokol491).
Назначение: постоянный контекст ИИ-ассистента для работы с проектами на нескольких Git-хостах.
Расположение: ~/gitproject/gitproject-workspace-guide.md

## 1. Архитектура системы

### Рабочее пространство
~/gitproject/                    <- корень workspace (порт 8000), он же git-репозиторий
├── router.php                       <- роутер хаба: /<slug>/... -> projects/<slug>/<docroot>/...
├── index.php                        <- главная страница хаба со списком проектов (сканирует projects/)
├── .env                             <- секреты (600): схема раздела 2
├── .gitignore                       <- хаба: /projects/, /data/*, .env, *.log, *.sqlite*, *.bak-*
├── README.md                        <- документация хаба: структура, установка, команды управления
├── bin/
│   ├── git-credential-multi.sh      <- мульти-хост credential helper (700)
│   ├── git-project-sync.sh          <- синхронизация/публикация проектов projects/<slug> (меню)
│   ├── git-project-update.sh        <- публикация самого хаба
│   ├── git-project-pull.sh          <- обновление хаба и проектов из remote
│   ├── git-project-clone.sh         <- интерактивный мастер клонирования с выбором хоста
│   ├── git-project-remote.sh        <- управление remote (add/remove/list + актуальность)
│   ├── audit-project.sh             <- аудит проекта перед паспортом
│   ├── lib/common.sh                <- общие функции мульти-хост (цвета, hosts.json, .env)
├── config/
│   └── hosts.json                   <- каталог хостов: статус, env_prefix, url_template
├── gitproject-workspace-guide.md       <- этот файл
├── STANDARD.md                      <- стандарт разработки (приоритетнее этого файла)
├── projects/                        <- контейнер репозиториев (изолирован, в репозиторий хаба не входит)
├── assets/                          <- общие ассеты хаба
└── data/                            <- логи: workspace.log, workspace-error.log, <host>.log

Состав projects/ динамический (см. главную страницу хаба); примеры клонов: archive, visual-novel-engine, virtual-cloud.

Принцип: оболочка (router.php, index.php, bin/, config/, data/, assets/) — только свои каталоги;
репозитории других проектов изолированы в projects/. URL-схема (/archive/, /virtual-cloud/) сохраняется.

### Мульти-хост система (ТЗ-001 v2)
Каталог хостов: config/hosts.json. Поля: host (домен), status, env_prefix, url_template.
Статусы: ready — доступен в CLI; integrating — в процессе интеграции, для выбора недоступен,
отображается с пометкой; disabled — отключён.
ФАКТ 2026-10-01: ready — gitflic (gitflic.ru), gitverse (gitverse.ru);
integrating — github, gitlab, bitbucket, gitea.
Каждый проект может иметь несколько remote (origin + дополнительные); имя дополнительного
remote = id хоста из hosts.json. Мульти-push (--all, --remote) — этапы 1-2 ТЗ-001 v2.
Окружение хаба обновляется ТОЛЬКО из репозитория sokol491/gitproject на GitFlic
(фиксировано: HUB_UPDATE_* в .env); проекты — из любых владельцев и готовых хостов.

### Репозиторий хаба на GitFlic

### Репозиторий хаба на GitVerse (зеркало)
Опубликован: https://gitverse.ru/Sokol491/gitproject
Зеркало основного репозитория для резервирования и тестирования мульти-хост синхронизации.
Владелец: Sokol491 (регистр учитывается!).
Переименован 2026-10-07: gitflicProject → gitproject (единообразие с GitFlic).
Опубликован: https://gitflic.ru/project/sokol491/gitproject
В коммит хаба никогда не попадают: .env, data/ (кроме .gitkeep), projects/ (контейнер),
логи, служебные файлы SQLite, *.bak-* (двойная защита: .gitignore + проверка staging скриптом).

### Docroot проектов (STANDARD.md §3, §9)
Обязательно: projects/<slug>/public/ — единственная публичная директория проекта.
Временно (до миграции): legacy docroot (Project/, web/, корень) допускаются с логированием в error.log.
Роутер проверяет: realpath() + str_starts_with() для защиты от path traversal.

### Порты
8000 — GitProject Workspace Hub (хаб проектов)

### URL-схема
http://0.0.0.0:8000/                          — хаб, список проектов
http://0.0.0.0:8000/<slug>/                   — проект

## 2. Секреты и файл .env

Путь: ~/gitproject/.env, права 600.
Формат ОБЯЗАТЕЛЬНЫЙ: одна переменная на строку, без пробелов вокруг "=",
в конце файла — перевод строки (иначе значения склеиваются, известный баг).

Автоисправление отсутствующего перевода строки (дописывает \n, только если его нет):
[ -n "$(tail -c 1 ~/gitproject/.env)" ] && printf '\n' >> ~/gitproject/.env

### Схема переменных (v2.0+, мульти-хост)

Глобальные:
- GIT_HOST          — хост по умолчанию для новых операций (gitflic.ru)
- ACTIVE_HOSTS      — список активных хостов через запятую
- HUB_UPDATE_HOST / HUB_UPDATE_OWNER / HUB_UPDATE_SLUG — источник обновлений хаба
  (фиксировано: gitflic.ru, sokol491, gitflicproject)
- NETWORK_PASSWORD  — пароль HTTP Basic Auth для доступа к хабу из LAN

На каждый хост (префикс = env_prefix из config/hosts.json):
- <PREFIX>_HUB_USER / <PREFIX>_HUB_TOKEN         — доступ к репозиторию хаба на этом хосте
- <PREFIX>_PROJECT_USER / <PREFIX>_PROJECT_TOKEN — доступ к проектам в projects/ на этом хосте
Префиксы: GITFLIC (gitflic.ru), GITVERSE (gitverse.ru), GITHUB, GITLAB, BITBUCKET, GITEA.
Значение "placeholder" равносильно отсутствию токена (helper и скрипты его игнорируют).
Owner на разных хостах может отличаться регистром (ФАКТ: GitFlic — sokol491, GitVerse — Sokol491).

Шаблон для новых устройств: ~/gitproject/.env.example (в репозитории хаба).

Просмотр .env ТОЛЬКО в маскированном виде (структура без значений):
sed -E 's/=(.+)$/=[скрыто]/' ~/gitproject/.env

Запрещены: cat .env, echo значений переменных, токены в URL команд,
немаскированные выводы и скриншоты с секретами.

Правила (раздел 9 STANDARD.md):
- секреты никогда не попадают в код, репозитории, URL и ответы в чат;
- токены с ограниченными правами и сроком жизни; после работ — отзываются;
- утечка (вывод на экран, скриншот, история) = немедленный отзыв на хосте,
  замена в .env, проверка ~/.bash_history и .git/config на остатки.

## 3. Мульти-хост credential helper (основной канал)

Помощник: ~/gitproject/bin/git-credential-multi.sh, права 700.
Логика:
1) читает запрос git из stdin ЦЕЛИКОМ до разбора (protocol/host/path);
2) префикс по домену: gitflic.ru -> GITFLIC, gitverse.ru -> GITVERSE,
   github.com -> GITHUB, gitlab.com -> GITLAB; неизвестный домен — молчаливый выход;
3) область: HUB — путь репозитория содержит gitproject (регистронезависимо, на ЛЮБОМ хосте),
   иначе PROJECT;
4) токен <PREFIX>_<SCOPE>_TOKEN, пользователь <PREFIX>_<SCOPE>_USER; fallback PROJECT -> HUB;
5) placeholder или пустой токен — молчаливый выход с подсказкой в stderr:
   вводить нужно API-ТОКЕН хоста, а не пароль аккаунта.

Подключение (выполнено один раз):
git config --global --unset-all credential.helper
git config --global credential.helper '/home/sokol491/gitproject/bin/git-credential-multi.sh'

Однократная настройка идентичности (без неё commit падает с Author identity unknown):
git config --global user.name "Alexander Sokolyuk"
git config --global user.email "<почта аккаунта GitFlic>"
git config --global --get user.name && git config --global --get user.email   # проверка

Проверка помощника БЕЗ вывода токена:
printf 'protocol=https\nhost=gitflic.ru\npath=sokol491/gitproject.git\n\n' \
  | ~/gitproject/bin/git-credential-multi.sh get \
  | sed -E 's/^(password=).*/\1[скрыто]/'

Клонирование и push по HTTPS идут без ввода пароля:
git clone https://gitflic.ru/project/sokol491/searchengine.git ~/gitproject/projects/searchengine

Сквозной тест канала (правка -> коммит -> push -> сверка):
cd ~/gitproject/projects/archive
printf '\nТест push: %s, канал HTTPS + credential helper.\n' "$(date +%Y-%m-%d_%H:%M)" >> README.md
git add README.md
git commit -m "docs: тест push через credential helper, токен из .env"
git push origin "$(git branch --show-current)"
git log --oneline -1
git ls-remote origin "$(git branch --show-current)"
# Успех: хеши совпадают и отличаются от предыдущего коммита; коммит виден на сайте.
# Откат теста ТОЛЬКО через: git revert --no-edit HEAD && git push origin "$(git branch --show-current)"
# Силовой reset + push --force запрещены: история на сервере общая.

## 4. Сервис systemd хаба

Файл: ~/.config/systemd/user/gitproject-workspace.service
Ключевые строки ExecStart: php -S 0.0.0.0:8000 -t %h/gitproject %h/gitproject/router.php
Логи: StandardOutput/StandardError append в ~/gitproject/data/

Команды:
systemctl --user status gitproject-workspace.service --no-pager
systemctl --user restart gitproject-workspace.service
systemctl --user enable gitproject-workspace.service
sudo loginctl enable-linger $USER        # автозапуск при загрузке, до входа в сеанс
ss -tlnp | grep :8000
tail -f ~/gitproject/data/workspace.log
tail -f ~/gitproject/data/workspace-error.log

## 5. Интеграция проекта в хаб (BASE_PATH)

Проблема: абсолютные URL проекта (/api/..., location.href='/') ломаются под префиксом /archive/.
Решение по шагам:
1) router.php при выполнении PHP выставляет: $_SERVER['APP_BASE_PATH'] = '/' . $project;
2) src/bootstrap.php проекта: define('BASE_PATH', rtrim((string)($_SERVER['APP_BASE_PATH'] ?? ''), '/'));
   там же срезает BASE_PATH из REQUEST_URI перед проверками зон и редиректами;
3) PHP строит ссылки через App\Support\Url::to('/login.php');
4) страницы отдают <meta name="app-base" content="<?= htmlspecialchars(BASE_PATH) ?>">,
   JS читает: const BASE = document.querySelector('meta[name="app-base"]').content;
   и использует BASE + '/api/...'. Без новых window-глобалов.

## 6. Жизненный цикл репозиториев

### Правило ветвления
Ветки только master: локально и на хостах. Все публикации — напрямую в master.

### Интерактивное клонирование (мульти-хост мастер)
~/gitproject/bin/git-project-clone.sh
Четыре шага с пояснениями, подсказками и сводкой перед выполнением:
1) выбор хоста-источника (ОТКУДА) — список только ready-хостов со статусами;
2) параметры проекта (owner, slug на этом хосте);
3) локальный каталог (КУДА): имя по умолчанию = slug; при существующем каталоге варианты:
   использовать существующий (добавить remote), задать другое имя, удалить (подтверждение
   словом DELETE), отмена;
4) сводка (URL источника, целевой каталог, имя remote) и подтверждение y/n.
После клонирования — предложение добавить remote других ready-хостов (мульти-хост).
Неинтерактивный режим: --host <domain> --owner <owner> --slug <slug> [--as-origin].

### Управление remote и актуальность
~/gitproject/bin/git-project-remote.sh <slug> --list     # таблица: remote, URL, SHA, дата, статус
~/gitproject/bin/git-project-remote.sh <slug> --add <domain> [--owner <owner>]
~/gitproject/bin/git-project-remote.sh <slug> --remove <remote>
Подсветка актуальности в --list: зелёный — самый свежий коммит среди remote;
жёлтый — отстаёт на N коммитов; серый — не инициализирован (нет refs).
--add: проверка статуса хоста (только ready), URL из url_template hosts.json, проверка
существования репозитория (ls-remote), fetch, лог в data/<host>.log.
--remove: origin не удаляется; подтверждение y/N; репозиторий на хосте НЕ удаляется.

### Синхронизация и публикация проекта
~/gitproject/bin/git-project-sync.sh                  # интерактивное меню проектов (цвет, all, q)
~/gitproject/bin/git-project-sync.sh <slug> [-m "сообщение"]
Режимы: status [slug], fix [slug], probe.
Полный цикл: fetch, сверка ahead/behind, коммит локальных изменений (защита запрещённых путей),
merge upstream с приоритетом локальной версии (merge -X ours), push, сверка хэшей.
Мульти-push (--all, --remote <имя>) — этапы 1-2 ТЗ-001 v2, в реализации.

### Публикация репозитория хаба
~/gitproject/bin/git-project-update.sh [-m "сообщение"]
~/gitproject/bin/git-project-update.sh status
~/gitproject/bin/git-project-update.sh fix
URL origin хаба — только нижний регистр slug: https://gitflic.ru/project/sokol491/gitproject.git
Если у origin/master есть посторонняя история (например gitflic-cl.yaml),
связать без force-push: git merge origin/master --allow-unrelated-histories -X ours

### Обновление хаба и проектов
~/gitproject/bin/git-project-pull.sh
Хаб — только из HUB_UPDATE_* (GitFlic, sokol491/gitproject); проекты — из их origin.

### Аудит перед паспортом (раздел 13 STANDARD.md), секреты не присылать:
for proj in archive searchengine pdf visual-novel-engine; do
  echo "======== $proj ========"
  ( cd ~/gitproject/projects/"$proj" \
    && find . -maxdepth 3 -not -path './.git*' -not -path './vendor/' -not -path './data/backups/' -not -name '.env*' | sort \
    && php -v | head -n 1 \
    && (ls -la data/ 2>/dev/null || echo "data/ отсутствует") \
    && { [ -f composer.json ] && cat composer.json || echo "composer.json отсутствует"; } )
done
По выводу аудита заполняется PROJECT PASSPORT (раздел 12 STANDARD.md),
факты не выдумывать, при отсутствии данных писать "нет данных".

### Логирование
Операции по хостам: data/<host>.log (gitflic.log, gitverse.log, ...);
хаб: data/workspace.log, data/workspace-error.log. Логи в репозиторий не попадают.

## 8. Безопасность сети и прав

- Хаб слушает 0.0.0.0:8000; ufw неактивен, порт открыт всей LAN.
- Из LAN хаб закрыт HTTP Basic Auth (NETWORK_PASSWORD из .env); с 127.0.0.1 — без пароля.
- Роутер отдаёт из проектов только public/ (STANDARD.md §9); запрос /.env запрещён списком forbidden.
- Временно допускаются legacy docroot (Project/, web/) с логированием в workspace-error.log.
- Защита от path traversal: realpath() + str_starts_with() для проверки, что файл внутри docroot.
- Права: .env 600, helper и скрипты bin/ 700, ~/.ssh/config 600, приватный ключ 600.

## 9. Отношение к STANDARD.md

STANDARD.md — приоритетный документ: каркас, PSR-12, SQLite WAL, JSON-контракт,
kit-модули, аудит. Этот файл — эксплуатация workspace: порты, systemd, git-доступ,
интеграция в хаб, мульти-хост. При противоречии действует STANDARD.md, владельцу
предлагается правка с changelog.

## 10. Cheat sheet

Хаб:
~/gitproject/bin/git-project-update.sh -m "docs: правки"   # публикация хаба
~/gitproject/bin/git-project-update.sh status               # состояние хаба
~/gitproject/bin/git-project-update.sh fix                  # подготовка к первой публикации
~/gitproject/bin/git-project-pull.sh                        # обновление хаба из GitFlic

Проекты:
~/gitproject/bin/git-project-clone.sh                       # мастер клонирования с выбором хоста
~/gitproject/bin/git-project-remote.sh <slug> --list        # remote + актуальность
~/gitproject/bin/git-project-remote.sh <slug> --add <domain>
~/gitproject/bin/git-project-sync.sh                        # интерактивное меню проектов
~/gitproject/bin/git-project-sync.sh <slug> -m "sync: правки"
~/gitproject/bin/git-project-sync.sh status                 # таблица состояний

Общее:
git config --global --get user.name                             # идентичность настроена?
systemctl --user restart gitproject-workspace.service
ss -tlnp | grep :8000
curl -sI http://127.0.0.1:8000/ | head -n 1
tail -f ~/gitproject/data/workspace-error.log
sed -E 's/=(.+)$/=[скрыто]/' ~/gitproject/.env              # маскированный просмотр .env