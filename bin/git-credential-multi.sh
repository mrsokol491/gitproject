#!/usr/bin/env bash
# git-credential-multi.sh — credential helper нескольких Git-хостов (GitProject Workspace Hub).
# Портативно: корень репозитория и .env берутся из HUB_ROOT (bin/lib/common.sh).
# Читает запрос git из stdin ЦЕЛИКОМ (protocol/host/path), выбирает токен из .env
# по схеме <PREFIX>_<SCOPE>_TOKEN: PREFIX по домену, SCOPE = HUB (репозиторий хаба
# gitproject на любом хосте) или PROJECT (остальные репозитории).
# Username: <PREFIX>_<SCOPE>_USER -> <PREFIX>_HUB_USER -> owner из path (первый сегмент).
# Персональных констант в коде нет: логины владельца различаются по хостам.
# Значение "placeholder" в .env трактуется как отсутствие токена.
# При пустом токене: подсказка в stderr про API-ТОКЕН, молчаливый выход (git спросит сам).

set -u

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/lib/common.sh"

op="${1:-}"
if [ "$op" != "get" ]; then
    cat >/dev/null 2>&1 || true
    exit 0
fi

input="$(cat 2>/dev/null || true)"
host="$(printf '%s\n' "$input" | sed -n 's/^host=//p' | tail -n 1 | tr -d '\r')"
path="$(printf '%s\n' "$input" | sed -n 's/^path=//p' | tail -n 1 | tr -d '\r')"
[ -n "$host" ] || exit 0

env_file="${HUB_ROOT}/.env"
if [ ! -r "$env_file" ]; then
    echo "git-credential-multi: нет читаемого .env ($env_file)" >&2
    exit 0
fi

case "$host" in
    gitverse.ru)         prefix="GITVERSE" ;;
    gitflic.ru)          prefix="GITFLIC" ;;
    git.sourcecraft.dev) prefix="SOURCECRAFT" ;;
    hub.mos.ru)          prefix="MOSHUB" ;;
    altlinux.space)      prefix="ALTLINUX" ;;
    github.com)          prefix="GITHUB" ;;
    gitlab.com)          prefix="GITLAB" ;;
    codeberg.org)        prefix="CODEBERG" ;;
    gitea.com)           prefix="GITEA" ;;
    *) exit 0 ;;
esac

path_lc="${path,,}"
if [ "$path_lc" == *gitproject* ]; then
    scope="HUB"
else
    scope="PROJECT"
fi

user="$(sed -n "s/^${prefix}_${scope}_USER=//p" "$env_file" | tail -n 1 | tr -d '\r')"
token="$(sed -n "s/^${prefix}_${scope}_TOKEN=//p" "$env_file" | tail -n 1 | tr -d '\r')"
if [ -z "$token" ] || { [ "$token" = "placeholder" ] && [ "$scope" = "PROJECT" ]; }; then
    user="$(sed -n "s/^${prefix}_HUB_USER=//p" "$env_file" | tail -n 1 | tr -d '\r')"
    token="$(sed -n "s/^${prefix}_HUB_TOKEN=//p" "$env_file" | tail -n 1 | tr -d '\r')"
fi
[ "$token" = "placeholder" ] && token=""
[ "$user" = "placeholder" ] && user=""

# Fallback username: owner из path, который передаёт git (owner/repo.git).
if [ -z "$user" ] && [ -n "$path" ]; then
    user="${path%%/*}"
fi

if [ -z "$token" ]; then
    echo "git-credential-multi: для $host не задан ${prefix}_${scope}_TOKEN в .env." >&2
    echo "Если git спросит Password — введите API-ТОКЕН $host (Настройки -> Приложения/Access Tokens), НЕ пароль аккаунта." >&2
    exit 0
fi
if [ -z "$user" ]; then
    echo "git-credential-multi: для $host не определён username: задайте ${prefix}_${scope}_USER в .env." >&2
    exit 0
fi

printf 'username=%s\npassword=%s\n' "$user" "$token"