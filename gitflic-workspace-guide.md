# GitProject Workspace Hub — Руководство по работе

Версия 2.2 от 2026-10-07. Владелец: Александр (@sokol491).
Назначение: постоянный контекст ИИ-ассистента для работы с проектами на нескольких Git-хостах.
Расположение: ~/gitproject/gitflic-workspace-guide.md
Прежнее имя: GitFlic Dev Workspace (переименован по ТЗ-001 v2, этап 0; путь и slug сохранены).

Изменения 2.1:
- раздел 1: состав мульти-хост системы (git-project-clone.sh, git-project-remote.sh,
  bin/lib/common.sh, config/hosts.json) и симлинки совместимости;
- раздел 2: итоговая схема .env (HUB/PROJECT на каждый хост, placeholder = нет токена);
- раздел 4: логика мульти-хост credential helper (HUB по пути gitflicproject на любом хосте);
- раздел 7: мастер клонирования в 4 шага, remote с подсветкой актуальности, логи по хостам;
- раздел 11: факты GitVerse (URL без /project/, регистр owner, raw по ветке), баги stdin и sed ERE,
  факт мульти-push;
- раздел 12: профиль GitVerse; раздел 14: changelog v2.0, v2.1.

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
│   ├── git-credential-gitflic.sh    -> git-credential-multi.sh   (симлинк совместимости)
│   ├── gitflic-sync.sh              -> git-project-sync.sh       (симлинк совместимости)
│   ├── gitflic-sync-hub.sh          -> git-project-update.sh     (симлинк совместимости)
│   └── gitflic-update.sh            -> git-project-pull.sh       (симлинк совместимости)
├── config/
│   └── hosts.json                   <- каталог хостов: статус, env_prefix, url_template
├── gitflic-workspace-guide.md       <- этот файл
├── STANDARD.md                      <- стандарт разработки (приоритетнее этого файла)
├── projects/                        <- контейнер репозиториев (изолирован, в репозиторий хаба не входит)
├── assets/                          <- общие ассеты хаба
└── data/                            <- логи: workspace.log, workspace-error.log, <host>.log

Состав projects/ динамический (см. главную страницу хаба); примеры клонов: archive,
searchengine, pdf, visual-novel-engine, virtual-cloud, test.

Принцип: оболочка (router.php, index.php, bin/, config/, data/, assets/) — только свои каталоги;
репозитории других проектов изолированы в projects/. URL-схема (/archive/, /searchengine/) сохраняется.

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
ФАКТ 2026-09-29: фактический slug — gitflicproject (нижний регистр); git-транспорт GitFlic
регистрозависим, Web-UI открывает оба написания. URL origin локального хаба — только нижний регистр.
В коммит хаба никогда не попадают: .env, data/ (кроме .gitkeep), projects/ (контейнер),
логи, служебные файлы SQLite, *.bak-* (двойная защита: .gitignore + проверка staging скриптом).

### Docroot проектов (STANDARD.md §3, §9)
Обязательно: projects/<slug>/public/ — единственная публичная директория проекта.
Временно (до миграции): legacy docroot (Project/, web/, корень) допускаются с логированием в error.log.
Роутер проверяет: realpath() + str_starts_with() для защиты от path traversal.

### Порты
8000 — GitProject Workspace Hub (хаб проектов)
8080 — занят сторонним проектом, НЕ трогать и не занимать

### URL-схема
http://192.168.31.119:8000/                          — хаб, список проектов
http://192.168.31.119:8000/<slug>/                   — проект

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

## 3. SSH-ключ GitFlic (запасной канал, не зависит от токена)

Генерация и подключение:
ssh-keygen -t ed25519 -C "sokol491-gitflic" -f ~/.ssh/gitflic_ed25519   # passphrase пустой
cat ~/.ssh/gitflic_ed25519.pub    # содержимое добавить: GitFlic -> настройки -> SSH-ключи,
                                  # имя lenovo-ideapad, срок +30 дней
