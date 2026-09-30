# GitFlic Workspace Hub

Централизованный хаб для управления, проксирования и изоляции проектов GitFlic. 
Порт по умолчанию: `8000`. Владелец инфраструктуры: Александр (@sokol491).

## Архитектура и базовые правила

1. **Окружение хаба** (код, скрипты, роутер, конфигурации) обновляется **строго и исключительно** из репозитория `sokol491/gitflicproject`.
2. **Рабочие проекты** в контейнере `projects/` могут принадлежать **любому** пользователю GitFlic. Целевой пользователь и токен для клонирования/пуша проектов задаются в `.env`.
3. **Безопасность**: все секреты хранятся только в `.env`. Токены и пароли не должны попадать в логи, чаты или коммиты.
4. **Стандарты кода**: PHP 8.1+, `declare(strict_types=1)`, PSR-12, PSR-4 (`App\` → `src/`). Docroot веб-приложений — `public/`.

---

## Установка (Новое устройство / Новый пользователь)

### Шаг 1. Клонирование хаба
Клонируем окружение хаба. Origin строго `sokol491`.

```bash
git clone https://gitflic.ru/project/sokol491/gitflicproject.git ~/gitflicProject
cd ~/gitflicProject
```

### Шаг 2. Настройка окружения (.env)
Создайте файл `.env` и установите права `600`. Разделите токены: один для самого хаба, другой для рабочих проектов.

```bash
cat > ~/gitflicProject/.env << 'ENV_EOF'
# Пароль для HTTP Basic Auth при доступе к хабу из LAN
NETWORK_PASSWORD=change_me_to_secure_password

# Учётные данные хаба (строго sokol491)
GITFLIC_HUB_USER=sokol491
GITFLIC_HUB_TOKEN=paste_hub_token_here

# Учётные данные для рабочих проектов (можно указать любого пользователя GitFlic)
GITFLIC_PROJECT_USER=sokol491
GITFLIC_PROJECT_TOKEN=paste_project_token_here
ENV_EOF

chmod 600 ~/gitflicProject/.env
# Защита от склейки последней строки
[ -n "$(tail -c 1 ~/gitflicProject/.env)" ] && printf '\n' >> ~/gitflicProject/.env
```

### Шаг 3. Настройка Git и Credential Helper
Хелпер автоматически маршрутизирует учётные данные: если запрашивается `sokol491/gitflicproject`, используются данные хаба, для всех остальных — данные проектов.

```bash
# Делаем хелпер исполняемым
chmod 700 ~/gitflicProject/bin/git-credential-gitflic.sh

# Регистрируем хелпер в глобальном git
git config --global --unset-all credential.helper
git config --global credential.https://gitflic.ru.helper "$HOME/gitflicProject/bin/git-credential-gitflic.sh"

# Настраиваем идентичность (обязательно для коммитов)
git config --global user.name "Your Name"
git config --global user.email "your_email@gitflic.ru"
```

### Шаг 4. SSH-ключ (Опционально, запасной канал)
Если HTTPS-токены отозваны или недоступны, можно использовать SSH.

```bash
ssh-keygen -t ed25519 -C "gitflic-workspace" -f ~/.ssh/gitflic_ed25519 -N ""
cat ~/.ssh/gitflic_ed25519.pub
# Скопируйте вывод и добавьте в настройки GitFlic -> SSH-ключи

cat >> ~/.ssh/config << 'SSH_EOF'
Host gitflic.ru
    User git
    IdentityFile ~/.ssh/gitflic_ed25519
    IdentitiesOnly yes
SSH_EOF
chmod 600 ~/.ssh/config
```

### Шаг 5. Запуск сервиса
Хаб работает как пользовательский systemd-сервис или через встроенный PHP-сервер.

```bash
# Вариант А: Прямой запуск (для тестов)
php -S 0.0.0.0:8000 -t ~/gitflicProject ~/gitflicProject/router.php

# Вариант Б: Systemd (для продакшена)
mkdir -p ~/.config/systemd/user/
# Убедитесь, что gitflic-workspace.service присутствует в репозитории
systemctl --user daemon-reload
systemctl --user enable --now gitflic-workspace.service
sudo loginctl enable-linger $USER # Автозапуск при загрузке ОС
```

---

## Работа с проектами

### Клонирование проекта
Токен из `GITFLIC_PROJECT_*` подставится автоматически благодаря credential helper.

```bash
# Пример клонирования проекта (своего или чужого)
git clone https://gitflic.ru/project/other_user/some-project.git ~/gitflicProject/projects/some-project
```

### Публикация изменений (Sync)
Используйте sync-скрипты. Они автоматически проверяют staging-область на утечки секретов перед пушем.

```bash
# Публикация изменений в хабе (только в master)
~/gitflicProject/bin/gitflic-sync-hub.sh -m "docs: обновил README"

# Публикация изменений в рабочем проекте
~/gitflicProject/bin/gitflic-sync.sh <slug> -m "feat: новая фича"
```

---

## Cheat Sheet

| Действие | Команда |
|---|---|
| Статус хаба | `~/gitflicProject/bin/gitflic-sync-hub.sh status` |
| Статус проектов | `~/gitflicProject/bin/gitflic-sync.sh status` |
| Перезапуск хаба | `systemctl --user restart gitflic-workspace.service` |
| Логи хаба | `tail -f ~/gitflicProject/data/workspace-error.log` |
| Проверка порта | `ss -tlnp \| grep :8000` |
| Маскированный просмотр .env | `sed -E 's/=(.+)$/=[скрыто]/' ~/gitflicProject/.env` |

---

## Структура каталогов

```text
~/gitflicProject/
├── router.php          # Роутер хаба
├── index.php           # Главная страница
├── .env                # Секреты (игнорируется git)
├── bin/                # Скрипты управления, sync и credential helper
├── projects/           # Контейнер изолированных репозиториев
├── data/               # Логи, кэш и SQLite базы (WAL mode)
└── assets/             # Общие статические файлы
```
EOF

# Фиксация изменений в репозитории
cd ~/gitflicProject
git add README.md
git commit -m "docs: добавлен подробный README с инструкцией по установке и мульти-юзер поддержкой"

# Отправка в репозиторий sokol491/gitflicproject
~/gitflicProject/bin/gitflic-sync-hub.sh -m "docs: добавлен подробный README с инструкцией по установке и мульти-юзер поддержкой"