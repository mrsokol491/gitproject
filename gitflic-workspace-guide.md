# GitFlic Workspace — Руководство по работе

Версия 1.9.3 от 2026-10-01. Владелец: Александр (@sokol491).
Назначение: постоянный контекст ИИ-ассистента для работы с проектами GitFlic.
Расположение: ~/gitflicProject/gitflic-workspace-guide.md

Изменения 1.9.3:
- раздел 2: новая схема переменных .env с разделением по областям (HUB/PROJECT) и поддержке мульти-хост (GIT_HOST);
- раздел 4: обновлён код credential helper (чтение GITFLIC_HUB_* / GITFLIC_PROJECT_* по контексту);
- раздел 7: добавлен подраздел «Обновление окружения» (только из репозитория sokol491);
- changelog: v1.9.3.

## 1. Архитектура системы

### Рабочее пространство
~/gitflicProject/                    <- корень workspace (порт 8000), он же git-репозиторий
├── router.php                       <- роутер хаба, проксирует запросы в проекты
├── index.php                        <- главная страница хаба со списком проектов
├── .env                             <- секреты: GIT_HOST, GITFLIC_HUB_*, GITFLIC_PROJECT_*, NETWORK_PASSWORD
├── .gitignore                       <- хаба: /projects/, /data/*, .env, *.log, *.sqlite*, *.bak-*
├── README.md                        <- документация хаба: структура, команды управления с примерами
├── bin/
│   ├── git-credential-gitflic.sh    <- credential helper, читает токены из .env (700)
│   ├── gitflic-sync.sh              <- интерактивная синхронизация проектов projects/<slug>
│   ├── gitflic-sync-hub.sh          <- синхронизация и публикация самого хаба
│   └── audit-project.sh             <- скрипт аудита проекта
├── gitflic-workspace-guide.md       <- этот файл
├── STANDARD.md                      <- стандарт разработки (приоритетнее этого файла)
├── projects/                        <- контейнер репозиториев (изолирован от оболочки)
│   ├── archive/                     <- клон sokol491/archive (PHP, legacy docroot Project/)
│   ├── searchengine/                <- клон sokol491/searchengine (Java/Maven)
│   ├── pdf/                         <- клон sokol491/pdf (Node.js/pdf.js, legacy docroot web/)
│   └── visual-novel-engine/         <- клон sokol491/visual-novel-engine (PHP+JS, docroot public/)
├── assets/                          <- общие ассеты хаба
└── data/                            <- логи хаба: workspace.log, workspace-error.log

Принцип: оболочка (router.php, index.php, bin/, data/, assets/) — только свои каталоги;
репозитории других проектов изолированы в projects/. URL-схема (/archive/, /searchengine/) сохраняется.

### Репозиторий хаба на GitFlic
Опубликован: https://gitflic.ru/project/sokol491/gitflicproject
ФАКТ 2026-09-29: фактический slug — gitflicproject (нижний регистр); git-транспорт GitFlic
регистрозависим, Web-UI открывает оба написания. URL origin локального хаба — только нижний регистр.
В коммит хаба никогда не попадают: .env, data/ (кроме .gitkeep), projects/ (контейнер),
логи, служебные файлы SQLite, *.bak-* (двойная защита: .gitignore + проверка staging скриптом).

### Docroot проектов (STANDARD.md §3, §9)
Обязательно: projects/<slug>/public/ — единственная публичная директория проекта.
Временно (до миграции): legacy docroot (Project/, web/, корень) допускаются с логированием в error.log.
Роутер проверяет: realpath() + str_starts_with() для защиты от path traversal.

### Порты
8000 — GitProject Workspace Hub (хаб проектов @sokol491)
8080 — занят сторонним проектом, НЕ трогать и не занимать

### URL-схема
http://192.168.31.119:8000/                          — хаб, список проектов
http://192.168.31.119:8000/archive/                  — проект archive
http://192.168.31.119:8000/searchengine/             — проект searchengine
http://192.168.31.119:8000/pdf/                      — проект pdf
http://192.168.31.119:8000/visual-novel-engine/      — проект visual-novel-engine

## 2. Секреты и файл .env

Путь: ~/gitflicProject/.env, права 600.
Формат ОБЯЗАТЕЛЬНЫЙ: одна переменная на строку, без пробелов вокруг "=",
в конце файла — перевод строки (иначе значения склеиваются, известный баг).

Автоисправление отсутствующего перевода строки (дописывает \n, только если его нет):
[ -n "$(tail -c 1 ~/gitflicProject/.env)" ] && printf '\n' >> ~/gitflicProject/.env

### Переменные (схема v1.9.3)

**GIT_HOST** — хост Git-системы (по умолчанию gitflic.ru; в будущем: github.com, gitlab.com, bitbucket.org).
Используется sync-скриптами для построения URL и probe.

**Токены разделены по областям:**

| Область | Переменные | Назначение |
|---|---|---|
| Hub | `GITFLIC_HUB_USER`, `GITFLIC_HUB_TOKEN` | Репозиторий хаба (sokol491/gitflicProject) |
| Project | `GITFLIC_PROJECT_USER`, `GITFLIC_PROJECT_TOKEN` | Проекты в projects/ (любой владелец) |
| Vercel | `GITVERCE_HUB_USER`, `GITVERCE_HUB_TOKEN` | Задел на интеграцию с Vercel |
| Auth | `NETWORK_PASSWORD` | HTTP Basic Auth для доступа к хабу из LAN |

**Credential helper** автоматически выбирает нужную пару токенов:
- При push хаба → `GITFLIC_HUB_*`
- При push проектов → `GITFLIC_PROJECT_*`

**Правила (раздел 9 STANDARD.md):**
- секреты никогда не попадают в код, репозитории, URL и ответы в чат;
- токены с ограниченными правами и сроком жизни; после работ — отзываются;
- утечка (вывод на экран, скриншот, история) = немедленный отзыв в GitFlic,
  замена в .env, проверка ~/.bash_history и .git/config на остатки.

Просмотр .env ТОЛЬКО в маскированном виде (структура без значений):
sed -E 's/=(.+)$/=[скрыто]/' ~/gitflicProject/.env

Запрещены: cat .env, echo значений переменных, токены в URL команд,
немаскированные выводы и скриншоты с секретами.

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
git clone git@gitflic.ru:sokol491/archive.git ~/gitflicProject/projects/archive

## 4. HTTPS и credential helper из .env (основной канал)

Помощник: ~/gitflicProject/bin/git-credential-gitflic.sh, права 700.
Полный код помощника (v1.9.3, мульти-область):

    #!/usr/bin/env bash
    # git-credential-gitflic.sh — отдаёт git учётные данные из .env по области
    # Читает GITFLIC_HUB_* для хаба, GITFLIC_PROJECT_* для проектов
    set -u
    op="${1:-}"
    cat >/dev/null 2>&1 || true
    [ "$op" = "get" ] || exit 0
    env_file="${HOME}/gitflicProject/.env"
    [ -r "$env_file" ] || exit 0

    # Определяем контекст: хаб или проект (по пути репозитория)
    # Если путь содержит gitflicProject — это хаб, иначе проект
    repo_path="${GIT_DIR:-.}"
    if [[ "$repo_path" == *"gitflicProject"* ]]; then
        user="$(sed -n 's/^GITFLIC_HUB_USER=//p' "$env_file" | tail -n 1 | tr -d '\r')"
        token="$(sed -n 's/^GITFLIC_HUB_TOKEN=//p' "$env_file" | tail -n 1 | tr -d '\r')"
    else
        user="$(sed -n 's/^GITFLIC_PROJECT_USER=//p' "$env_file" | tail -n 1 | tr -d '\r')"
        token="$(sed -n 's/^GITFLIC_PROJECT_TOKEN=//p' "$env_file" | tail -n 1 | tr -d '\r')"
    fi

    [ -n "$token" ] || exit 0
    printf 'username=%s\npassword=%s\n' "${user:-sokol491}" "$token"

Подключение (выполнено один раз):
mkdir -p ~/gitflicProject/bin
chmod 700 ~/gitflicProject/bin/git-credential-gitflic.sh
git config --global --unset-all credential.helper
git config --global credential.https://gitflic.ru.helper '/home/sokol491/gitflicProject/bin/git-credential-gitflic.sh'

Однократная настройка идентичности (без неё commit падает с Author identity unknown):
git config --global user.name "Alexander Sokolyuk"
git config --global user.email "<почта аккаунта GitFlic>"
git config --global --get user.name && git config --global --get user.email   # проверка

Проверка помощника БЕЗ вывода токена:
printf 'protocol=https\nhost=gitflic.ru\n' | ~/gitflicProject/bin/git-credential-gitflic.sh get | sed -E 's/^(password=).*/\1[скрыто]/'