cat >> ~/.ssh/config <<'EOF'
Host gitflic.ru
    User git
    IdentityFile ~/.ssh/gitflic_ed25519
    IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config
ssh -T git@gitflic.ru
# Ответ "GitFlic не поддерживает данный вид ssh взаимодействия" = ключ принят,
# shell запрещён, git-операции работают. Это норма.

Клонирование по SSH (slug регистрозависим! сверять с диалогом "Клонировать"):
git clone git@gitflic.ru:sokol491/archive.git ~/gitproject/projects/archive

## 4. Мульти-хост credential helper (основной канал)

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

## 5. Сервис systemd хаба

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

## 6. Интеграция проекта в хаб (BASE_PATH)

Проблема: абсолютные URL проекта (/api/..., location.href='/') ломаются под префиксом /archive/.
Решение по шагам:
1) router.php при выполнении PHP выставляет: $_SERVER['APP_BASE_PATH'] = '/' . $project;
2) src/bootstrap.php проекта: define('BASE_PATH', rtrim((string)($_SERVER['APP_BASE_PATH'] ?? ''), '/'));
   там же срезает BASE_PATH из REQUEST_URI перед проверками зон и редиректами;
3) PHP строит ссылки через App\Support\Url::to('/login.php');
4) страницы отдают <meta name="app-base" content="<?= htmlspecialchars(BASE_PATH) ?>">,
   JS читает: const BASE = document.querySelector('meta[name="app-base"]').content;
   и использует BASE + '/api/...'. Без новых window-глобалов.

## 7. Жизненный цикл репозиториев

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
Если у origin/master есть посторонняя история (например gitflic-cl.yaml, см. раздел 11),
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

## 11. Известные проблемы и решения

Общие:
- Порт 8000 занят посторонним php: ss -tlnp | grep :8000, kill <PID>, restart сервиса.
- Author identity unknown при commit: настроить идентичность по разделу 4.
- bash: синтаксическая ошибка рядом с «|»: выполнена строка-шаблон "<команда> | sed ...";
  подставлять реальную команду вместо <команда>, шаблон не запускать.
- password с хвостом вида "...GITFLIC_USER=..." или склейка выводов: в .env нет перевода
  строки в конце; исправить командой раздела 2, проверить маскированным просмотром.
- Токен побывал на экране/в скриншоте/в URL: отозвать на хосте, выпустить новый,
  вычистить историю: sed -i '/sokol491:.*@gitflic\.ru/d' ~/.bash_history && history -c && history -w
- Проект из LAN просит пароль хаба: это ожидаемое поведение Basic Auth.
- Legacy docroot: проект отдаёт 404, в error.log строка "legacy docroot ... требуется миграция на public/".
  Решение: перенести содержимое legacy-каталога (Project/, web/) в public/, обновить includes/ на относительные пути.
- HTTP 000 при curl: сервис не запустился или порт занят; проверить systemctl --user status, ss -tlnp | grep :8000.

GitFlic:
- Регистрозависимость slug (ФАКТ 2026-09-29): git-транспорт при неверном регистре отвечает
  HTTP 500/"not found", Web-UI открывает оба написания. Slug хаба: gitflicproject (нижний регистр).
- HTTP 500 на несуществующий репозиторий: git-транспорт возвращает 500 вместо 404;
  скрипты парсят вывод ls-remote и трактуют как "репозитория нет".
- gitflic-cl.yaml (ФАКТ 2026-09-29): при создании репозитория через UI добавляется CI-файл;
  если CI не используется — удалить через UI до первого push; истории связать
  git merge origin/master --allow-unrelated-histories -X ours (без force-push).
- Создание репозиториев через API не подтверждено (probe: 404/302) — создавать через Web-UI.

GitVerse (ФАКТЫ 2026-10-01):
- URL клона/пуша: https://gitverse.ru/<owner>/<slug>.git — БЕЗ сегмента /project/ (в отличие от GitFlic);
  шаблон зафиксирован в config/hosts.json (url_template).
