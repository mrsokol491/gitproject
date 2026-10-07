#!/usr/bin/env bash
# gitflic-sync.sh — интерактивная синхронизация проектов контейнера projects/ с Git-хостом.
#
# Вызовы:
#   gitflic-sync.sh                     — интерактивное меню выбора проектов
#   gitflic-sync.sh <slug>              — синхронизация одного проекта без меню
#   gitflic-sync.sh <slug> -m "сообщ."  — то же с сообщением коммита
#   gitflic-sync.sh status              — таблица состояний всех проектов
#   gitflic-sync.sh fix <slug>          — подготовка проекта (init/origin/контроль)
#   gitflic-sync.sh probe               — матрица эндпоинтов API
#
# Владелец репозитория: GITFLIC_USER из .env; репозитории других владельцев
# работают через свой origin (скрипт публикует в upstream локальной ветки).
# Архитектура мульти-хост: базовый хост берётся из GIT_HOST в .env.
# Коды выхода: 0 успех; 2 окружение/аргументы; 3 конфликт слияния; 4 push/хэши; 5 staging.

set -uo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; GRAY='\033[0;90m'; BOLD='\033[1m'; RESET='\033[0m'

print_header()  { echo -e "${BLUE}${BOLD}$1${RESET}"; }
print_success() { echo -e "${GREEN}$1${RESET}"; }
print_error()   { echo -e "${RED}$1${RESET}" >&2; }
print_warn()    { echo -e "${YELLOW}$1${RESET}"; }
print_info()    { echo -e "${CYAN}$1${RESET}"; }
print_gray()    { echo -e "${GRAY}$1${RESET}"; }

WORKSPACE="$HOME/gitproject/projects"
ENV_FILE="$HOME/gitproject/.env"

GIT_HOST=""; GITFLIC_USER=""; GITFLIC_TOKEN=""
API_CODE=""; API_REDIRECT=""
BODY_FILE="$(mktemp)"
trap 'rm -f "$BODY_FILE"' EXIT

load_env() {
    GIT_HOST="$(sed -n 's/^GIT_HOST=//p' "$ENV_FILE" 2>/dev/null | tail -n 1 | tr -d '\r')"
    GITFLIC_USER="$(sed -n 's/^GITFLIC_USER=//p' "$ENV_FILE" 2>/dev/null | tail -n 1 | tr -d '\r')"
    GITFLIC_TOKEN="$(sed -n 's/^GITFLIC_TOKEN=//p' "$ENV_FILE" 2>/dev/null | tail -n 1 | tr -d '\r')"
    GIT_HOST="${GIT_HOST:-gitflic.ru}"
    GITFLIC_USER="${GITFLIC_USER:-sokol491}"
}

repo_url() { echo "https://${GIT_HOST}/project/${GITFLIC_USER}/$1.git"; }

is_local_repo() { [ -d "$1/.git" ] && git -C "$1" rev-parse --is-inside-work-tree >/dev/null 2>&1; }

remote_repo_exists() {
    local output
    output="$(git ls-remote "$(repo_url "$1")" 2>&1)"
    [[ ! "$output" =~ "Repository not found" && ! "$output" =~ "fatal:" && ! "$output" =~ "error:" ]]
}

api_call_url() {
    local method="$1" url="$2" json="${3:-}" out
    local args=(-s -o "$BODY_FILE" -w '%{http_code} %{redirect_url}' -H "Authorization: Bearer ${GITFLIC_TOKEN}")
    if [ -n "$json" ]; then
        args+=(-H 'Content-Type: application/json' -X "$method" --data "$json")
    else
        args+=(-X "$method")
    fi
    out="$(curl "${args[@]}" "$url")"
    API_CODE="${out%% *}"
    API_REDIRECT="${out#* }"
}

project_state() { # slug -> строка состояния для меню
    local dir="$WORKSPACE/$1" branch dirty
    if ! is_local_repo "$dir"; then
        echo "НЕ GIT"
        return
    fi
    branch="$(git -C "$dir" branch --show-current 2>/dev/null)"
    [ -n "$branch" ] || branch="(без коммитов)"
    dirty="$(git -C "$dir" status --porcelain 2>/dev/null | wc -l)"
    if ! remote_repo_exists "$1"; then
        echo "НЕТ REMOTE ($branch)"
    elif [ "$dirty" -gt 0 ]; then
        echo "$branch, изменений: $dirty"
    else
        echo "$branch, чисто"
    fi
}

