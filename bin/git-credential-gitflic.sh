#!/usr/bin/env bash
# git-credential-gitflic.sh — credential helper для GitFlic.
# Читает GITFLIC_USER и GITFLIC_TOKEN из .env workspace и отдаёт их git.
# Подключение: git config --global credential.https://gitflic.ru.helper '<путь к файлу>'

set -u

# Операция от git (get/store/erase). Токен выдаём только на get.
op="${1:-}"

# Запрос git приходит на stdin — читаем и игнорируем, чтобы не заблокировать канал
cat >/dev/null 2>&1 || true
[ "$op" = "get" ] || exit 0

env_file="${HOME}/gitflicProject/.env"
[ -r "$env_file" ] || exit 0

# Читаем значения из .env (последняя найденная строка), убираем CR для файлов из Windows
token="$(sed -n 's/^GITFLIC_TOKEN=//p' "$env_file" | tail -n 1 | tr -d '\r')"
user="$(sed -n 's/^GITFLIC_USER=//p' "$env_file" | tail -n 1 | tr -d '\r')"

# Без токена молча выходим — git покажет обычный запрос пароля
[ -n "$token" ] || exit 0

# Ответ по протоколу git-credential
printf 'username=%s\npassword=%s\n' "${user:-sokol491}" "$token"