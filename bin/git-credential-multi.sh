#!/usr/bin/env bash
# git-credential-multi.sh — credential helper нескольких Git-хостов (GitProject Workspace Hub).
# Читает запрос git из stdin ЦЕЛИКОМ (protocol/host/path), выбирает токен из .env
# по схеме <PREFIX>_<SCOPE>_TOKEN, где PREFIX — хост, SCOPE — HUB (репозиторий хаба)
# или PROJECT (репозитории в projects/).
#
# Хосты: gitflic.ru -> GITFLIC_*, github.com -> GITHUB_*,
#        gitlab.com -> GITLAB_*, gitverse.ru -> GITVERSE_*.
# Неизвестный хост или пустой токен: молчаливый выход без вывода —
# git запросит данные сам; при пустом токене в stderr печатается подсказка,
# что вводить нужно API-ТОКЕН, а не пароль аккаунта.

set -u

op="${1:-}"
if [ "$op" != "get" ]; then
    # store/erase и прочее: читаем и игнорируем, чтобы не заблокировать канал
    cat >/dev/null 2>&1 || true
    exit 0
fi

# ВАЖНО: stdin читается один раз и ЦЕЛИКОМ до любого разбора (баг v1: cat >/dev/null съедал запрос)
input="$(cat 2>/dev/null || true)"

host="$(printf '%s\n' "$input" | sed -n 's/^host=//p' | tail -n 1 | tr -d '\r')"
path="$(printf '%s\n' "$input" | sed -n 's/^path=//p' | tail -n 1 | tr -d '\r')"

[ -n "$host" ] || exit 0

env_file="${HOME}/gitflicProject/.env"
if [ ! -r "$env_file" ]; then
    echo "git-credential-multi: нет читаемого .env ($env_file) — git запросит API-ТОКЕН вручную" >&2
    exit 0
fi

case "$host" in
    gitflic.ru)  prefix="GITFLIC" ;;
    github.com)  prefix="GITHUB" ;;
    gitlab.com)  prefix="GITLAB" ;;
    gitverse.ru) prefix="GITVERSE" ;;
    *) exit 0 ;;
esac

# Область: HUB — только репозиторий хаба на GitFlic (slug gitflicproject, нижний регистр!);
# путь из URL приходит строчный, поэтому сравнение в нижнем регистре.
path_lc="${path,,}"
if [ "$host" = "gitflic.ru" ] && { [ -z "$path_lc" ] || [[ "$path_lc" == *gitflicproject* ]]; }; then
    scope="HUB"
else
    scope="PROJECT"
fi

user="$(sed -n "s/^${prefix}_${scope}_USER=//p" "$env_file" | tail -n 1 | tr -d '\r')"
token="$(sed -n "s/^${prefix}_${scope}_TOKEN=//p" "$env_file" | tail -n 1 | tr -d '\r')"

# Fallback: PROJECT-токен пуст — пробуем HUB-токен того же хоста
if [ -z "$token" ] && [ "$scope" = "PROJECT" ]; then
    user="$(sed -n "s/^${prefix}_HUB_USER=//p" "$env_file" | tail -n 1 | tr -d '\r')"
    token="$(sed -n "s/^${prefix}_HUB_TOKEN=//p" "$env_file" | tail -n 1 | tr -d '\r')"
fi

if [ -z "$token" ]; then
    echo "git-credential-multi: для $host не задан ${prefix}_${scope}_TOKEN в .env." >&2
    echo "Если git спросит Password — введите API-ТОКЕН $host (Настройки -> API-токены), НЕ пароль аккаунта." >&2
    exit 0
fi

printf 'username=%s\npassword=%s\n' "${user:-sokol491}" "$token"
