#!/usr/bin/env bash
# gitproject-sync.sh — синхронизация проектов projects/<slug>.
# Вызовы:
#   gitproject-sync.sh                       — интерактивное меню
#   gitproject-sync.sh <slug>                — один проект (origin или первый remote)
#   gitproject-sync.sh <slug> --all          — мульти-push на все существующие remote
#   gitproject-sync.sh <slug> --remote <r>   — push на конкретный remote
#   gitproject-sync.sh <slug> --push         — push на первый remote
#   gitproject-sync.sh <slug> --pull         — pull --ff-only с первого remote
#   gitproject-sync.sh <slug> -m "сообщ."    — сообщение коммита
#   gitproject-sync.sh status [host]         — таблица состояний
#   gitproject-sync.sh fix <slug>            — подготовка проекта
#   gitproject-sync.sh probe                 — матрица хостов
# Push выполняется ТОЛЬКО в существующие на хосте репозитории (ls-remote + кэш 15 мин).

set -uo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../lib/common.sh"

ENV_FILE="${HUB_ROOT}/.env"
HOSTS_JSON="${HUB_ROOT}/config/hosts.json"
export ENV_FILE HOSTS_JSON

load_env

# ============================================================================
# Автодобавление remote проекта из .env (<PREFIX>_PROJECT_TOKEN)
# ============================================================================
ensure_project_remotes_from_env() {
    local repo="$1" slug="$2"
    local added=0
    local line key value prefix token host_id domain url_template owner_var owner url remote_name

    [ -f "$ENV_FILE" ] || return 0
    [ -f "$HOSTS_JSON" ] || return 0

    while IFS= read -r line || [ -n "$line" ]; do
        line="${line%$'\r'}"
        case "$line" in ''|\#*) continue ;; esac
        case "$line" in *=*) ;; *) continue ;; esac
        key="${line%%=*}"
        value="${line#*=}"

        if [[ "$key" =~ ^([A-Z][A-Z_]*)_PROJECT_TOKEN$ ]]; then
            prefix="${BASH_REMATCH[1]}"
            token="$value"
            [ -z "$token" ] && continue
            [ "$token" = "placeholder" ] && continue

            host_id="$(printf '%s' "$prefix" | tr '[:upper:]' '[:lower:]')"
            domain="$(jq -r --arg id "$host_id" '.[$id].host // empty' "$HOSTS_JSON" 2>/dev/null)"
            url_template="$(jq -r --arg id "$host_id" '.[$id].url_template // empty' "$HOSTS_JSON" 2>/dev/null)"
            [ -z "$domain" ] && continue
            [ -z "$url_template" ] && continue

            owner_var="${prefix}_PROJECT_USER"
            owner="$(sed -n "s/^${owner_var}=//p" "$ENV_FILE" 2>/dev/null | tail -n 1 | tr -d '\r')"
            if [ -z "$owner" ] || [ "$owner" = "placeholder" ]; then
                owner="$(sed -n "s/^${prefix}_HUB_USER=//p" "$ENV_FILE" 2>/dev/null | tail -n 1 | tr -d '\r')"
            fi
            if [ -z "$owner" ] || [ "$owner" = "placeholder" ]; then
                print_warn "    Пропускаю ${host_id}: задайте ${owner_var} в .env"
                continue
            fi

            url="${url_template//\{owner\}/$owner}"
            url="${url//\{slug\}/$slug}"
            remote_name="$host_id"

            if ! git -C "$repo" remote get-url -- "$remote_name" >/dev/null 2>&1; then
                print_info "    Добавляю remote: $remote_name ($url)"
                if git -C "$repo" remote add -- "$remote_name" "$url" 2>/dev/null; then
                    added=$((added + 1))
                else
                    print_warn "    Не удалось добавить $remote_name"
                fi
            fi
        fi
    done < "$ENV_FILE"

    if [ "$added" -gt 0 ]; then
        print_success "    ✓ Добавлено remote: $added"
    fi
}

