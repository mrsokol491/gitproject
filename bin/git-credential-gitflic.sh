#!/usr/bin/env bash
# git-credential-gitflic.sh — маршрутизация учётных данных GitFlic из .env
set -u
op="${1:-}"

host=""
path=""
while IFS='=' read -r key value; do
    [ "$key" = "host" ] && host="$value"
    [ "$key" = "path" ] && path="$value"
done

[ "$op" = "get" ] || exit 0

env_file="${HOME}/gitflicProject/.env"
[ -r "$env_file" ] || exit 0

# Маршрутизация: хаб строго sokol491, проекты — любой пользователь из .env
if [[ "$path" == *"sokol491/gitflicproject"* ]]; then
    user="$(sed -n 's/^GITFLIC_HUB_USER=//p' "$env_file" | tail -n 1 | tr -d '\r')"
    token="$(sed -n 's/^GITFLIC_HUB_TOKEN=//p' "$env_file" | tail -n 1 | tr -d '\r')"
else
    user="$(sed -n 's/^GITFLIC_PROJECT_USER=//p' "$env_file" | tail -n 1 | tr -d '\r')"
    token="$(sed -n 's/^GITFLIC_PROJECT_TOKEN=//p' "$env_file" | tail -n 1 | tr -d '\r')"
fi

[ -n "$token" ] || exit 0
printf 'username=%s\npassword=%s\n' "${user:-sokol491}" "$token"