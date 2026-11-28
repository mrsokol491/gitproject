# GitProject Workspace Hub
> Централизованный хаб для управления, проксирования и изоляции проектов Git.

![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue)
![Git](https://img.shields.io/badge/Git-2.30%2B-orange)
![License](https://img.shields.io/badge/License-Apache%202.0-green)

**Владелец:** Александр (@sokol491)

## 📌 О проекте
Универсальная оболочка-хаб для работы с репозиториями на нескольких Git-хостах. Поддерживает российские и международные платформы, включая GitVerse, GitFlic, SourceCraft, Mos.hub, GitHub, GitLab и другие.

**Локальный путь:**
+ ~/gitproject


## 🚀 Быстрый старт
**Требования:** PHP 8.3+, git 2.30+, curl, systemd (опционально).

**Локальный путь:** `~/gitproject`

**Основной репозиторий:** ✅ [GitVerse](https://gitverse.ru/Sokol491/gitproject)

### 1. Клонирование хаба (из GitVerse)
```bash
git clone https://gitverse.ru/Sokol491/gitproject.git ~/gitproject
cd ~/gitproject
```

### 2. Создать `.env` из шаблона и вписать свои значения
```bash
cp .env.example .env 2>/dev/null || true
chmod 600 .env
```

### 3. Права на скрипты и подключение credential helper
```bash
chmod 700 bin/git-credential-multi.sh bin/git-project-*.sh
git config --global credential.helper "$HOME/gitproject/bin/git-credential-multi.sh"
git config --global user.name "Ваше Имя"
git config --global user.email "ваш_email@example.com"
```

### 4. Проверка helper (токен маскируется)
```bash
printf 'protocol=https\nhost=gitverse.ru\npath=Sokol491/gitproject.git\n' \
  | bin/git-credential-multi.sh get | sed -E 's/^(password=).*/\1[скрыто]/'
```

### 5. Запуск хаба
```bash
php -S 0.0.0.0:8000 -t ~/gitproject ~/gitproject/router.php
```
Или через systemd:
```bash
systemctl --user enable --now gitproject-workspace.service
sudo loginctl enable-linger $USER
```

### 6. Клонирование проектов в контейнер
```bash
git clone https://gitverse.ru//.git ~/gitproject/projects/
~/gitproject/bin/git-project-sync.sh fix
```

**Обновление окружения хаба** — только из репозитория Sokol491 на GitVerse:
```bash
~/gitproject/bin/git-project-pull.sh
```

<details>
<summary>Сервис хаба (systemd)</summary>

```bash
systemctl --user status gitproject-workspace.service --no-pager
systemctl --user restart gitproject-workspace.service
systemctl --user enable gitproject-workspace.service
sudo loginctl enable-linger $USER
```

</details>

<details>
<summary>Диагностика</summary>

```bash
ss -tlnp | grep :8000
curl -sI http://127.0.0.1:8000/ | head -n 1
tail -n 20 ~/gitproject/data/workspace.log
tail -n 20 ~/gitproject/data/workspace-error.log
```

</details>


## 🧩 Статус интеграций
<details>
<summary>Показать полный список платформ и статусов</summary>

| Платформа | Статус |
|-----------|--------|
| GitVerse (gitverse.ru) — платформа от Сбера (реестр ПО РФ) | ✅ Интегрирована |
| GitFlic (gitflic.ru) — платформа от Астра (реестр ПО РФ) | ✅ Интегрирована |
| SourceCraft (sourcecraft.dev) — платформа от Яндекса (реестр ПО РФ) | ✅ Интегрирована |
| Mos.hub (hub.mos.ru) — Московская облачная платформа с ИИ-ассистентом «КоДИТ» и антивирусным сканированием (реестр ПО РФ) | ✅ Интегрирована |
| GitHub (github.com) — международный Git-хостинг | ✅ Интегрирована |
| GitLab (gitlab.com) — международный Git-хостинг | ✅ Интегрирована |
| Codeberg (codeberg.org) — международный Git-хостинг | ✅ Интегрирована |
| AltLinux Space (altlinux.space) — платформа от Alt Linux (реестр ПО РФ) | ✅ Интегрирована |
| Gitea (gitea.com) — международный Git-хостинг | ✅ Интегрирована |
| GOSH (gosh.sh) — Git Open Source Hodler, международный Git-хостинг | ❌ Не планируется |
| Bitbucket (bitbucket.org) — международный Git-хостинг | ❌ Не планируется |
| КОД'ОК (kodok.dev) — Российский Git-хостинг | ⚠️ В процессе |
| AppSec.Code (appsec.global/appseccode) — Российский Git-хостинг | ⚠️ В процессе |
| Fenix (РТК) — (rtk-market.ru/product/fenix) — Российский Git-хостинг | ⚠️ В процессе |
| КОД (Сфера) — (sferaplatform.ru/kod) — Российский Git-хостинг | ⚠️ В процессе |

**Обозначения:**
+ ✅ 🇷🇺 GitVerse (gitverse.ru) — платформа от Сбера
+ ✅ 🇷🇺 GitFlic (gitflic.ru) — платформа от Астра
+ ✅ 🇷🇺 SourceCraft (sourcecraft.dev) — платформа от Яндекса
+ ✅ 🇷🇺 Mos.hub (hub.mos.ru) — Московская облачная платформа с ИИ-ассистентом «КоДИТ» и антивирусным сканированием
+ ✅ 🇷🇺 AltLinux Space (altlinux.space) — платформа от Alt Linux
+ ✅ 🌐 GitHub (github.com)
+ ✅ 🌐 GitLab (gitlab.com)
+ ✅ 🌐 Сodeberg (codeberg.org)
+ ✅ 🌐 Gitea (gitea.com)
+ ✅ 🌐 Forgejo (Forgejo.org)
+ ❌ 🌐 GOSH (gosh.sh) — Git Open Source Hodler
+ ❌ 🌐 Bitbucket (bitbucket.org)
+ ⚠️ 🇷🇺 КОД'ОК (kodok.dev)
+ ⚠️ 🇷🇺 AppSec.Code (appsec.global/appseccode)
+ ⚠️ 🇷🇺 Fenix (РТК) — (rtk-market.ru/product/fenix)
+ ⚠️ 🇷🇺 КОД (Сфера) — (sferaplatform.ru/kod)


    + ✅ - Интегрирован
    + ⚠️ - В процессе интеграции
    + ❌ - На данный момент интеграция не планируется
    + 🇷🇺 - Российский Git-хостинг
    + 🌐 - международный Git-хостинг

</details>

## 🔗 Зеркала проекта
**Основной репозиторий:** ✅ [GitVerse](https://gitverse.ru/Sokol491/gitproject)

<details>
<summary>Показать все зеркала (список постоянно пополняется)</summary>

- ✅ [GitFlic](https://gitflic.ru/project/sokol491/gitproject)
- ✅ [SourceCraft](https://sourcecraft.dev/sokol491/gitproject)
- ✅ [GitHub](https://github.com/mrsokol491/gitproject)
- ✅ [Mos.hub](https://hub.mos.ru/mrsokol491/gitproject)
- ✅ [GitLab](https://gitlab.com/sokol491/gitproject)
- ✅ [Codeberg](https://codeberg.org/mrsokol491/gitproject)
- ✅ [Gitea](https://gitea.com/mrsokol491/gitproject)
- ✅ [AltLinux Space](https://altlinux.space/mrsokol491/gitproject)
- ✅ [Forgejo](https://v17.next.forgejo.org/sokol491/gitproject)

</details>

## 🌐 Порты и URL
- `8000` — хаб (systemd: `gitproject-workspace.service`)
- [http://127.0.0.1:8000/](http://127.0.0.1:8000/) — хаб
- [http://127.0.0.1:8000/](http://127.0.0.1:8000/)< slug >/ — проект

## 🛠 Команды управления
Все скрипты в `bin/` требуют права `700`. Токены подставляются из `.env` через credential helper; в выводе команд маскируются (`[скрыто]`).

## 🔒 Безопасность
- Секреты только в `.env` (600); маскированный просмотр: `sed -E 's/=(.+)$/=[скрыто]/' ~/gitproject/.env`
- Токены: ограниченные права, срок 1–2 дня, после работ отзываются.
- Защита от PHP2Shell (CVE-2026-109618/9):
  - Обновить PHP до патчной версии (>= 8.1.33, 8.2.25, 8.3.12, 8.4.0+).
  - Настроить `disable_functions` и `open_basedir` в `php.ini`.
- Из LAN хаб закрыт HTTP Basic Auth; с `127.0.0.1` — без пароля.
- Роутер: forbidden-список (`.env`, `bin/`, `data/`, `projects/`, `*.md`), защита path traversal (realpath + проверка префикса docroot).

## 📝 Логи
`data/workspace.log`, `data/workspace-error.log`, `data/*.log` — в репозиторий не попадают.

## 📄 Лицензия
Apache License 2.0 — полный текст в [LICENSE](https://github.com/mrsokol491/gitproject/blob/master/LICENSE).
Выбор обусловлен упором на российское ПО: лицензия принята в РФ-практике (Яндекс, Сбер, VK), даёт явную патентную защиту и совместима с GPL.

## 📚 Документация скриптов
`doc/` — инструкция по каждому `bin/*.sh`: все флаги, режимы, коды подтверждения, ограничения и примеры вызова. Оглавление: `doc/README.md`.