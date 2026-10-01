#!/usr/bin/env bash
# common.sh — общие функции для мульти-хост системы GitProject.
# Подключается в начало скриптов: source "$HOME/gitflicProject/bin/lib/common.sh"

# Цвета
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; GRAY='\033[0;90m'; BOLD='\033[1m'; RESET='\033[0m'

print_header()  { echo -e "${BLUE}${BOLD}$1${RESET}"; }
print_success() { echo -e "${GREEN}$1${RESET}"; }
print_error()   { echo -e "${RED}$1${RESET}" >&2; }
print_warn()    { echo -e "${YELLOW}$1${RESET}"; }
print_info()    { echo -e "${CYAN}$1${RESET}"; }
print_gray()    { echo -e "${GRAY}$1${RESET}"; }

# Загрузка .env
load_env() {
    local env_file="${HOME}/gitflicProject/.env"
    if [ -r "$env_file" ]; then
        GIT_HOST="$(sed -n 's/^GIT_HOST=//p' "$env_file" | tail -n 1 | tr -d '\r')"
        ACTIVE_HOSTS="$(sed -n 's/^ACTIVE_HOSTS=//p' "$env_file" | tail -n 1 | tr -d '\r')"
    fi
    GIT_HOST="${GIT_HOST:-gitflic.ru}"
    ACTIVE_HOSTS="${ACTIVE_HOSTS:-gitflic.ru}"
}

# Получить токен для хоста и области (HUB/PROJECT)
get_host_token() {
    local host="$1" scope="${2:-PROJECT}"
    local env_file="${HOME}/gitflicProject/.env"
    local prefix=""
    
    case "$host" in
        gitflic.ru)  prefix="GITFLIC" ;;
        github.com)  prefix="GITHUB" ;;
        gitlab.com)  prefix="GITLAB" ;;
        gitverse.ru) prefix="GITVERSE" ;;
        *)           echo ""; return 1 ;;
    esac
    
    local token_var="${prefix}_${scope}_TOKEN"
    local token
    token="$(sed -n "s/^${token_var}=//p" "$env_file" | tail -n 1 | tr -d '\r')"
    
    # Fallback: если PROJECT токен пуст, пробуем HUB
    if [ -z "$token" ] && [ "$scope" = "PROJECT" ]; then
        token="$(sed -n "s/^${prefix}_HUB_TOKEN=//p" "$env_file" | tail -n 1 | tr -d '\r')"
    fi
    
    echo "$token"
}

# Проверить, существует ли репозиторий на хосте
check_remote_exists() {
    local host="$1" owner="$2" slug="$3"
    local url="https://${host}/project/${owner}/${slug}.git"
    local output
    output="$(git ls-remote "$url" 2>&1)"
    [[ ! "$output" =~ "Repository not found" && ! "$output" =~ "fatal:" && ! "$output" =~ "error:" ]]
}

# Логирование в отдельный файл хоста
log_to_host() {
    local host="$1" message="$2"
    local log_file="${HOME}/gitflicProject/data/${host}.log"
    local timestamp
    timestamp="$(date '+%Y-%m-%d %H:%M:%S')"
    mkdir -p "$(dirname "$log_file")"
    echo "[$timestamp] $message" >> "$log_file"
}

# Получить список настроенных remote проекта
get_project_remotes() {
    local slug="$1"
    local repo="${HOME}/gitflicProject/projects/${slug}"
    if [ ! -d "$repo/.git" ]; then
        echo ""
        return 1
    fi
    git -C "$repo" remote -v 2>/dev/null | awk '{print $1}' | sort -u
}

# Получить SHA HEAD для remote
get_remote_head() {
    local repo="$1" remote="$2" branch="${3:-master}"
    git -C "$repo" rev-parse "${remote}/${branch}" 2>/dev/null || echo ""
}