# ============================================================================
# Существование репозитория на remote (ls-remote + кэш 15 мин)
# Возврат 0 = существует, 1 = нет/недоступен.
# ============================================================================
remote_repo_exists() {
    local slug="$1" remote="$2"
    local repo="${PROJECTS_DIR}/${slug}"
    local cache="${HUB_ROOT}/data/remotes-cache.json"
    local key="${slug}/${remote}"
    local url now ttl entry exists tmp

    url="$(git -C "$repo" remote get-url -- "$remote" 2>/dev/null)" || return 1
    [ -n "$url" ] || return 1

    mkdir -p "${HUB_ROOT}/data"
    now="$(date +%s)"

    if [ -f "$cache" ]; then
        entry="$(jq -c --arg k "$key" '.[$k] // empty' "$cache" 2>/dev/null)"
        if [ -n "$entry" ]; then
            ttl="$(printf '%s' "$entry" | jq -r '.ttl // 0')"
            if [ "$now" -lt "$ttl" ]; then
                exists="$(printf '%s' "$entry" | jq -r '.exists')"
                [ "$exists" = "true" ] && return 0 || return 1
            fi
        fi
    fi

    exists=false
    if GIT_TERMINAL_PROMPT=0 timeout 8 git ls-remote -- "$url" HEAD >/dev/null 2>&1; then
        exists=true
    fi

    tmp="$(mktemp)"
    if [ -f "$cache" ]; then
        jq --arg k "$key" --argjson t "$((now + 900))" --argjson e "$exists" \
           '.[$k] = {ttl: $t, exists: $e}' "$cache" > "$tmp" 2>/dev/null || printf '{}' > "$tmp"
    else
        jq -n --arg k "$key" --argjson t "$((now + 900))" --argjson e "$exists" \
           '{($k): {ttl: $t, exists: $e}}' > "$tmp" 2>/dev/null || printf '{}' > "$tmp"
    fi
    mv "$tmp" "$cache"

    [ "$exists" = "true" ] && return 0 || return 1
}

# ============================================================================
# Push/pull одного remote
# ============================================================================
sync_project_remote() {
    local slug="$1" remote="$2" mode="${3:-push}"
    local repo="${PROJECTS_DIR}/${slug}"
    local branch

    [ -d "$repo/.git" ] || { print_error "Проект $slug не найден"; return 1; }
    branch="$(git -C "$repo" branch --show-current 2>/dev/null)"
    [ -n "$branch" ] || { print_error "Не определена текущая ветка"; return 1; }

    if [ "$mode" = "pull" ]; then
        print_info "    Pull: $branch <- $remote"
        if git -C "$repo" pull --ff-only -- "$remote" "$branch" 2>&1; then
            print_success "    ✓ Pull успешен: $remote"
            return 0
        fi
        print_error "    ✗ Pull не удался: $remote"
        return 1
    fi

    if ! remote_repo_exists "$slug" "$remote"; then
        print_warn "    ⚪ Пропуск $remote: репозиторий не существует на хосте"
        return 0
    fi

    print_info "    Push: $branch -> $remote"
    if git -C "$repo" push -- "$remote" "$branch" 2>&1; then
        print_success "    ✓ Push успешен: $remote"
        return 0
    fi
    print_error "    ✗ Push не удался: $remote"
    return 1
}

