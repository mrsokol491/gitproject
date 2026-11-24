# GitProject Workspace Hub

Централизованный хаб для управления, проксирования и изоляции проектов Git.

Владелец: Александр (@sokol491)
Назначение: универсальная оболочка-хаб для работы с репозиториями на нескольких Git-хостах:
+ ✅ GitVerse (gitverse.ru) — платформа от Сбера (реестр ПО РФ)
+ ✅ GitFlic (gitflic.ru) — платформа от Астра (реестр ПО РФ)
+ ✅ SourceCraft (sourcecraft.dev) — платформа от Яндекса (реестр ПО РФ)
+ ✅ GitHub (github.com) — международный Git-хостинг (в процессе)
+ ⚠️ CodeBase (codebase.mos.ru) — московская платформа разработки (в процессе)
+ ⚠️ Mos.hub (Mos.hub) — Московская облачная платформа с ИИ-ассистентом «КоДИТ» и антивирусным сканированием. (в процессе)
+ ⚠️ GitLab (gitlab.com) — международный Git-хостинг (в процессе)
+ ⚠️ Bitbucket (bitbucket.org) — международный Git-хостинг (в процессе)
+ ⚠️ Gitea (gitea.com) — международный Git-хостинг (в процессе)
+ ⚠️ Forgejo (forgejo.org) — Активно развивающийся community-fork (ответвление) от Gitea (в процессе)


    + ✅ - Интегрирован
    + ⚠️ - В процессе интеграции

- индекс проектов, прокси-роутер в docroot проектов, скрипты синхронизации/публикации/аудита, credential helper.

- **Основной репозиторий:** https://gitverse.ru/Sokol491/gitproject
- **Зеркало:** https://gitflic.ru/project/sokol491/gitproject
- Локальный путь: ~/gitproject

Правила разработки: STANDARD.md (приоритетный документ).
Эксплуатация: gitproject-workspace-guide.md (порты, systemd, git-доступ, changelog).

## Установка (новое устройство / новый пользователь)

Требования: PHP 8.1+, git 2.30+, curl, systemd (опционально).

1. Клонирование хаба (из GitVerse)
```
       git clone https://gitverse.ru/Sokol491/gitproject.git ~/gitproject
       cd ~/gitproject
```
2. Создать .env из шаблона и вписать свои значения
```
       cp .env.example .env 2>/dev/null || true
       chmod 600 .env
       # Основные настройки GitVerse
       GIT_HOST=gitverse.ru
       GITVERSE_HUB_USER=Sokol491
       NETWORK_PASSWORD=<пароль>
```
3. Права на скрипты и подключение credential helper
```
       chmod 700 bin/git-credential-multi.sh bin/git-project-*.sh
       git config --global credential.helper "$HOME/gitproject/bin/git-credential-multi.sh"
       git config --global user.name "Ваше Имя"
       git config --global user.email "ваш_email@example.com"
```
4. Проверка helper (токен маскируется)
```
       printf 'protocol=https\nhost=gitverse.ru\npath=Sokol491/gitproject.git\n' \
         | bin/git-credential-multi.sh get | sed -E 's/^(password=).*/\1[скрыто]/'
```
5. Запуск хаба
```
       php -S 0.0.0.0:8000 -t ~/gitproject ~/gitproject/router.php
```
  или через systemd:
```
       systemctl --user enable --now gitproject-workspace.service
       sudo loginctl enable-linger $USER
```
6. Клонирование проектов в контейнер
```
       git clone https://gitverse.ru/<owner>/<slug>.git ~/gitproject/projects/<slug>
       ~/gitproject/bin/git-project-sync.sh fix <slug>
```
Обновление окружения хаба — только из репозитория Sokol491 на GitVerse:
```
    ~/gitproject/bin/git-project-pull.sh
```
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

### Credential helper (bin/git-credential-multi.sh)

Вызывается только git, вручную не нужен. Проверка БЕЗ вывода токена:

    printf 'protocol=https\nhost=gitverse.ru\n' \
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
- Защита от PHP2Shell (CVE-2026-109618/9):
  - Обновить PHP до патчной версии (>= 8.1.33, 8.2.25, 8.3.12, 8.4.0+).
  - Настроить `disable_functions` и `open_basedir` в php.ini.
- Из LAN хаб закрыт HTTP Basic Auth; с 127.0.0.1 — без пароля.
- Роутер: forbidden-список (.env, bin/, data/, projects/, *.md), защита path
  traversal (realpath + проверка префикса docroot).

## Логи

data/workspace.log, data/workspace-error.log, data/<host>.log — в репозиторий не попадают.