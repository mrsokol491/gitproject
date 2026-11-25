#!/usr/bin/env bash
set -u

op="${1:-}"
if [ "$op" != "get" ]; then
    cat >/dev/null 2>&1 || true
    exit 0
fi

input="$(cat 2>/dev/null || true)"
host="$(printf '%s\n' "$input" | sed -n 's/^host=//p' | tail -n 1 | tr -d '\r')"
path="$(printf '%s\n' "$input" | sed -n 's/^path=//p' | tail -n 1 | tr -d '\r')"
[ -n "$host" ] || exit 0

env_file="${HOME}/gitproject/.env"
if [ ! -r "$env_file" ]; then
    echo "git-credential-multi: нет читаемого .env ($env_file)" >&2
    exit 0
fi

case "$host" in
    gitverse.ru)         prefix="GITVERSE" ;;
    gitflic.ru)          prefix="GITFLIC" ;;
    git.sourcecraft.dev) prefix="SOURCECRAFT" ;;
    hub.mos.ru)          prefix="MOSHUB" ;;
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

if [ -z "$token" ]; then
    echo "git-credential-multi: для $host не задан ${prefix}_${scope}_TOKEN в .env." >&2
    exit 0
fi

printf 'username=%s\npassword=%s\n' "${user:-sokol491}" "$token"