Клонирование и push по HTTPS идут без ввода пароля:
git clone https://gitflic.ru/project/sokol491/searchengine.git ~/gitflicProject/projects/searchengine

## 5. Сервис systemd хаба

Файл: ~/.config/systemd/user/gitflic-workspace.service
Ключевые строки ExecStart: php -S 0.0.0.0:8000 -t %h/gitflicProject %h/gitflicProject/router.php
Логи: StandardOutput/StandardError append в ~/gitflicProject/data/

Команды:
systemctl --user status gitflic-workspace.service --no-pager
systemctl --user restart gitflic-workspace.service
systemctl --user enable gitflic-workspace.service
sudo loginctl enable-linger $USER        # автозапуск при загрузке, до входа в сеанс
ss -tlnp | grep :8000
tail -f ~/gitflicProject/data/workspace.log
tail -f ~/gitflicProject/data/workspace-error.log

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

## 7. Жизненный цикл репозиториев и аудит

### Правило ветвления
Ветки только master: локально и на GitFlic. Перенаправление upstream не используется.
Все публикации — напрямую в master.

### Проекты контейнера projects/
Клон/обновление (репозитории клонируются в контейнер projects/):
git clone https://gitflic.ru/project/sokol491/<slug>.git ~/gitflicProject/projects/<slug>
cd ~/gitflicProject/projects/<slug> && git pull

