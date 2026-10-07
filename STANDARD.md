# STANDARD.md — единый стандарт проектов GitProject
Версия 1.3 от 2026-09-29. Владелец: Александр (@sokol491).
Область: все репозитории и новые проекты. Файл универсален: не ссылается на
память, диалоги и конкретные проекты — только на правила и готовые модули (раздел 11).

1. Коммуникация и рабочий процесс
Отвечать по-русски, кратко, по делу; код — полными блоками, с комментариями и примером запуска.
Пользователь передаёт файлы/код полным текстом; правки возвращаются полными файлами.
GitFlic: прямое скачивание архивов и репозиториев без авторизации запрещено;
рабочая схема — пользователь вставляет, ассистент правит.
Допускается web-scraping содержимого отдельных файлов публичных репозиториев через
blob/tree URL с query-параметрами для аудита структуры и ключевых файлов
(детали — gitproject-workspace-guide.md §13). Поддерживается /blob/raw для чистого текста
(требует commit hash). GitHub-формат /blob/<ветка>/<путь> не работает.
Токены и секреты: только с ограниченными правами и сроком жизни; никогда в коде, репозитории и ответах.

2. PHP и код
PHP 8.1+, declare(strict_types=1); в каждом файле.
PSR-12, PSR-4, Composer обязателен даже без зависимостей.
Namespace: App\ → src/.
Слои: Controller (тонкий) → Service (логика) → Repository (данные).
UTF-8 везде, включая кириллицу в PDF.
Запрещены var_dump/print_r/отладочный echo; ошибки — исключения + логи.
Минимальный composer.json:
{
    "require": {
        "php": ">=8.1",
        "ext-pdo": "*",
        "ext-sqlite3": "*"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5",
        "phpstan/phpstan": "^1.11"
    },
    "autoload": {
        "psr-4": { "App\\": "src/" }
    },
    "scripts": {
        "test": "phpunit",
        "stan": "phpstan analyse src"
    }
}
Запуск: composer install && composer test && composer stan

3. Каркас репозитория (обязателен)
repo/
 ├── bin/                    # CLI-скрипты: snake_case.php (backup.php, import.php)
 ├── config/
 │   └── config.php          # конфигурация без секретов
 ├── data/                   # приватно, игнорируется git (кроме .gitkeep)
 │   ├── .gitkeep
 │   ├── backups/            # VACUUM INTO + <имя>.meta.json (sha256)
 │   ├── logs/               # app.log, error.log, security.log
 │   └── cache/
 ├── docs/                   # architecture.md, api.md, migration.md + доменные
 ├── migrations/             # YYYYMMDD_HHMMSS_snake_name.php, хранятся в репозитории
 ├── public/                 # ЕДИНСТВЕННАЯ публичная директория (docroot)
 │   ├── index.php
 │   ├── router.php          # защитный роутер (php -S, dev)
 │   ├── admin/              # страницы админки
 │   ├── api/
 │   │   ├── user/           # эндпоинты snake_case.php
 │   │   ├── admin/
 │   │   └── internal/       # batch/progress/cancel для клиентских циклов
 │   ├── assets/
 │   │   ├── css/            # kebab-case.css
 │   │   └── js/             # kebab-case.js, ES-модули
 │   └── media/              # ТОЛЬКО файлы, предназначенные для публичного доступа
 ├── src/                    # App\ (PSR-4)
 │   ├── bootstrap.php
 │   ├── Controller/         # <Name>Controller.php — тонкие
 │   ├── Service/            # <Name>Service.php — логика
 │   ├── Repository/         # <Name>Repository.php — данные
 │   ├── Entity/             # <Name>.php
 │   ├── Kit/                # копии готовых модулей, если нет composer-пакета
 │   └── Support/            # вспомогательные классы
 ├── storage/                # опционально: пользовательские файлы и индексы (git игнорирует)
 │   └── .gitkeep
 ├── tests/
 │   ├── Unit/               # <Class>Test.php
 │   └── Integration/
 ├── .env.example
 ├── .gitignore
 ├── composer.json
 ├── phpunit.xml
 ├── phpstan.neon
 └── README.md