- Регистр owner: Sokol491 (на GitFlic — sokol491); owner берётся из <PREFIX>_PROJECT_USER/.env или запроса.
- Raw-файл: https://gitverse.ru/api/repos/<owner>/<repo>/raw/branch/<ветка>/<путь> —
  работает по имени ветки, commit SHA не нужен (в отличие от GitFlic /blob/raw).
- Платформа: признаки Gitea-совместимого API (/api/repos/...); токен создаётся в Web-UI
  (настройки/access tokens); заголовок авторизации API не проверен (гипотеза).
- Мульти-push проверен: коммит 63bda97 (проект test) опубликован одновременно на GitFlic и GitVerse,
  таблица актуальности показала оба remote зелёными.

Мульти-хост и скрипты:
- Credential helper спрашивал username/password интерактивно (баг v2.0): причина —
  cat >/dev/null съедал stdin до разбора; исправлено: stdin читается один раз целиком (input="$(cat)").
- sed с -E и группами \(\): ошибка "недопустимая ссылка \1" — в ERE группы обозначаются (),
  обратные слэши только в BRE (баг исправлен в git-project-clone.sh 2026-10-01).
- Интерактивные меню не должны печатать данные в stdout вместе с возвращаемым значением
  функции (баг захвата ANSI-кодов в имя каталога, исправлен 2026-10-01).
- Защиты staging sync-скриптов: git-project-sync.sh запрещает только КОРНЕВЫЕ data/, storage/, vendor/
  (public/data/ разрешён); git-project-update.sh запрещает .env, data/ (кроме .gitkeep), projects/,
  логи и служебные файлы SQLite на любой глубине; нарушение = код выхода 5, коммит отменён.

## 12. Ресурсы

- Профиль GitFlic: https://gitflic.ru/user/sokol491
- Профиль GitVerse: https://gitverse.ru/Sokol491
- Репозиторий хаба: https://gitflic.ru/project/sokol491/gitproject
- Хаб: http://192.168.31.119:8000/
- Локально: http://127.0.0.1:8000/

## 13. Web-scraping: blob/tree GitFlic без токена

Для публичных репозиториев GitFlic отдаёт содержимое файлов через web_extractor
без авторизации, если использовать правильный формат URL.

Приоритет: ИСХОДНЫЙ КОД файла получаем по /blob/raw (чистый текст);
blob HTML — вспомогательный (просмотр и получение ссылки Raw с commit=<SHA>).

Форматы URL (проверено 2026-09-29 на sokol491/archive, sokol491/visual-novel-engine):

Web blob RAW (ОСНОВНОЙ: чистый текст файла, БЕЗ HTML-обёртки):
https://gitflic.ru/project/<user>/<slug>/blob/raw?file=<путь, URL-encoded>&commit=<SHA>
Пример:
https://gitflic.ru/project/sokol491/visual-novel-engine/blob/raw?file=README.md&commit=d7fb9cb2c0d1ba22f2008bec3116fe0cf60e0bd6
Требует commit hash (не имя ветки!). Источники commit SHA:
(а) ссылка/кнопка Raw на blob-странице — её href уже содержит commit=<SHA>;
(б) git ls-remote https://gitflic.ru/project/<user>/<slug>.git | grep HEAD (SHA вершины ветки);
(в) SHA, сообщённый владельцем.
Преимущества: только код, без парсинга HTML; детерминированный результат.

Web blob (HTML-страница с обёрткой GitFlic; вспомогательный):
https://gitflic.ru/project/<user>/<slug>/blob?branch=<ветка>&file=<путь, URL-encoded>
Примеры:
https://gitflic.ru/project/sokol491/archive/blob?branch=master&file=README.md
https://gitflic.ru/project/sokol491/archive/blob?branch=master&file=Project/includes/config.php
Кириллица в путях требует URL-encoding:
Структура.txt → %D0%A1%D1%82%D1%80%D1%83%D0%BA%D1%82%D1%83%D1%80%D0%B0.txt