Публикация проекта (полный цикл: коммит локальных изменений + публикация в master):
~/gitflicProject/bin/gitflic-sync.sh <slug> [-m "сообщение"]
Режимы: status [slug] (таблица состояний), fix [slug] (init/origin/контроль), probe (эндпоинты API).

**Интерактивное меню** (v1.9.2): запуск без аргументов открывает цветную таблицу проектов;
выбор — номера через запятую (1,3), all — все, q — выход.

Аудит перед паспортом (раздел 13 STANDARD.md), секреты не присылать:
for proj in archive searchengine pdf visual-novel-engine; do
  echo "======== $proj ========"
  ( cd ~/gitflicProject/projects/"$proj" \
    && find . -maxdepth 3 -not -path './.git*' -not -path './vendor/' -not -path './data/backups/' -not -name '.env*' | sort \
    && php -v | head -n 1 \
    && (ls -la data/ 2>/dev/null || echo "data/ отсутствует") \
    && { [ -f composer.json ] && cat composer.json || echo "composer.json отсутствует"; } )
done

По выводу аудита заполняется PROJECT PASSPORT (раздел 12 STANDARD.md),
факты не выдумывать, при отсутствии данных писать "нет данных".

### Обновление окружения хаба
Окружение хаба обновляется **только из репозитория sokol491** (официальный источник):
git -C ~/gitflicProject pull origin master

Проекты в projects/ могут быть от любого владельца GitFlic (любой slug/owner).

### Репозиторий хаба (gitflicProject)
Публикация хаба (полный цикл: коммит локальных изменений + публикация в master):
~/gitflicProject/bin/gitflic-sync-hub.sh [-m "сообщение"]
Состояние хаба:
~/gitflicProject/bin/gitflic-sync-hub.sh status
Подготовка к первой публикации (init, origin, .gitignore, data/.gitkeep):
~/gitflicProject/bin/gitflic-sync-hub.sh fix
URL origin хаба — только нижний регистр slug: https://gitflic.ru/project/sokol491/gitflicproject.git
Если у origin/master есть посторонняя история (например gitflic-cl.yaml, см. раздел 11),
связать без force-push: git merge origin/master --allow-unrelated-histories -X ours

## 8. Безопасность сети и прав

- Хаб слушает 0.0.0.0:8000; ufw неактивен, порт открыт всей LAN.
- Из LAN хаб закрыт HTTP Basic Auth (NETWORK_PASSWORD из .env); с 127.0.0.1 — без пароля.
- Роутер отдаёт из проектов только public/ (STANDARD.md §9); запрос /.env запрещён списком forbidden.
- Временно допускаются legacy docroot (Project/, web/) с логированием в workspace-error.log.
- Защита от path traversal: realpath() + str_starts_with() для проверки, что файл внутри docroot.
- Права: .env 600, помощник 700, sync-скрипты 700, ~/.ssh/config 600, приватный ключ 600.

## 9. Отношение к STANDARD.md

STANDARD.md — приоритетный документ: каркас, PSR-12, SQLite WAL, JSON-контракт,
kit-модули, аудит. Этот файл — эксплуатация workspace: порты, systemd, git-доступ,
интеграция в хаб. При противоречии действует STANDARD.md, владельцу предлагается
правка с changelog.

