#!/usr/bin/env bash
# common.sh — общие функции мульти-хост системы GitProject Workspace Hub.
# Подключение: source "$HOME/gitproject/bin/lib/common.sh"

CONFIG_FILE="$HOME/gitproject/config/hosts.json"
PROJECTS_DIR="$HOME/gitproject/projects"
ENV_FILE="$HOME/gitproject/.env"

# Цвета консоли
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; GRAY='\033[0;90m'; BOLD='\033[1m'; RESET='\033[0m'

print_header()  { echo -e "${BLUE}${BOLD}$1${RESET}"; }
print_success() { echo -e "${GREEN}$1${RESET}"; }
print_error()   { echo -e "${RED}$1${RESET}" >&2; }
print_warn()    { echo -e "${YELLOW}$1${RESET}"; }
print_info()    { echo -e "${CYAN}$1${RESET}"; }
print_gray()    { echo -e "${GRAY}$1${RESET}"; }

load_env() {
    GIT_HOST="$(sed -n 's/^GIT_HOST=//p' "$ENV_FILE" 2>/dev/null | tail -n 1 | tr -d '\r')"
    GIT_HOST="${GIT_HOST:-gitflic.ru}"
}

# Значение поля хоста из config/hosts.json по его id (gitflic|gitverse|...)
host_field() {
    local id="$1" field="$2"
    [ -f "$CONFIG_FILE" ] || return 1
    sed -n "/\"${id}\": *{/,/}/p" "$CONFIG_FILE" \
        | sed -n "s/.*\"${field}\": *\"\([^\"]*\)\".*/\1/p" | head -n 1
}

# Id хоста по домену (gitverse.ru -> gitverse)
host_id_by_domain() {
    local domain="$1" id
    for id in $(sed -n 's/^ *"\([a-z]*\)": *{.*$/\1/p' "$CONFIG_FILE" 2>/dev/null); do
        if [ "$(host_field "$id" host)" = "$domain" ]; then
            echo "$id"
            return 0
        fi
    done
    echo ""
    return 1
}

# Статус хоста по домену (ready|integrating|disabled)
host_status() {
    local id
    id="$(host_id_by_domain "$1")"
    [ -n "$id" ] && host_field "$id" status || echo "disabled"
}

# Префикс переменных .env по домену (GITFLIC|GITVERSE|...)
host_prefix() {
    local id
    id="$(host_id_by_domain "$1")"
    [ -n "$id" ] && host_field "$id" env_prefix || echo ""
}

# URL репозитория по шаблону хоста: build_repo_url <domain> <owner> <slug>
build_repo_url() {
    local domain="$1" owner="$2" slug="$3" id tpl
    id="$(host_id_by_domain "$domain")"
    [ -n "$id" ] || return 1
    tpl="$(host_field "$id" url_template)"
    [ -n "$tpl" ] || return 1
    tpl="${tpl//\{owner\}/$owner}"
    tpl="${tpl//\{slug\}/$slug}"
    echo "$tpl"
}

# Owner по умолчанию для хоста: <PREFIX>_PROJECT_USER, fallback <PREFIX>_HUB_USER
get_host_owner() {
    local prefix
    prefix="$(host_prefix "$1")"
    [ -n "$prefix" ] || return 1
    local owner
    owner="$(sed -n "s/^${prefix}_PROJECT_USER=//p" "$ENV_FILE" 2>/dev/null | tail -n 1 | tr -d '\r')"
    if [ -z "$owner" ] || [ "$owner" = "placeholder" ]; then
        owner="$(sed -n "s/^${prefix}_HUB_USER=//p" "$ENV_FILE" 2>/dev/null | tail -n 1 | tr -d '\r')"
    fi
    echo "$owner"
}

# Токен для хоста и области: get_host_token <domain> <HUB|PROJECT>
get_host_token() {
    local prefix
    prefix="$(host_prefix "$1")"
    [ -n "$prefix" ] || return 1
    local scope="${2:-PROJECT}" token
    token="$(sed -n "s/^${prefix}_${scope}_TOKEN=//p" "$ENV_FILE" 2>/dev/null | tail -n 1 | tr -d '\r')"
    if { [ -z "$token" ] || [ "$token" = "placeholder" ]; } && [ "$scope" = "PROJECT" ]; then
        token="$(sed -n "s/^${prefix}_HUB_TOKEN=//p" "$ENV_FILE" 2>/dev/null | tail -n 1 | tr -d '\r')"
    fi
    [ "$token" = "placeholder" ] && token=""
    echo "$token"
}

# Репозиторий существует на хосте: check_remote_exists <domain> <owner> <slug>
check_remote_exists() {
    local url
    url="$(build_repo_url "$1" "$2" "$3")" || return 1
    local output
    output="$(git ls-remote "$url" 2>&1)"
    [[ ! "$output" =~ "Repository not found" && ! "$output" =~ "fatal:" && ! "$output" =~ "error:" && ! "$output" =~ "404" ]]
}

# Лог операции в отдельный файл хоста: log_to_host <domain> <сообщение>
log_to_host() {
    local log_file="$HOME/gitproject/data/$1.log"
    mkdir -p "$HOME/gitproject/data"
    printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$2" >> "$log_file"
}

# Список имён remote проекта
get_project_remotes() {
    local repo="$PROJECTS_DIR/$1"
    [ -d "$repo/.git" ] || return 1
    git -C "$repo" remote 2>/dev/null | sort
}