show_menu() {
    local -a slugs=("$@")
    local i n state
    echo ""
    print_header "=============================================================="
    print_header "  GitFlic Workspace — Sync Manager (хост: ${GIT_HOST})"
    print_header "=============================================================="
    printf "${BLUE}${BOLD}  %-3s %-24s %s${RESET}\n" "N" "ПРОЕКТ" "СОСТОЯНИЕ"
    print_header "--------------------------------------------------------------"
    for i in "${!slugs[@]}"; do
        n=$((i + 1))
        state="$(project_state "${slugs[$i]}")"
        case "$state" in
            НЕ\ GIT*|НЕТ\ REMOTE*) printf "  ${CYAN}${BOLD}%-3s${RESET} ${BOLD}%-24s${RESET} ${RED}%s${RESET}\n" "$n" "${slugs[$i]}" "$state" ;;
            *изменений:*)          printf "  ${CYAN}${BOLD}%-3s${RESET} ${BOLD}%-24s${RESET} ${YELLOW}%s${RESET}\n" "$n" "${slugs[$i]}" "$state" ;;
            *)                     printf "  ${CYAN}${BOLD}%-3s${RESET} ${BOLD}%-24s${RESET} ${GREEN}%s${RESET}\n" "$n" "${slugs[$i]}" "$state" ;;
        esac
    done
    print_header "--------------------------------------------------------------"
    print_info "  Выбор: номера через запятую (1,3), all — все, q — выход"
}

read_choice() {
    local max="$1" choice
    read -r -p "$(echo -e ${CYAN}Ваш выбор: ${RESET})" choice
    case "$choice" in
        q|Q) print_info "Отмена."; exit 0 ;;
        all|ALL) echo all; return 0 ;;
    esac
    if [[ "$choice" =~ ^[0-9]+(,[0-9]+)*$ ]]; then
        local n ok=1
        IFS=',' read -ra nums <<< "$choice"
        for n in "${nums[@]}"; do
            { [ "$n" -ge 1 ] && [ "$n" -le "$max" ]; } || ok=0
        done
        if [ "$ok" -eq 1 ]; then echo "$choice"; return 0; fi
    fi
    print_error "Неверный ввод. Примеры: 1 | 1,3 | all | q"
    read_choice "$max"
}