Пустые каталоги держим через .gitkeep; содержимое data/ и storage/ в репозиторий не попадает.
PHP-код вне src/, bin/, migrations/, public/ запрещён.
Данные, конфиги, логи, бэкапы внутри public/ запрещены.
.gitignore (шаблон):
# Зависимости
/vendor/
# Приватные данные (раздел 3): в репозиторий не попадают
/data/*
!/data/.gitkeep
/storage/*
!/storage/.gitkeep
# Секреты и логи
.env
*.log
# Служебные файлы SQLite
*.sqlite
*.sqlite-journal
*.sqlite-wal
*.sqlite-shm
Проверка после git add -A: git status --short — нет строк с data/, .env, vendor/.

4. Стандарт имён файлов и папок
PHP-классы: PascalCase, один класс на файл, имя файла = имени класса; каталоги src/ — PascalCase по namespace.
CLI-скрипты и API-эндпоинты: snake_case.php.
Ассеты (css/js/изображения/docs-файлы): kebab-case.
Миграции: migrations/YYYYMMDD_HHMMSS_snake_name.php; идемпотентные, бэкап перед применением.
Бэкапы: data/backups/YYYYMMDD_HHMMSS.sqlite + YYYYMMDD_HHMMSS.meta.json (sha256, размер, версия схемы).
Логи: data/logs/app.log, error.log, security.log; ротация при > 5 МБ или смене даты.
Технические имена пользовательских файлов: <32 hex>.<ext>; оригинальное имя, mime, checksum — в БД.
Переменные окружения: UPPER_SNAKE_CASE в .env; ключи конфига: section.key (lowercase).
Поля JSON: snake_case; error_code: UPPER_SNAKE_CASE.
Тесты: tests/Unit/<Class>Test.php, tests/Integration/<Class>Test.php.

5. JSON-контракт API
Успех: {"ok": true, "data": {...}}
Ошибка: {"ok": false, "error": "Сообщение", "error_code": "CODE"}
HTTP: 400 валидация, 401/403 доступ, 404 не найдено, 503 недоступно, 500 сбой.

6. Данные и БД
Локально: SQLite через PDO; PRAGMA journal_mode=WAL; busy_timeout=30000; synchronous=NORMAL.
SQLite ≥ 3.27 (нужен для VACUUM INTO). Проверка: php -r "echo SQLite3::version()['versionString'], PHP_EOL;"
Миграции версионируемые и идемпотентные; бэкап перед миграцией.
Бэкапы: VACUUM INTO в data/backups/, мета с sha256, restore через режим обслуживания (флаг-файл).
Поиск/масштаб: SQLite FTS5 или MySQL FULLTEXT для простого; Meilisearch/Elasticsearch для масштаба.
Секреты только в .env (в репозитории — .env.example); пароли только password_hash().

7. Фоновые операции
Шаблон: job → items → batch (лимит) → progress → cancel.
Домашний режим: без демонов; batch запускается клиентским циклом (public/api/internal/) или cron (bin/).
Тяжёлые ФС-операции вне пишущих транзакций; retry при SQLITE_BUSY.

8. Логирование
data/logs/: app.log, error.log, security.log.
Обязательно логировать: входы и неудачи, изменения и удаления данных, импорт/экспорт, бэкапы/restore, операции с носителями.

9. Безопасность
docroot = public/; прямой доступ к data/, config/, src/, tests/, bin/, migrations/, vendor/, *.ged, .sqlite, .log, .env запрещён (router.php / .htaccess / nginx location).
Приватные файлы отдаются только эндпоинтом с проверкой реестра и статуса.
Login: rate-limit, lockout 5 попыток / 15 минут, аудит в security.log.
Загрузка: проверка размера и типа, техническое имя hex+ext, оригинал и checksum в БД.
Вывод экранировать (textContent/escape), innerHTML с пользовательскими данными запрещён; CSP: default-src 'self'; script-src 'self'.
HTTP в LAN: риск зафиксирован; TLS при возможности.

10. Фронтенд
Сервер: данные, API, безопасность, рендеринг страниц; браузер: интерактив.
Данные — JSON, а не исполняемые .js; новые window.*-глобалы запрещены (ES-модули).
Серверный кэш в data/cache/; клиентский кэш только для некритичного.

11. Готовые модули (kit)
Модуль — самодостаточный блок кода с тестами и MODULE.md (semver), не знающий о проекте.
Подключение: (а) composer-пакет из отдельного репозитория, namespace Kit\ — предпочтительно;
(б) копия в src/Kit/<Module>/ со сменой namespace на App\Kit<Module>\ и фиксацией версии в docs.
Базовый каталог:
kit/http-json: JsonResponse (контракт раздела 5), шаблон защитного router.php.
kit/db-sqlite: PDO-обёртка с PRAGMA (WAL, busy_timeout, synchronous), runner миграций, хелпер FTS5.
kit/jobs: JobRunner job→items→batch→progress→cancel + шаблоны internal-эндпоинтов.
kit/log: Logger app/error/security + ротация.
kit/security: Auth (password_hash, сессии), RateLimiter (5/15), аудит в security.log.
kit/backup: BackupManager (VACUUM INTO, sha256-мета, режим обслуживания).
kit/config: Config (config.php + .env, UPPER_SNAKE_CASE).
kit/file-registry: реестр файлов (техимена, checksum, mime), эндпоинт выдачи приватных файлов.
Доменные модули (подключаются по необходимости):
kit/pdf: извлечение текста (smalot/pdfparser), генерация (tcpdf/dompdf), UTF-8; большие документы — yield/стриминг через kit/jobs.
kit/search: SearchAdapter с единым интерфейсом: SQLite FTS5 / MySQL FULLTEXT / Meilisearch.
kit/gedcom: серверный парсер GEDCOM в БД; .ged не публикуется.
kit/novel: схема и валидатор novel.json; API novels/save/load.
kit/drive-pool: пул съёмных носителей (UUID в .cloud-drive.json, статусы, drain/optimize через kit/jobs).
Правила: модуль не ссылается на проект; вход — через Config и интерфейсы; тесты модуля обязательны;
новый модуль добавляется в каталог только с MODULE.md и версией.

12. Паспорт проекта
При подключении/аудите любого проекта заполнять PROJECT PASSPORT, разделы:
0 источник; 1 назначение; 2 стек; 3 структура; 4 точки входа; 5 модули;
6 хранилище; 7 безопасность; 8 соглашения; 9 решения; 10 что стандартизировать;
11 открытые вопросы; 12 что нужно от пользователя.
Факты не выдумывать — писать «нет данных».

13. Аудит (выполнять в корне проекта)
find . -maxdepth 3 -not -path '/.git' -not -path './vendor/' -not -path './data/backups/' -not -name '.env*' | sort
php -v; ls -la data/; [ -f composer.json ] && cat composer.json
Секреты, хэши и токены не присылать.

14. Изменения стандарта
Новые решения добавлять в этот файл датой (changelog ниже); молча противоречить файлу запрещено.

Changelog:
2026-09-23: v1.0 принят по итогам аудитов; правки ревью (.gitignore, require-dev, SQLite ≥ 3.27) вошли до загрузки.
2026-09-23: v1.1 — файл универсализирован (без ссылок на проекты и память); добавлен раздел 4 (имена файлов и папок), раздел 11 (готовые модули kit), каталог migrations/ в каркасе.
2026-09-29: v1.2 — раздел 1: допустимость web-scraping blob/tree GitFlic для аудита публичных репозиториев (детали в gitproject-workspace-guide.md §13).
2026-09-29: v1.3 — раздел 1: добавлено упоминание /blob/raw для чистого текста файла (детали в gitproject-workspace-guide.md §13).
## Changelog
- 2026-10-01: v1.4 — рабочее пространство переименовано: GitProject Workspace Hub
  (прежнее имя GitFlic Dev Workspace, ТЗ-001 v2, этап 0). Путь ~/gitproject,
  slug репозитория на GitFlic (gitproject) и URL хаба сохранены для совместимости;
  старые имена скриптов bin/ работают через симлинки.
2026-10-07: v1.5 — локальный путь обновлён: ~/gitflicProject → ~/gitproject (завершение
переименования, Фаза 2 ТЗ-001 v2). Репозиторий на GitFlic переименован в gitproject;
все скрипты bin/, systemd-unit (gitproject-workspace.service) и внутренние пути
обновлены. Симлинки gitflic-*.sh в bin/ сохранены для обратной совместимости.
2026-10-07: v1.5 — полное переименование рабочего пространства (Фаза 2 ТЗ-001 v2):
локальный путь ~/gitflicProject → ~/gitproject; systemd unit gitflic-workspace.service →
gitproject-workspace.service; репозиторий на GitFlic sokol491/gitflicproject → sokol491/gitproject;
все упоминания в документации обновлены; симлинки gitflic-*.sh в bin/ сохранены для
обратной совместимости.
2026-10-07: v1.6 — симлинки gitflic-*.sh в bin/ удалены (обратная совместимость имён
завершена, рабочие имена — только git-project-*.sh); руководство переименовано:
gitflic-workspace-guide.md → gitproject-workspace-guide.md; строка про порт 8080
и строки «Прежнее имя» удалены из документации; forbidden-список router.php обновлён.
