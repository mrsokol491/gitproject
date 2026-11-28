# GitProject Workspace Hub

Централизованный хаб для управления, проксирования и изоляции проектов Git.

Владелец: Александр (@sokol491)
Назначение: универсальная оболочка-хаб для работы с репозиториями на нескольких Git-хостах:
+ ✅ GitVerse (gitverse.ru) — платформа от Сбера (реестр ПО РФ)
+ ✅ GitFlic (gitflic.ru) — платформа от Астра (реестр ПО РФ)
+ ✅ SourceCraft (sourcecraft.dev) — платформа от Яндекса (реестр ПО РФ)
+ ✅ Mos.hub (hub.mos.ru) — Московская облачная платформа с ИИ-ассистентом «КоДИТ» и антивирусным сканированием (реестр ПО РФ)
+ ✅ GitHub (github.com) — международный Git-хостинг
+ ✅ GitLab (gitlab.com) — международный Git-хостинг
+ ✅ Сodeberg (codeberg.org) — международный Git-хостинг
+ ✅ AltLinux Space (altlinux.space) — платформа от Alt Linux (реестр ПО РФ)
+ ✅ Gitea (gitea.com) — международный Git-хостинг
+ ✅ Forgejo (Forgejo.org) — международный Git-хостинг
+ ❌ GOSH (gosh.sh) — Git Open Source Hodler, международный Git-хостинг
+ ❌ Bitbucket (bitbucket.org) — международный Git-хостинг
+ ⚠️ КОД'ОК (kodok.dev) — Российский Git-хостинг
+ ⚠️ AppSec.Code (appsec.global/appseccode) — Российский Git-хостинг
+ ⚠️ Fenix (РТК) — (rtk-market.ru/product/fenix) — Российский Git-хостинг
+ ⚠️ КОД (Сфера) — (sferaplatform.ru/kod) — Российский Git-хостинг


    + ✅ - Интегрирован
    + ⚠️ - В процессе интеграции
    + ❌ - На данный момент интеграция не планируется

**Локальный путь:**
+ ~/gitproject

**Основной репозиторий:** 
+ ✅ GitVerse: https://gitverse.ru/Sokol491/gitproject

**Список зеркал:** 
+ ✅ GitFlic: https://gitflic.ru/project/sokol491/gitproject
+ ✅ SourceCraft: https://sourcecraft.dev/sokol491/gitproject
+ ✅ GitHub: https://github.com/mrsokol491/gitproject
+ ✅ Mos.hub: https://hub.mos.ru/mrsokol491/gitproject
+ ✅ GitLab: https://gitlab.com/sokol491/gitproject
+ ✅ Сodeberg: https://codeberg.org/mrsokol491/gitproject
+ ✅ Gitea: https://gitea.com/mrsokol491/gitproject
+ ✅ AltLinux Space: https://altlinux.space/mrsokol491/gitproject
+ ✅ Forgejo: https://v17.next.forgejo.org/sokol491/gitproject

## Установка (новое устройство / новый пользователь)

Требования: PHP 8.3+, git 2.30+, curl, systemd (опционально).

1. Клонирование хаба (из GitVerse)
```
git clone https://gitverse.ru/Sokol491/gitproject.git ~/gitproject
cd ~/gitproject
```
2. Создать .env из шаблона и вписать свои значения
```
cp .env.example .env 2>/dev/null || true
chmod 600 .env
```
3. Права на скрипты и подключение credential helper
```
chmod 700 bin/lib/git-credential-multi.sh bin/gitproject-*.sh
git config --global credential.helper "$HOME/gitproject/bin/lib/git-credential-multi.sh"
git config --global user.name "Ваше Имя"
git config --global user.email "ваш_email@example.com"
```
4. Проверка helper (токен маскируется)
```
printf 'protocol=https\nhost=gitverse.ru\npath=Sokol491/gitproject.git\n' \
| bin/lib/git-credential-multi.sh get | sed -E 's/^(password=).*/\1[скрыто]/'
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
~/gitproject/bin/project/gitproject-sync.sh fix <slug>
```
Обновление окружения хаба — только из репозитория Sokol491 на GitVerse:
```
~/gitproject/bin/project/gitproject-pull.sh
```
## Порты и URL

- 8000 — хаб (systemd: gitproject-workspace.service)
- http://127.0.0.1:8000/ — хаб
    http://127.0.0.1:8000/< slug >/ — проект

## Команды управления

Все скрипты в bin/ требуют права 700. Токены подставляются из .env через
credential helper; в выводе команд маскируются ([скрыто]).

### Интерактивная синхронизация проектов (bin/project/gitproject-sync.sh)

Запуск без аргументов открывает меню: нумерованный список проектов с цветным
состоянием; выбор — номера через запятую (1,3), all — все, q — выход.

    ~/gitproject/bin/project/gitproject-sync.sh                 # интерактивное меню
    ~/gitproject/bin/project/gitproject-sync.sh <slug>          # один проект без меню
    ~/gitproject/bin/project/gitproject-sync.sh <slug> -m "сообщение"
    ~/gitproject/bin/project/gitproject-sync.sh status          # таблица состояний
    ~/gitproject/bin/project/gitproject-sync.sh fix <slug>      # init/origin/контроль

### Мульти-хост система

    ~/gitproject/bin/project/gitproject-clone.sh                # мастер клонирования с выбором хоста
    ~/gitproject/bin/project/gitproject-remote.sh <slug> --list # remote + актуальность
    ~/gitproject/bin/project/gitproject-remote.sh <slug> --add <domain>

### Credential helper (bin/lib/git-credential-multi.sh)

Вызывается только git, вручную не нужен. Проверка БЕЗ вывода токена:

    printf 'protocol=https\nhost=gitverse.ru\n' \
      | ~/gitproject/bin/lib/git-credential-multi.sh get \
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
  - Обновить PHP до патчной версии (>= 8.3.12, 8.4.0+).
  - Настроить `disable_functions` и `open_basedir` в php.ini.
- Из LAN хаб закрыт HTTP Basic Auth; с 127.0.0.1 — без пароля.
- Роутер: forbidden-список (.env, bin/, data/, projects/, *.md), защита path
  traversal (realpath + проверка префикса docroot).

## Логи

data/workspace.log, data/workspace-error.log, data/<host>.log — в репозиторий не попадают.
## Лицензия

Apache License 2.0 — полный текст в [LICENSE](LICENSE).
Выбор обусловлен упором на российское ПО: лицензия принята в РФ-практике
(Яндекс, Сбер, VK), даёт явную патентную защиту и совместима с GPL.

## Документация скриптов

`doc/` — инструкция по каждому `bin/*.sh`: все флаги, режимы, коды подтверждения,
ограничения и примеры вызова. Оглавление: `doc/README.md`.