Web tree (список файлов каталога):
https://gitflic.ru/project/<user>/<slug>/file?branch=<ветка>
Пример: https://gitflic.ru/project/sokol491/archive/file?branch=master

ВАЖНО: GitHub-формат /blob/<ветка>/<путь> (без query-параметров) НЕ работает —
GitFlic редирексит на страницу входа. Использовать только query-формат.

REST API (требует токен):
GET https://api.gitflic.ru/project/<user>/<slug>/blob?commitHash=<hash>&file=<путь>
Заголовок: Authorization: token <GITFLIC_TOKEN из .env>
commitHash брать из: GET /project/<user>/<slug>/branch/<ветка>
Токен: права read:project, срок 1-2 дня, после работ — отозвать.

Полный клон/архив без авторизации запрещён (STANDARD.md §1).

Применение:
- Исходный код любого файла публичного репозитория — через /blob/raw.
- Аудит структуры проекта до локального клона (раздел 13 STANDARD.md).
- Получение содержимого ключевых файлов (README.md, config.php, index.php).
- Проверка соответствия каркасу (STANDARD.md §3) без скачивания всего репозитория.
- Быстрый просмотр кода для формирования PROJECT PASSPORT.

Ограничения:
- Только публичные репозитории.
- Скорость: 1-3 файла за запрос (web_extractor может объединять URL).
- Для приватных репозиториев — только локальный клон с credential helper (раздел 4).
- Секреты в коде (.env, токены в конфиге) не выводить в чат — только констатировать наличие.
- /blob/raw требует commit SHA, не работает с именами веток.

Алгоритм получения файла (ОБЯЗАТЕЛЕН до любого вопроса пользователю):
1. user и slug брать из §1/§12 (sokol491; список projects/). Веб-поиск репозитория ЗАПРЕЩЁН: URL детерминирован.
2. Tree первым: /project/<user>/<slug>/file?branch=master; ошибка/пусто → branch=main.
3. Raw (ОСНОВНОЙ шаг): если commit SHA известен (ls-remote HEAD, сообщён владельцем) —
   /project/<user>/<slug>/blob/raw?file=<путь>&commit=<SHA>; это и есть исходный код файла.
4. Если SHA неизвестен: blob HTML /project/<user>/<slug>/blob?branch=<ветка из шага 2>&file=<путь>;
   на странице взять href ссылки Raw (содержит commit=<SHA>) и повторить шаг 3;
   для readme перебирать регистр: README.md → readme.md → Readme.md;
   путь из tree-листа брать дословно, кириллицу URL-encode.
5. Содержимое blob HTML допустимо только как временная замена, если Raw недоступен.
6. Исчерпаны шаги 1-4 и инструмент недоступен → выдать пользователю готовые URL
   (raw + blob) и команду локального просмотра, а не открытый запрос «дайте ссылку»:
   cd ~/gitproject/projects/<slug> && find . -maxdepth 1 -iname 'readme*' -exec cat {} +
7. Только после этого — просить вставку текста (STANDARD.md §1).

Типичные ошибки (ЗАПРЕЩЁННЫЕ подмены):
- Выдумывать локальные пути: единственно верный — ~/gitproject/projects/<slug> (§1).
- GitLab-синтаксис: /api/v4/..., заголовок PRIVATE-TOKEN — в GitFlic не существует;
  верно: https://api.gitflic.ru/project/<user>/<slug>/blob?commitHash=<SHA>&file=<путь>,
  заголовок: Authorization: token <GITFLIC_TOKEN из .env>.
- GitHub-формат /blob/<ветка>/<путь> (запрещён выше) — не предлагать пользователю как рабочий.
- Спрашивать у пользователя владельца, slug, ссылку или локальный путь,
  если они выводятся из §1/§12; вопрос допустим только после шагов 1-5 алгоритма.
- Отдавать пользователю blob HTML как «исходный код», когда доступен /blob/raw.