# ============================================================================
# Мульти-push на все существующие remote
# ============================================================================
sync_project_all_remotes() {
    local slug="$1"
    local repo="${PROJECTS_DIR}/${slug}"
    local branch

    [ -d "$repo/.git" ] || { print_error "Проект $slug не найден"; return 1; }
    branch="$(git -C "$repo" branch --show-current 2>/dev/null)"
    [ -n "$branch" ] || { print_error "Не определена текущая ветка"; return 1; }

    print_header ""
    print_header "==> Мульти-синхронизация: $slug (все remote)"
    print_info "    Ветка: $branch"

    ensure_project_remotes_from_env "$repo" "$slug"

    local -a remotes=()
    while read -r remote; do
        [ -n "$remote" ] && remotes+=("$remote")
    done < <(get_project_remotes "$slug")

    if [ ${#remotes[@]} -eq 0 ]; then
        print_warn "    У проекта нет настроенных remote"
        return 1
    fi
    print_info "    Remote: ${remotes[*]}"

    if [ -n "$(git -C "$repo" status --porcelain)" ]; then
        print_info "    Коммит локальных изменений..."
        git -C "$repo" add -A
        local forbidden
        forbidden="$(git -C "$repo" diff --cached --name-only \
            | grep -E '^data/|^storage/|^vendor/|^node_modules/|\.sqlite(-journal|-wal|-shm)?$|\.log$|\.ged$|^\.env($|[^.])|^\.env\.(local|prod|production)$' \
            | grep -vE '^data/\.gitkeep$|^storage/\.gitkeep$|\.env\.example$' \
            || true)"
        if [ -n "$forbidden" ]; then
            print_error "    В staging запрещённые пути:"
            echo "$forbidden" | sed 's/^/      ! /' >&2
            git -C "$repo" reset -q
            return 5
        fi
        git -C "$repo" commit -q -m "sync: $slug $(date +%Y-%m-%d_%H:%M)"
        print_success "    ✓ Коммит создан"
    else
        print_gray "    Рабочий каталог чист"
    fi

    local success=0 fail=0 skipped=0
    for remote in "${remotes[@]}"; do
        print_info ""
        if ! remote_repo_exists "$slug" "$remote"; then
            print_warn "    ⚪ Пропуск $remote: репозиторий не существует на хосте"
            skipped=$((skipped + 1))
            continue
        fi
        print_info "    Публикация: $branch -> $remote"
        if git -C "$repo" push -- "$remote" "$branch" 2>/dev/null; then
            print_success "    ✓ УСПЕХ: $slug/$branch -> $remote"
            success=$((success + 1))
        else
            print_error "    ✗ Ошибка push на $remote"
            fail=$((fail + 1))
        fi
    done

    print_info ""
    if [ "$fail" -eq 0 ]; then
        print_success "    Мульти-push завершён: $success успешно, $skipped пропущено"
    else
        print_warn "    Мульти-push с ошибками: $success успешно, $fail ошибок, $skipped пропущено"
    fi
    return "$fail"
}

# ============================================================================
# Таблица состояний проектов
# ============================================================================
show_status() {
    local host_filter="${1:-}"

    print_header ""
    if [ -n "$host_filter" ]; then
        print_header "Состояние проектов (хост: $host_filter)"
    else
        print_header "Состояние проектов (все хосты)"
    fi
    print_header ""

    if [ ! -d "$PROJECTS_DIR" ]; then
        print_warn "Папка projects/ не найдена"
        return 0
    fi

    printf "${BLUE}${BOLD}%-24s %-8s %-8s %-14s %s${RESET}\n" "ПРОЕКТ" "LOCAL" "REMOTE" "ВЕТКА" "ГРЯЗЬ"
    printf "${BLUE}%-24s %-8s %-8s %-14s %s${RESET}\n" "------------------------" "--------" "--------" "--------------" "-----"

    local found=0
    for dir in "$PROJECTS_DIR"/*/; do
        [ -d "$dir" ] || continue
        [ -d "$dir/.git" ] || continue

        local slug branch local_sha remote_name remote_sha dirty color
        slug="$(basename "$dir")"
        branch="$(git -C "$dir" branch --show-current 2>/dev/null || echo '?')"
        local_sha="$(git -C "$dir" rev-parse --short=6 HEAD 2>/dev/null || echo '?')"
        dirty="$(git -C "$dir" status --porcelain 2>/dev/null | wc -l | tr -d ' ')"

        remote_name="$(git -C "$dir" remote 2>/dev/null | head -n 1)"
        remote_sha="—"
        if [ -n "$remote_name" ]; then
            remote_sha="$(git -C "$dir" rev-parse --short=6 "$remote_name/$branch" 2>/dev/null || echo '—')"
        fi

        color="$WHITE"
        if [ "$dirty" -gt 0 ]; then
            color="$YELLOW"
        elif [ "$remote_sha" != "—" ] && [ "$local_sha" != "$remote_sha" ]; then
            color="$CYAN"
        fi

        printf "${color}%-24s %-8s %-8s %-14s %s${RESET}\n" "$slug" "$local_sha" "$remote_sha" "$branch" "$dirty"
        found=$((found + 1))
    done

    if [ "$found" -eq 0 ]; then
        print_gray "    Проектов не найдено"
    fi
    print_header ""
}

# ============================================================================
# Подготовка проекта (fix)
# ============================================================================
fix_project() {
    local slug="$1"
    local repo="${PROJECTS_DIR}/${slug}"

    [ -d "$repo/.git" ] || { print_error "Проект $slug не найден"; return 1; }

    print_header "==> Подготовка проекта: $slug"
    ensure_project_remotes_from_env "$repo" "$slug"

    local -a remotes=()
    while read -r remote; do
        [ -n "$remote" ] && remotes+=("$remote")
    done < <(get_project_remotes "$slug")

    if [ ${#remotes[@]} -gt 0 ]; then
        print_info "    Fetch со всех remote..."
        for remote in "${remotes[@]}"; do
            git -C "$repo" fetch -- "$remote" 2>&1 | tail -n 1
        done
        print_success "    ✓ Fetch завершён"
    fi

    local branch
    branch="$(git -C "$repo" branch --show-current 2>/dev/null)"
    if [ -z "$branch" ]; then
        print_warn "    Ветка не определена, создаю master"
        git -C "$repo" checkout -b master 2>&1
    else
        print_info "    Ветка: $branch"
    fi

    print_success "    Проект $slug готов к работе"
}

# ============================================================================
# Матрица хостов (probe)
# ============================================================================
probe_api() {
    print_header "==> Матрица API хостов"
    [ -f "$HOSTS_JSON" ] || { print_error "hosts.json не найден"; return 1; }

    local ids
    ids="$(jq -r 'keys[]' "$HOSTS_JSON" 2>/dev/null)"
    [ -n "$ids" ] || { print_error "hosts.json пуст"; return 1; }

    printf "${BLUE}${BOLD}%-14s %-24s %-12s %-10s %s${RESET}\n" "ID" "ДОМЕН" "СТАТУС" "РЕЕСТР" "URL"
    printf "${BLUE}%-14s %-24s %-12s %-10s %s${RESET}\n" "--------------" "------------------------" "------------" "----------" "--------------------"

    for id in $ids; do
        local domain status registry url_template color
        domain="$(jq -r --arg id "$id" '.[$id].host // "?"' "$HOSTS_JSON")"
        status="$(jq -r --arg id "$id" '.[$id].status // "?"' "$HOSTS_JSON")"
        registry="$(jq -r --arg id "$id" '.[$id].registry // "?"' "$HOSTS_JSON")"
        url_template="$(jq -r --arg id "$id" '.[$id].url_template // "?"' "$HOSTS_JSON")"

        color="$WHITE"
        case "$status" in
            ready) color="$GREEN" ;;
            integrating) color="$YELLOW" ;;
            *) color="$RED" ;;
        esac

        printf "${color}%-14s %-24s %-12s %-10s %s${RESET}\n" "$id" "$domain" "$status" "$registry" "$url_template"
    done
    print_header ""
}

# ============================================================================
# Интерактивное меню
# ============================================================================
interactive_menu() {
    print_header "==> GitProject Workspace Hub — синхронизация проектов"

    local -a projects=()
    if [ -d "$PROJECTS_DIR" ]; then
        for dir in "$PROJECTS_DIR"/*/; do
            [ -d "$dir/.git" ] && projects+=("$(basename "$dir")")
        done
    fi

    if [ ${#projects[@]} -eq 0 ]; then
        print_warn "Проектов не найдено в $PROJECTS_DIR"
        return 1
    fi

    print_info "Доступные проекты:"
    local i=1
    for p in "${projects[@]}"; do
        printf "  %d. %s\n" "$i" "$p"
        i=$((i + 1))
    done
    echo ""
    printf "Выберите номер (1-%d) или 'q' для выхода: " "${#projects[@]}"
    read -r choice

    case "$choice" in
        q|Q|exit) echo "Выход"; return 0 ;;
        '') print_error "Пустой выбор"; return 1 ;;
    esac

    if [[ "$choice" =~ ^[0-9]+$ ]] && [ "$choice" -ge 1 ] && [ "$choice" -le "${#projects[@]}" ]; then
        local slug="${projects[$((choice - 1))]}"
        print_info "Выбран проект: $slug"
        sync_project_all_remotes "$slug"
    else
        print_error "Неверный номер"
        return 1
    fi
}

# ============================================================================
# Главная функция
# ============================================================================
main() {
    local slug=""
    local mode="single"
    local remote=""
    local message=""

    while [[ $# -gt 0 ]]; do
        case "$1" in
            --all) mode="all"; shift ;;
            --push) mode="push"; shift ;;
            --pull) mode="pull"; shift ;;
            --remote)
                remote="${2:-}"
                if [ "$mode" != "pull" ]; then mode="specific"; fi
                [ -z "$remote" ] && { print_error "--remote требует аргумент"; exit 2; }
                shift 2
                ;;
            -m)
                message="${2:-}"
                [ -z "$message" ] && { print_error "-m требует аргумент"; exit 2; }
                shift 2
                ;;
            status)
                show_status "${2:-}"
                exit 0
                ;;
            fix)
                slug="${2:-}"
                [ -z "$slug" ] && { print_error "fix требует <slug>"; exit 2; }
                validate_slug "$slug" || exit 2
                fix_project "$slug"
                exit $?
                ;;
            probe)
                probe_api
                exit 0
                ;;
            -*)
                print_error "Неизвестный аргумент: $1"
                exit 2
                ;;
            *)
                if [ -z "$slug" ]; then
                    slug="$1"
                else
                    print_error "Слишком много аргументов: $1"
                    exit 2
                fi
                shift
                ;;
        esac
    done

    if [ -z "$slug" ]; then
        interactive_menu
        exit $?
    fi

    validate_slug "$slug" || exit 2

    local repo="${PROJECTS_DIR}/${slug}"
    if [ ! -d "$repo/.git" ]; then
        print_error "Проект $slug не найден в $PROJECTS_DIR/"
        exit 1
    fi

    case "$mode" in
        all)
            sync_project_all_remotes "$slug"
            exit $?
            ;;
        specific)
            sync_project_remote "$slug" "$remote" "push"
            exit $?
            ;;
        push)
            local first_remote
            first_remote="$(get_project_remotes "$slug" | head -n 1)"
            [ -n "$first_remote" ] || { print_error "Нет remote у проекта $slug"; exit 1; }
            sync_project_remote "$slug" "$first_remote" "push"
            exit $?
            ;;
        pull)
            local src_remote
            src_remote="${remote:-$(get_project_remotes "$slug" | head -n 1)}"
            [ -n "$src_remote" ] || { print_error "Нет remote у проекта $slug"; exit 1; }
            sync_project_remote "$slug" "$src_remote" "pull"
            exit $?
            ;;
        single)
            if git -C "$repo" remote get-url -- origin >/dev/null 2>&1; then
                sync_project_remote "$slug" "origin" "push"
            else
                local first_remote
                first_remote="$(get_project_remotes "$slug" | head -n 1)"
                [ -n "$first_remote" ] || { print_error "Нет remote у проекта $slug"; exit 1; }
                sync_project_remote "$slug" "$first_remote" "push"
            fi
            exit $?
            ;;
        *)
            print_error "Неизвестный режим: $mode"
            exit 2
            ;;
    esac
}

main "$@"