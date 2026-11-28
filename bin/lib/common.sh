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

# --- Валидация slug проекта ---
# Допустимые символы: латиница, цифры, - и _, не начинать с -, длина 1-50.
# Вызывать во всех скриптах, где slug попадает в путь.
validate_slug() {
    local slug="$1"
    if [ -z "$slug" ]; then
        print_error "Slug пустой"
        return 1
    fi
    if ! [[ "$slug" =~ ^[a-z][a-z0-9_-]{0,49}$ ]]; then
        print_error "Некорректный slug '$slug': только латиница, цифры, - и _, не начинать с -, длина 1-50"
        return 1
    fi
    return 0
}

# --- Проверка существования репозитория на remote (с кэшем 15 мин) ---
# Возвращает 0 если репо существует, 1 если нет.
# Кэш: data/remotes-cache.json, TTL 15 минут.
check_remote_repo_exists() {
    local slug="$1" remote="$2"
    local repo="${PROJECTS_DIR}/${slug}"
    local cache_file="${HUB_ROOT}/data/remotes-cache.json"
    local cache_key="${slug}/${remote}"
    local now ttl cached_val

    [ -d "$repo/.git" ] || return 1

    # Получить URL remote
    local url
    url="$(git -C "$repo" remote get-url -- "$remote" 2>/dev/null)" || return 1
    [ -n "$url" ] || return 1

    # Проверить кэш
    mkdir -p "${HUB_ROOT}/data"
    if [ -f "$cache_file" ]; then
        now="$(date +%s)"
        cached_val="$(jq -r --arg k "$cache_key" '.[$k] // empty' "$cache_file" 2>/dev/null)"
        if [ -n "$cached_val" ]; then
            ttl="$(echo "$cached_val" | jq -r '.ttl // 0' 2>/dev/null)"
            if [ "$now" -lt "$ttl" ]; then
                local exists
                exists="$(echo "$cached_val" | jq -r '.exists' 2>/dev/null)"
                [ "$exists" = "true" ] && return 0 || return 1
            fi
        fi
    fi

    # Реальная проверка через ls-remote (требует credentials)
    local exists=false
    if GIT_TERMINAL_PROMPT=0 git ls-remote -- "$url" HEAD >/dev/null 2>&1; then
        exists=true
    fi

    # Записать в кэш (TTL 15 мин = 900 сек)
    local new_ttl=$(( now + 900 ))
    local tmp_cache
    tmp_cache="$(mktemp)"
    if [ -f "$cache_file" ]; then
        jq --arg k "$cache_key" --argjson t "$new_ttl" --argjson e "$exists" \
           '.[$k] = {ttl: $t, exists: $e}' "$cache_file" > "$tmp_cache" 2>/dev/null
    else
        printf '{"%s":{"ttl":%d,"exists":%s}}' "$cache_key" "$new_ttl" "$exists" > "$tmp_cache"
    fi
    mv "$tmp_cache" "$cache_file"

    [ "$exists" = "true" ] && return 0 || return 1
}

# Сброс кэша (вызывать после добавления/удаления remote)
clear_remotes_cache() {
    local slug="${1:-}"
    local cache_file="${HUB_ROOT}/data/remotes-cache.json"
    [ -f "$cache_file" ] || return 0
    if [ -z "$slug" ]; then
        rm -f "$cache_file"
    else
        local tmp
        tmp="$(mktemp)"
        jq --arg s "$slug" 'to_entries | map(select((.key | startswith($s + "/")) | not)) | from_entries' \
           "$cache_file" > "$tmp" 2>/dev/null
        mv "$tmp" "$cache_file"
    fi
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