## 14. Changelog

- 2026-09-25: v1.3 — контейнер projects/, миграция репозиториев, docroot public/.
- 2026-09-29: v1.4 — раздел 13: web-scraping blob/tree GitFlic без токена.
- 2026-09-29: v1.5 — раздел 13: алгоритм получения файла до вопроса пользователю.
- 2026-09-29: v1.6 — раздел 13: добавлен формат /blob/raw для чистого текста файла.
- 2026-09-29: v1.7 — раздел 13: блок типичных ошибок.
- 2026-09-29: v1.8 — раздел 13: /blob/raw — основной способ получения исходного кода.
- 2026-09-29: v1.9 — раздел 1: bin/gitflic-sync-hub.sh, README.md, хаб как git-репозиторий; раздел 7: публикация хаба; раздел 10: команды хаба; раздел 11: регистрозависимость slug, HTTP 500, gitflic-cl.yaml, защиты staging; раздел 12: ссылка на репозиторий хаба.
- 2026-09-30: v1.9.1 — раздел 7: удалены упоминания ветки qwen и модели перенаправления upstream; зафиксировано правило «только master: локально и на GitFlic».
- 2026-10-01: v1.9.2 — раздел 2: переменная GIT_HOST в .env (мульти-хост); раздел 7: клонирование репозиториев любого владельца; раздел 10: интерактивное меню sync с цветовым оформлением; README: раздел «Установка».
- 2026-10-01: v1.9.3 — раздел 2: схема разделения токенов HUB/PROJECT с заделом GitVerse; раздел 4: credential helper по контексту репозитория; раздел 7: подраздел «Обновление окружения».
- 2026-10-01: v2.0 — переименование в GitProject Workspace Hub (ТЗ-001 v2, этап 0); переименование скриптов с симлинками совместимости; мульти-хост credential helper; bin/lib/common.sh; config/hosts.json; исправлен баг stdin в helper.
- 2026-10-02: v2.1 — раздел 1: мульти-хост состав (мастера clone/remote, lib, hosts.json); раздел 2: итоговая схема .env (HUB/PROJECT на хост, placeholder); раздел 4: логика helper (HUB по пути gitflicproject на любом хосте); раздел 7: мастер клонирования в 4 шага, remote с актуальностью, логи по хостам; раздел 11: факты GitVerse, баги stdin и sed ERE, факт мульти-push; раздел 12: профиль GitVerse.

Конец документа.
- 2026-10-07: v2.2 — полное переименование рабочего пространства (Фаза 2 ТЗ-001 v2):
  локальный путь ~/gitflicProject → ~/gitproject; systemd unit gitflic-workspace.service →
  gitproject-workspace.service; репозиторий на GitFlic sokol491/gitflicproject → sokol491/gitproject;
  credential helper: область HUB определяется по пути *gitproject*; все внутренние пути
  скриптов обновлены; симлинки gitflic-*.sh сохранены для обратной совместимости.
2026-10-07: v2.2 — полное переименование рабочего пространства (Фаза 2 ТЗ-001 v2):
локальный путь ~/gitflicProject → ~/gitproject; systemd unit gitflic-workspace.service →
gitproject-workspace.service; репозиторий на GitFlic sokol491/gitflicproject → sokol491/gitproject;
все пути в документации обновлены; критерий HUB-области в helper: *gitproject*;
симлинки gitflic-*.sh сохранены для обратной совместимости.
2026-10-07: v2.2.1 — зеркало хаба на GitVerse переименовано: Sokol491/gitflicProject →
Sokol491/gitproject для единообразия с основным репозиторием на GitFlic.

2026-10-07: v2.2.2 — добавлен раздел о регистрозависимости owner на разных Git-хостах.
GitFlic допускает любой регистр (sokol491 = Sokol491), GitVerse требует точное совпадение
(Sokol491 с заглавной буквы). Переменная GITVERSE_PROJECT_USER в .env должна содержать
точное написание из профиля GitVerse.
