#!/usr/bin/env bash
# common.sh — общие переменные и функции для bin-скриптов GitProject Workspace Hub.
# Портативно: HUB_ROOT вычисляется из расположения файла (bin/lib -> корень репозитория).
# Содержит helper'ы wizard-скриптов (clone/remote/migrate/add-host/remove):
# host_field, host_status, host_id_by_domain, get_host_owner, build_repo_url,
# check_remote_exists, remote_display_name, log_to_host, CONFIG_FILE.

# --- Динамическое определение корня репозитория ---
if [ -z "${HUB_ROOT:-}" ]; then
    SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
    HUB_ROOT="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
    export HUB_ROOT
fi

PROJECTS_DIR="${HUB_ROOT}/projects"
export PROJECTS_DIR

CONFIG_FILE="${HUB_ROOT}/config/hosts.json"
export CONFIG_FILE

ENV_FILE="${HUB_ROOT}/.env"
export ENV_FILE

# --- Цветовые переменные (ANSI) ---
if [ -t 1 ]; then
    BOLD='\033[1m'
    DIM='\033[2m'
    UNDERLINE='\033[4m'
    NC='\033[0m'
    RESET='\033[0m'
    BLACK='\033[30m'
    RED='\033[31m'
    GREEN='\033[32m'
    YELLOW='\033[33m'
    BLUE='\033[34m'
    MAGENTA='\033[35m'
    CYAN='\033[36m'
    WHITE='\033[37m'
    GRAY='\033[90m'
else
    BOLD=''; DIM=''; UNDERLINE=''; NC=''; RESET=''
    BLACK=''; RED=''; GREEN=''; YELLOW=''
    BLUE=''; MAGENTA=''; CYAN=''; WHITE=''; GRAY=''
fi
export BOLD DIM UNDERLINE NC RESET BLACK RED GREEN YELLOW BLUE MAGENTA CYAN WHITE GRAY

# --- Загрузка .env (построчный парсинг, без IFS='=') ---
load_env() {
    local env_file="${HUB_ROOT}/.env"
    if [ -f "$env_file" ]; then
        while IFS= read -r line || [ -n "$line" ]; do
            line="${line%$'\r'}"
            case "$line" in ''|\#*) continue ;; esac
            case "$line" in *=*) ;; *) continue ;; esac
            export "${line%%=*}=${line#*=}"
        done < "$env_file"
    fi
}

# --- Функции вывода ---
print_info()    { printf '\033[34mℹ %s\033[0m\n' "$*"; }
print_success() { printf '\033[32m✓ %s\033[0m\n' "$*"; }
print_warn()    { printf '\033[33m⚠ %s\033[0m\n' "$*"; }
print_error()   { printf '\033[31m✗ %s\033[0m\n' "$*" >&2; }
print_header()  { printf '\n\033[1;36m%s\033[0m\n' "$*"; }
print_gray()    { printf '\033[90m%s\033[0m\n' "$*"; }

# --- Список remote проекта ---
get_project_remotes() {
    local slug="$1"
    local repo="${PROJECTS_DIR}/${slug}"
    [ -d "$repo/.git" ] || return 0
    git -C "$repo" remote 2>/dev/null | sort
}

# --- Helper'ы wizard-скриптов (hosts.json + .env) ---

host_field() {
    local id="$1" field="$2"
    [ -f "$CONFIG_FILE" ] || return 0
    jq -r --arg id "$id" --arg f "$field" '.[$id][$f] // empty' "$CONFIG_FILE" 2>/dev/null
}

host_id_by_domain() {
    local domain="$1"
    [ -f "$CONFIG_FILE" ] || return 0
    jq -r --arg d "$domain" 'to_entries[] | select(.value.host == $d) | .key' "$CONFIG_FILE" 2>/dev/null | head -n 1
}

host_status() {
    local domain="$1" id
    id="$(host_id_by_domain "$domain")"
    if [ -z "$id" ]; then
        echo "unknown"
        return 0
    fi
    host_field "$id" status
}

get_host_owner() {
    local domain="$1" id prefix owner
    id="$(host_id_by_domain "$domain")"
    [ -n "$id" ] || return 0
    prefix="$(host_field "$id" env_prefix)"
    [ -n "$prefix" ] || return 0
    owner="$(sed -n "s/^${prefix}_PROJECT_USER=//p" "$ENV_FILE" 2>/dev/null | tail -n 1 | tr -d '\r')"
    if [ -z "$owner" ] || [ "$owner" = "placeholder" ]; then
        owner="$(sed -n "s/^${prefix}_HUB_USER=//p" "$ENV_FILE" 2>/dev/null | tail -n 1 | tr -d '\r')"
    fi
    [ "$owner" = "placeholder" ] && owner=""
    printf '%s' "$owner"
}

build_repo_url() {
    local domain="$1" owner="$2" slug="$3" id tpl url
    id="$(host_id_by_domain "$domain")"
    [ -n "$id" ] || return 1
    tpl="$(host_field "$id" url_template)"
    [ -n "$tpl" ] || return 1
    url="${tpl//\{owner\}/$owner}"
    url="${url//\{slug\}/$slug}"
    printf '%s' "$url"
}

check_remote_exists() {
    local domain="$1" owner="$2" slug="$3" url
    url="$(build_repo_url "$domain" "$owner" "$slug")" || return 1
    GIT_TERMINAL_PROMPT=0 git ls-remote "$url" HEAD >/dev/null 2>&1
}

remote_display_name() {
    local slug="$1" remote="$2" desc name
    desc="$(host_field "$remote" description)"
    name="${desc%% —*}"
    [ -n "$name" ] || name="$remote"
    printf '%s' "$name"
}

log_to_host() {
    local domain="$1" msg="$2"
    mkdir -p "$HUB_ROOT/data"
    printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$msg" >> "$HUB_ROOT/data/$domain.log"
}