sync_project() {
    local slug="$1" commit_msg="${2:-}"
    local repo="$WORKSPACE/$slug"
    print_header ""
    print_header "==> Синхронизация: $slug"
    if [ ! -d "$repo/.git" ]; then
        print_error "    $repo не является git-репозиторием (нужен fix или клон)"
        return 1
    fi
    cd "$repo" || return 1
    local branch
    branch="$(git branch --show-current)"
    if [ -z "$branch" ]; then
        print_error "    detached HEAD — синхронизация невозможна"
        return 1
    fi
    if ! { git config --get user.name >/dev/null && git config --get user.email >/dev/null; }; then
        print_error "    не настроена идентичность git (user.name / user.email)"
        return 1
    fi
    if ! remote_repo_exists "$slug"; then
        print_error "    репозиторий $slug на ${GIT_HOST} отсутствует (создайте через Web-UI)"
        return 1
    fi
    if ! git fetch origin >/dev/null 2>&1; then
        print_error "    git fetch origin не удался (сеть/доступ/credential helper)"
        return 1
    fi
    local remote_ref="origin/$branch" has_remote=0
    git show-ref --verify --quiet "refs/remotes/$remote_ref" && has_remote=1
    local local_head ahead_list behind_list ahead_n=0 behind_n=0
    local_head="$(git rev-parse --short HEAD)"
    ahead_list="$(git log --oneline "${remote_ref}..HEAD" 2>/dev/null || true)"
    if [ "$has_remote" -eq 1 ]; then
        behind_list="$(git log --oneline "HEAD..${remote_ref}" 2>/dev/null || true)"
        print_info "    Локально: $local_head | Remote: $(git rev-parse --short "$remote_ref")"
    else
        print_info "    Локально: $local_head | Remote: ветка $branch отсутствует (будет создана)"
    fi
    [ -n "$ahead_list" ] && ahead_n="$(wc -l <<<"$ahead_list")"
    [ -n "${behind_list:-}" ] && behind_n="$(wc -l <<<"$behind_list")"
    print_info "    Впереди: $ahead_n | Позади: $behind_n"

    if [ -n "$(git status --porcelain)" ]; then
        print_info "    Коммит локальных изменений..."
        git add -A
        local forbidden
        forbidden="$(git diff --cached --name-only \
            | grep -E '^data/|^storage/|^vendor/|\.sqlite(-journal|-wal|-shm)?$|\.log$|\.ged$|^\.env($|[^.])|^\.env\.(local|prod|production)$' \
            | grep -vE '^data/\.gitkeep$|\.env\.example$' || true)"
        if [ -n "$forbidden" ]; then
            print_error "    в staging запрещённые пути — коммит отменён:"
            echo "$forbidden" | sed 's/^/      ! /' >&2
            git reset -q
            return 5
        fi
        local msg="$commit_msg"
        [ -z "$msg" ] && msg="sync: $slug $(date +%Y-%m-%d_%H:%M) — локальные изменения"
        git commit -q -m "$msg"
        print_success "    коммит: $(git log --oneline -1)"
    else
        print_gray "    рабочий каталог чист"
    fi

    if [ "$has_remote" -eq 1 ] && [ "$behind_n" -gt 0 ]; then
        print_info "    Интеграция $remote_ref (приоритет локального, merge -X ours)..."
        if ! git merge -X ours "$remote_ref" -m "merge: интеграция $remote_ref, приоритет локальных изменений"; then
            print_error "    слияние остановилось — разрешите вручную (git status)"
            return 3
        fi
    fi

    print_info "    Публикация: $branch -> ${GIT_HOST}..."
    if [ "$has_remote" -eq 1 ]; then
        git push origin "$branch" || return 4
    else
        git push -u origin "$branch" || return 4
    fi
    local local_now remote_now
    local_now="$(git rev-parse HEAD)"
    remote_now="$(git ls-remote origin "refs/heads/$branch" | awk '{print $1}')"
    if [ -n "$remote_now" ] && [ "$local_now" = "$remote_now" ]; then
        print_success "    УСПЕХ: $slug/$branch, хэш ${local_now:0:7} совпадает с remote"
        return 0
    fi
    print_error "    хэши не совпали (локально ${local_now:0:7}, remote ${remote_now:0:7})"
    return 4
}

