#!/usr/bin/env bash
# common.sh — общие функции для bin-скриптов GitProject Workspace Hub.
# Вычисляет HUB_ROOT динамически через BASH_SOURCE, чтобы работать из любой папки.

if [ -z "${HUB_ROOT:-}" ]; then
    SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
    HUB_ROOT="$(cd -- "$SCRIPT_DIR/.." && pwd)"
    export HUB_ROOT
fi

PROJECTS_DIR="${HUB_ROOT}/projects"
export PROJECTS_DIR

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

print_info()  { printf '\033[34mℹ %s\033[0m\n' "$*"; }
print_success(){ printf '\033[32m✓ %s\033[0m\n' "$*"; }
print_warn()  { printf '\033[33m⚠ %s\033[0m\n' "$*"; }
print_error() { printf '\033[31m✗ %s\033[0m\n' "$*" >&2; }
print_header(){ printf '\n\033[1;36m%s\033[0m\n' "$*"; }
print_gray()  { printf '\033[90m%s\033[0m\n' "$*"; }

get_project_remotes() {
    local slug="$1" repo="${PROJECTS_DIR}/${slug}"
    [ -d "$repo/.git" ] || return 0
    git -C "$repo" remote 2>/dev/null | sort
}