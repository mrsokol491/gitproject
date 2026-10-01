#!/usr/bin/env bash
# git-credential-multi.sh — credential helper для нескольких Git-хостов.
# Читает stdin от git (protocol, host, path), определяет хост и отдаёт нужный токен из .env.
#
# Поддерживаемые хосты:
#   gitflic.ru   -> GITFLIC_HUB_* / GITFLIC_PROJECT_*
#   github.com   -> GITHUB_HUB_* / GITHUB_PROJECT_*
#   gitlab.com   -> GITLAB_HUB_* / GITLAB_PROJECT_*
#   gitverse.ru  -> GITVERSE_HUB_* / GITVERSE_PROJECT_*
#
# Выбор HUB vs PROJECT: по пути репозитория (если path содержит gitflicProject — это хаб).

set -u

op="${1:-}"
cat >/dev/null 2>&1 || true
[ "$op" = "get" ] || exit 0

env_file="${HOME}/gitflicProject/.env"
[ -r "$env_file" ] || exit 0

# Читаем stdin от git
protocol=""
host=""
path=""
while IFS='=' read -r key value; do
    case "$key" in
        protocol) protocol="$value" ;;
        host)     host="$value" ;;
        path)     path="$value" ;;
    esac
done

[ -n "$host" ] || exit 0

# Определяем префикс переменных по хосту
case "$host" in
    gitflic.ru)  prefix="GITFLIC" ;;
    github.com)  prefix="GITHUB" ;;
    gitlab.com)  prefix="GITLAB" ;;
    gitverse.ru) prefix="GITVERSE" ;;
    *)           exit 1 ;;  # неизвестный хост — git запросит вручную
esac

# Определяем область: HUB или PROJECT
# Если путь содержит gitflicProject (репозиторий хаба) — это HUB, иначе PROJECT
if [[ "$path" == *"gitflicProject"* ]]; then
    scope="HUB"
else
    scope="PROJECT"
fi

user_var="${prefix}_${scope}_USER"
token_var="${prefix}_${scope}_TOKEN"

user="$(sed -n "s/^${user_var}=//p" "$env_file" | tail -n 1 | tr -d '\r')"
token="$(sed -n "s/^${token_var}=//p" "$env_file" | tail -n 1 | tr -d '\r')"

# Fallback: если PROJECT токен пуст, пробуем HUB
if [ -z "$token" ] && [ "$scope" = "PROJECT" ]; then
    token="$(sed -n "s/^${prefix}_HUB_TOKEN=//p" "$env_file" | tail -n 1 | tr -d '\r')"
    user="$(sed -n "s/^${prefix}_HUB_USER=//p" "$env_file" | tail -n 1 | tr -d '\r')"
fi

[ -n "$token" ] || exit 0

printf 'username=%s\npassword=%s\n' "${user:-sokol491}" "$token"