## 10. Cheat sheet

### Репозиторий хаба
~/gitflicProject/bin/gitflic-sync-hub.sh -m "docs: правки"   # публикация хаба
~/gitflicProject/bin/gitflic-sync-hub.sh status               # состояние хаба
~/gitflicProject/bin/gitflic-sync-hub.sh fix                  # подготовка к первой публикации

### Проекты контейнера projects/
git clone https://gitflic.ru/project/<owner>/<slug>.git ~/gitflicProject/projects/<slug>
git -C ~/gitflicProject/projects/<slug> pull
~/gitflicProject/bin/gitflic-sync.sh <slug> -m "sync: правки"  # публикация проекта
~/gitflicProject/bin/gitflic-sync.sh                           # интерактивное меню
~/gitflicProject/bin/gitflic-sync.sh status                    # таблица состояний
~/gitflicProject/bin/gitflic-sync.sh fix <slug>                # init/origin/контроль

### Общее
git config --global --get user.name                          # идентичность настроена?
systemctl --user restart gitflic-workspace.service
ss -tlnp | grep :8000
curl -sI http://127.0.0.1:8000/ | head -n 1
tail -f ~/gitflicProject/data/workspace-error.log

## 11. Известные проблемы и решения

- Порт 8000 занят посторонним php: ss -tlnp | grep :8000, kill <PID>, restart сервиса.
- fatal при клоне: сверить регистр slug с диалогом "Клонировать" (archive, не Archive);
  проверить ключ: ssh -vT git@gitflic.ru 2>&1 | grep -E 'Offering|Authenticated'.
- Author identity unknown при commit: настроить идентичность по разделу 4.
- bash: синтаксическая ошибка рядом с «|»: выполнена строка-шаблон "<команда> | sed ...";
  подставлять реальную команду вместо <команда>, шаблон не запускать.
- password с хвостом вида "...GITFLIC_USER=..." или склейка выводов: в .env нет перевода
  строки в конце; исправить командой раздела 2, проверить маскированным просмотром.
- Токен побывал на экране/в скриншоте/в URL: отозвать в GitFlic, выпустить новый,
  вычистить историю: sed -i '/sokol491:.*@gitflic\.ru/d' ~/.bash_history && history -c && history -w
- Проект из LAN просит пароль хаба: это ожидаемое поведение Basic Auth.
- Legacy docroot: проект отдаёт 404, в error.log строка "legacy docroot ... требуется миграция на public/".
  Решение: перенести содержимое legacy-каталога (Project/, web/) в public/, обновить includes/ на относительные пути.
- HTTP 000 при curl: сервис не запустился или порт занят; проверить systemctl --user status, ss -tlnp | grep :8000.
- GitFlic: регистрозависимость slug (ФАКТ 2026-09-29): git-транспорт при неверном регистре отвечает
  HTTP 500/"not found", Web-UI открывает оба написания. Slug хаба: gitflicproject (нижний регистр);
  origin хаба: https://gitflic.ru/project/sokol491/gitflicproject.git. Регистр сверять с диалогом "Клонировать".
- GitFlic: HTTP 500 на несуществующий репозиторий: git-транспорт возвращает 500 вместо 404;
  скрипты парсят вывод ls-remote и трактуют как "репозитория нет".
- GitFlic: gitflic-cl.yaml (ФАКТ 2026-09-29): при создании репозитория через UI добавляется CI-файл;
  если CI не используется — удалить через UI до первого push; истории связать
  git merge origin/master --allow-unrelated-histories -X ours (без force-push, guide раздел 4).
- Защиты staging sync-скриптов: gitflic-sync.sh запрещает только КОРНЕВЫЕ data/, storage/, vendor/
  (public/data/ разрешён); gitflic-sync-hub.sh запрещает .env, data/ (кроме .gitkeep), projects/,
  логи и служебные файлы SQLite на любой глубине; нарушение = код выхода 5, коммит отменён.
- Создание репозиториев через API не подтверждено (probe: 404/302) — создавать через Web-UI.

## 12. Ресурсы

- Профиль GitFlic: https://gitflic.ru/user/sokol491
- Репозиторий хаба: https://gitflic.ru/project/sokol491/gitflicproject
- Хаб: http://192.168.31.119:8000/
- Локально: http://127.0.0.1:8000/

## 13. Web-scraping: blob/tree GitFlic без токена

Для публичных репозиториев GitFlic отдаёт содержимое файлов через web_extractor
без авторизации, если использовать правильный формат URL.