cmd_status() {
    print_header ""
    print_header "Состояние проектов (хост: ${GIT_HOST}):"
    printf "${BLUE}${BOLD}%-24s %-6s %-7s %-24s %s${RESET}\n" "ПРОЕКТ" "LOCAL" "REMOTE" "ВЕТКА" "ГРЯЗЬ"
    local dir slug local_s remote_s branch dirty
    for dir in "$WORKSPACE"/*/; do
        [ -d "$dir" ] || continue
        slug="$(basename "$dir")"
        local_s="нет"; remote_s="нет"; branch="-"; dirty="-"
        if is_local_repo "$dir"; then
            local_s="да"
            branch="$(git -C "$dir" branch --show-current 2>/dev/null)"
            [ -n "$branch" ] || branch="(без коммитов)"
            dirty="$(git -C "$dir" status --porcelain 2>/dev/null | wc -l)"
        fi
        remote_repo_exists "$slug" && remote_s="да"
        printf "%-24s %-6s %-7s %-24s %s\n" "$slug" "$local_s" "$remote_s" "$branch" "$dirty"
    done
}

cmd_fix() {
    local slug="$1" repo="$WORKSPACE/$1"
    print_header ""
    print_header "==> Подготовка проекта: $slug"
    if [ ! -d "$repo" ]; then
        print_error "    каталог $repo не существует"
        print_info "    клонирование: git clone https://${GIT_HOST}/project/<владелец>/$slug.git $repo"
        return 2
    fi
    if is_local_repo "$repo"; then
        print_success "    локальный git: да"
    else
        git -C "$repo" init -b master && print_success "    локальный git: инициализирован (master)"
    fi
    if ! git -C "$repo" remote get-url origin >/dev/null 2>&1; then
        git -C "$repo" remote add origin "$(repo_url "$slug")"
        print_success "    origin: добавлен ($(repo_url "$slug"))"
    else
        print_info "    origin: $(git -C "$repo" remote get-url origin)"
    fi
    if remote_repo_exists "$slug"; then
        print_success "    ${GIT_HOST}: репозиторий существует"
    else
        print_warn "    ${GIT_HOST}: репозитория нет — создайте через Web-UI"
    fi
}

cmd_probe() {
    print_header ""
    print_header "Матрица эндпоинтов API ${GIT_HOST}:"
    local base path
    for base in "https://${GIT_HOST}/api/v2" "https://${GIT_HOST}/api/v1" "https://${GIT_HOST}/api"; do
        for path in "/user" "/projects" "/projects/${GITFLIC_USER}/archive" "/user/projects"; do
            api_call_url GET "${base}${path}"
            printf "GET  %-58s -> %s %s\n" "${base}${path}" "$API_CODE" "$API_REDIRECT"
        done
        api_call_url POST "${base}/projects" '{"name":"archive"}'
        printf "POST %-57s -> %s %s\n" "${base}/projects name=archive" "$API_CODE" "$API_REDIRECT"
    done
}

# ---------- Разбор аргументов ----------
load_env
MODE="${1:-}"

case "$MODE" in
    status) cmd_status; exit 0 ;;
    fix)    [ -n "${2:-}" ] || { print_error "Укажите slug: gitflic-sync.sh fix <slug>"; exit 2; }
            cmd_fix "$2"; exit $? ;;
    probe)  cmd_probe; exit 0 ;;
    -h|--help|"")
        if [ "$MODE" = "-h" ] || [ "$MODE" = "--help" ]; then
            echo "Использование: gitflic-sync.sh | <slug> [-m сообщ.] | status | fix <slug> | probe"
            exit 0
        fi
        ;;
esac

# Передан slug — работаем без меню
if [ -n "$MODE" ] && [ -d "$WORKSPACE/$MODE" ]; then
    SLUG="$MODE"; shift
    CMSG=""
    if [ "${1:-}" = "-m" ]; then CMSG="${2:-}"; fi
    sync_project "$SLUG" "$CMSG"
    exit $?
fi
if [ -n "$MODE" ]; then
    print_error "Неизвестный проект или режим: $MODE"
    exit 2
fi

# Интерактивное меню
SLUGS=()
for dir in "$WORKSPACE"/*/; do
    [ -d "$dir" ] || continue
    SLUGS+=("$(basename "$dir")")
done
if [ "${#SLUGS[@]}" -eq 0 ]; then
    print_error "Проекты не найдены в $WORKSPACE"
    print_info "Клонирование: git clone https://${GIT_HOST}/project/<владелец>/<slug>.git $WORKSPACE/<slug>"
    exit 2
fi

show_menu "${SLUGS[@]}"
CHOICE="$(read_choice "${#SLUGS[@]}")"

SELECTED=()
if [ "$CHOICE" = "all" ]; then
    SELECTED=("${SLUGS[@]}")
else
    IFS=',' read -ra NUMS <<< "$CHOICE"
    for N in "${NUMS[@]}"; do
        SELECTED+=("${SLUGS[$((N - 1))]}")
    done
fi

print_header ""
print_header "Синхронизация выбранных проектов: ${#SELECTED[@]}"
OK=0; FAIL=0
for SLUG in "${SELECTED[@]}"; do
    if sync_project "$SLUG"; then OK=$((OK + 1)); else FAIL=$((FAIL + 1)); fi
done
echo ""
print_header "=============================================================="
if [ "$FAIL" -eq 0 ]; then
    print_success "  Итог: успешно $OK, ошибок $FAIL"
else
    print_warn "  Итог: успешно $OK, ошибок $FAIL"
fi
print_header "=============================================================="
exit "$FAIL"