Приоритет: ИСХОДНЫЙ КОД файла получаем по /blob/raw (чистый текст);
blob HTML — вспомогательный (просмотр и получение ссылки Raw с commit=<SHA>).

### Форматы URL (проверено 2026-09-29 на sokol491/archive, sokol491/visual-novel-engine):

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

### Применение:
- Исходный код любого файла публичного репозитория — через /blob/raw.
- Аудит структуры проекта до локального клона (раздел 13 STANDARD.md).
- Получение содержимого ключевых файлов (README.md, config.php, index.php).
- Проверка соответствия каркасу (STANDARD.md §3) без скачивания всего репозитория.
- Быстрый просмотр кода для формирования PROJECT PASSPORT.

### Ограничения:
- Только публичные репозитории.
- Скорость: 1-3 файла за запрос (web_extractor может объединять URL).
- Для приватных репозиториев — только локальный клон с credential helper (раздел 4).
- Секреты в коде (.env, токены в конфиге) не выводить в чат — только констатировать наличие.
- /blob/raw требует commit SHA, не работает с именами веток.

### Алгоритм получения файла (ОБЯЗАТЕЛЕН до любого вопроса пользователю):
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
   cd ~/gitflicProject/projects/<slug> && find . -maxdepth 1 -iname 'readme*' -exec cat {} +
7. Только после этого — просить вставку текста (STANDARD.md §1).

### Типичные ошибки (ЗАПРЕЩЁННЫЕ подмены):
- Выдумывать локальные пути: единственно верный — ~/gitflicProject/projects/<slug> (§1).
- GitLab-синтаксис: /api/v4/..., заголовок PRIVATE-TOKEN — в GitFlic не существует;
  верно: https://api.gitflic.ru/project/<user>/<slug>/blob?commitHash=<SHA>&file=<путь>,
  заголовок: Authorization: token <GITFLIC_TOKEN из .env>.
- GitHub-формат /blob/<ветка>/<путь> (запрещён выше) — не предлагать пользователю как рабочий.
- Спрашивать у пользователя владельца, slug, ссылку или локальный путь,
  если они выводятся из §1/§12; вопрос допустим только после шагов 1-5 алгоритма.
- Отдавать пользователю blob HTML как «исходный код», когда доступен /blob/raw.

---

Конец документа.

**Changelog:**
- 2026-09-25: v1.3 — контейнер projects/, миграция репозиториев, docroot public/.
- 2026-09-29: v1.4 — раздел 13: web-scraping blob/tree GitFlic без токена.
- 2026-09-29: v1.5 — раздел 13: алгоритм получения файла до вопроса пользователю.
- 2026-09-29: v1.6 — раздел 13: добавлен формат /blob/raw для чистого текста файла.
- 2026-09-29: v1.7 — раздел 13: блок типичных ошибок.
- 2026-09-29: v1.8 — раздел 13: /blob/raw — основной способ получения исходного кода.
- 2026-09-29: v1.9 — раздел 1: bin/gitflic-sync-hub.sh, README.md, хаб как git-репозиторий; раздел 7: публикация хаба; раздел 10: команды хаба; раздел 11: регистрозависимость slug, HTTP 500, gitflic-cl.yaml, защиты staging; раздел 12: ссылка на репозиторий хаба.
- 2026-09-30: v1.9.1 — раздел 7: удалены упоминания ветки qwen и модели перенаправления upstream; зафиксировано правило «только master: локально и на GitFlic».
- 2026-10-01: v1.9.2 — раздел 2: переменная GIT_HOST в .env (мульти-хост); раздел 7: клонирование репозиториев любого владельца; раздел 10: интерактивное меню gitflic-sync.sh с цветовым оформлением; README: раздел «Установка» для новых устройств и новых пользователей GitFlic.
- 2026-10-01: v1.9.3 — раздел 2: новая схема .env с разделением токенов по областям (HUB/PROJECT) и заделом на Vercel; раздел 4: обновлён код credential helper (автоматический выбор HUB/PROJECT токена по контексту репозитория); раздел 7: подраздел «Обновление окружения» (только из репозитория sokol491).
- 2026-10-01: v2.0 — переименование в GitProject Workspace Hub (ТЗ-001 v2, этап 0);
  мульти-хост credential helper (GitFlic/GitHub/GitLab/GitVerse); bin/lib/common.sh;
  config/hosts.json; схема .env HUB/PROJECT; симлинки совместимости для старых имён скриптов;
  исправлен баг stdin в helper (cat >/dev/null съедал запрос git).
