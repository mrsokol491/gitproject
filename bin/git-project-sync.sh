#!/usr/bin/env bash
# git-project-sync.sh — синхронизация проектов projects/<slug>.
# Вызовы:
#   git-project-sync.sh                       — интерактивное меню
#   git-project-sync.sh <slug>                — один проект (только origin)
#   git-project-sync.sh <slug> --all          — один проект на все remote
#   git-project-sync.sh <slug> --remote <r>   — один проект на конкретный remote
#   git-project-sync.sh <slug> -m "сообщ."    — с сообщением коммита
#   git-project-sync.sh status                — таблица состояний
#   git-project-sync.sh fix <slug>            — подготовка проекта
#   git-project-sync.sh probe                 — матрица API

set -uo pipefail

source "$HOME/gitproject/bin/lib/common.sh"

# ============================================================================
# Автоматическое добавление remote проекта из .env
# ============================================================================
ensure_project_remotes_from_env() {
    local repo="$1" slug="$2"
    local added=0
    local env_file="$HOME/gitproject/.env"
    
    while IFS='=' read -r key value; do
        [[ "$key" =~ ^#.*$ || -z "$key" ]] && continue
        
        if [[ "$key" =~ ^([A-Z_]+)_PROJECT_TOKEN=(.+)$ ]]; then
            local prefix="${BASH_REMATCH[1]}"
            local token="${BASH_REMATCH[2]}"
            
            [[ "$token" == "placeholder" || -z "$token" ]] && continue
            
            local host_id domain url_template owner_var owner
            host_id="$(echo "$prefix" | tr '[:upper:]' '[:lower:]' | sed 's/_$//')"
            
            if [ -f "$HOME/gitproject/config/hosts.json" ]; then
                domain="$(jq -r --arg id "$host_id" '.[$id].host // empty' "$HOME/gitproject/config/hosts.json" 2>/dev/null)"
                url_template="$(jq -r --arg id "$host_id" '.[$id].url_template // empty' "$HOME/gitproject/config/hosts.json" 2>/dev/null)"
            fi
            
            [ -z "$domain" ] && continue
            
            owner_var="${prefix}_PROJECT_USER"
            owner="$(sed -n "s/^${owner_var}=//p" "$env_file" 2>/dev/null | tail -n 1 | tr -d '
')"
            [ -z "$owner" ] && owner="sokol491"
            
            local url="${url_template//\{owner\}/$owner}"
            url="${url//\{slug\}/$slug}"
            
            local remote_name="$host_id"
            if ! git -C "$repo" remote get-url "$remote_name" >/dev/null 2>&1; then
                print_info "    Добавляю remote: $remote_name ($url)"
                if git -C "$repo" remote add "$remote_name" "$url" 2>/dev/null; then
                    ((added++))
                else
                    print_warn "    ⚠ Не удалось добавить $remote_name"
                fi
            fi
        fi
    done < "$env_file"
    
    if [ $added -gt 0 ]; then
        print_success "    ✓ Добавлено remote: $added"
    fi
}


# ============================================================================
# Умная синхронизация всех remote (Этап 3 ТЗ-001 v2)
# ============================================================================

sync_project_all_remotes() {
    local slug="$1"
    local repo="$PROJECTS_DIR/$slug"
    if [ ! -d "$repo/.git" ]; then
        print_error "Проект $slug не найден"
        return 1
    fi
    local branch
    branch="$(git -C "$repo" branch --show-current 2>/dev/null)"
    [ -n "$branch" ] || { print_error "Не определена текущая ветка"; return 1; }

    print_header ""
    print_header "==> Мульти-синхронизация: $slug (все remote)"
    print_info "    Ветка: $branch"

    local -a remotes=()
    while read -r remote; do
        [ -n "$remote" ] && remotes+=("$remote")
    done < <(get_project_remotes "$slug")

    if [ ${#remotes[@]} -eq 0 ]; then
        print_warn "    У проекта нет настроенных remote"
        return 1
    fi
    print_info "    Remote: ${remotes[*]}"
    
    # Автоматически добавляем missing remote из .env
    ensure_project_remotes_from_env "$repo" "$slug"
    
    # Пересчитываем список remote после возможного добавления
    remotes=()
    while read -r remote; do
        [ -n "$remote" ] && remotes+=("$remote")
    done < <(get_project_remotes "$slug")

    # 1. Коммит локальных изменений
    if [ -n "$(git -C "$repo" status --porcelain)" ]; then
        print_info "    Коммит локальных изменений..."
        git -C "$repo" add -A
        local forbidden
        forbidden="$(git -C "$repo" diff --cached --name-only | grep -E '^data/|^storage/|^vendor/|\.sqlite(-journal|-wal|-shm)?$|\.log$|\.ged$|^\.env($|[^.])|^\.env\.(local|prod|production)$' | grep -vE '^data/\.gitkeep$|\.env\.example$' || true)"
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

    local local_sha="$(git -C "$repo" rev-parse HEAD)"
    local success=0 fail=0

    for remote in "${remotes[@]}"; do
        print_info ""
        print_info "    Синхронизация с $remote..."
        git -C "$repo" fetch "$remote" >/dev/null 2>&1 || true

        local remote_ref="refs/remotes/$remote/$branch"
        if ! git -C "$repo" show-ref --verify --quiet "$remote_ref" 2>/dev/null; then
            print_info "    $remote: ветка отсутствует, публикация..."
            if git -C "$repo" push "$remote" "$branch" >/dev/null 2>&1; then
                print_success "    ✓ УСПЕХ: $remote инициализирован"
                ((success++))
            else
                print_error "    ✗ Ошибка push на $remote"
                ((fail++))
            fi
            continue
        fi

        local remote_sha="$(git -C "$repo" rev-parse "$remote_ref")"
        if [ "$local_sha" = "$remote_sha" ]; then
            print_gray "    $remote: версии совпадают, пропуск"
            ((success++))
            continue
        fi

        local mb="$(git -C "$repo" merge-base "$local_sha" "$remote_sha" 2>/dev/null || echo '')"
        if [ "$mb" = "$remote_sha" ]; then
            print_info "    $remote: локальная версия впереди, fast-forward..."
            if git -C "$repo" push "$remote" "$branch" >/dev/null 2>&1; then
                print_success "    ✓ УСПЕХ: $remote обновлён"
                ((success++))
            else
                print_error "    ✗ Ошибка push на $remote"
                ((fail++))
            fi
        else
            print_warn "    $remote: истории расходятся, слияние с приоритетом локальной версии..."
            if git -C "$repo" merge --allow-unrelated-histories -X ours "$remote_sha" -m "sync: merge $remote, приоритет локальной версии" >/dev/null 2>&1; then
                if git -C "$repo" push "$remote" "$branch" >/dev/null 2>&1; then
                    print_success "    ✓ УСПЕХ: $remote получил слияние и обновлён"
                    ((success++))
                else
                    print_error "    ✗ Ошибка push после слияния"
                    git -C "$repo" merge --abort 2>/dev/null || true
                    ((fail++))
                fi
            else
                print_error "    ✗ Ошибка слияния с $remote"
                git -C "$repo" merge --abort 2>/dev/null || true
                ((fail++))
            fi
        fi
    done

    print_header ""
    if [ $fail -eq 0 ]; then
        print_success "    Мульти-синхронизация завершена успешно: $success remote"
    else
        print_warn "    Мульти-синхронизация завершена с ошибками: $success успешно, $fail ошибок"
    fi
    return $fail
}

# ============================================================================
# Мультипуш: commit + push на все remote (без fetch/merge)
# ============================================================================

sync_project_push_all() {
    local slug="$1"
    local repo="$PROJECTS_DIR/$slug"
    if [ ! -d "$repo/.git" ]; then
        print_error "Проект $slug не найден"
        return 1
    fi
    local branch
    branch="$(git -C "$repo" branch --show-current 2>/dev/null)"
    [ -n "$branch" ] || { print_error "Не определена текущая ветка"; return 1; }

    print_header ""
    print_header "==> Мультипуш: $slug (все remote)"
    print_info "    Ветка: $branch"

    # Автоматически добавляем missing remote из .env
    ensure_project_remotes_from_env "$repo" "$slug"

    # Пересчитываем список remote
    local -a remotes=()
    while read -r remote; do
        [ -n "$remote" ] && remotes+=("$remote")
    done < <(get_project_remotes "$slug")

    if [ ${#remotes[@]} -eq 0 ]; then
        print_warn "    У проекта нет настроенных remote"
        return 1
    fi
    print_info "    Remote: ${remotes[*]}"

    # 1. Коммит локальных изменений
    if [ -n "$(git -C "$repo" status --porcelain)" ]; then
        print_info "    Коммит локальных изменений..."
        git -C "$repo" add -A
        local forbidden
        forbidden="$(git -C "$repo" diff --cached --name-only | grep -E '^data/|^storage/|^vendor/|\.sqlite(-journal|-wal|-shm)?$|\.log$|\.ged$|^\.env($|[^.])|^\.env\.(local|prod|production)$' | grep -vE '^data/\.gitkeep$|\.env\.example$' || true)"
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

    local success=0 fail=0
    for remote in "${remotes[@]}"; do
        print_info "    Push -> $remote..."
        if git -C "$repo" push "$remote" "$branch" >/dev/null 2>&1; then
            print_success "    ✓ УСПЕХ: $remote"
            ((success++))
        else
            print_error "    ✗ Ошибка push на $remote"
            ((fail++))
        fi
    done

    print_header ""
    if [ $fail -eq 0 ]; then
        print_success "    Мультипуш завершён: $success remote"
    else
        print_warn "    Мультипуш с ошибками: $success успешно, $fail ошибок"
    fi
    return $fail
}

# ============================================================================
# Синхронизация конкретного remote
# ============================================================================

sync_project_specific_remote() {
    local slug="$1" remote="$2"
    local repo="$PROJECTS_DIR/$slug"
    if [ ! -d "$repo/.git" ]; then
        print_error "Проект $slug не найден"
        return 1
    fi
    if ! git -C "$repo" remote get-url "$remote" >/dev/null 2>&1; then
        print_error "Remote $remote не настроен для проекта $slug"
        print_info "Добавьте: git-project-remote.sh $slug --add <domain>"
        return 1
    fi
    local branch
    branch="$(git -C "$repo" branch --show-current 2>/dev/null)"
    [ -n "$branch" ] || { print_error "Не определена текущая ветка"; return 1; }

    print_header ""
    print_header "==> Синхронизация: $slug -> $remote"
    print_info "    Ветка: $branch"

    if git -C "$repo" push "$remote" "$branch"; then
        print_success "    ✓ УСПЕХ: $slug/$branch -> $remote"
        return 0
    else
        print_error "    ✗ Ошибка push на $remote"
        return 1
    fi
}

# ============================================================================
# Pull updates from a specific remote
# ============================================================================

pull_project_remote() {
    local slug="$1" remote="${2:-origin}"
    local repo="$PROJECTS_DIR/$slug"
    if [ ! -d "$repo/.git" ]; then
        print_error "Проект $slug не найден"
        return 1
    fi
    if ! git -C "$repo" remote get-url "$remote" >/dev/null 2>&1; then
        print_error "Remote $remote не настроен для проекта $slug"
        print_info "Добавьте: git-project-remote.sh $slug --add <domain>"
        return 1
    fi
    local branch
    branch="$(git -C "$repo" branch --show-current 2>/dev/null)"
    [ -n "$branch" ] || { print_error "Не определена текущая ветка"; return 1; }

    print_header ""
    print_header "==> Pull из $remote: $slug"
    print_info "    Ветка: $branch"

    # Fetch remote
    print_info "    Получение изменений..."
    local fetchOutput=()
    if ! git -C "$repo" fetch "$remote" "$branch" 2>&1; then
        print_error "    Ошибка fetch с $remote"
        return 1
    fi

    local remote_ref="refs/remotes/$remote/$branch"
    if ! git -C "$repo" show-ref --verify --quiet "$remote_ref" 2>/dev/null; then
        print_info "    Ветка $remote/$branch отсутствует"
        return 0
    fi

    local local_sha remote_sha
    local_sha="$(git -C "$repo" rev-parse HEAD)"
    remote_sha="$(git -C "$repo" rev-parse "$remote_ref")"

    if [ "$local_sha" = "$remote_sha" ]; then
        print_success "    Версии совпадают, обновлений нет"
        return 0
    fi

    # Check if fast-forward is possible
    local mb
    mb="$(git -C "$repo" merge-base "$local_sha" "$remote_sha" 2>/dev/null || echo '')"
    if [ "$mb" = "$remote_sha" ]; then
        print_info "    Локальная версия впереди, пропуск pull"
        return 0
    fi

    # Show summary of changes
    local changed_files
    changed_files="$(git -C "$repo" diff --stat "$local_sha"..."$remote_sha" 2>/dev/null)"
    local file_count
    file_count="$(echo "$changed_files" | tail -1 | grep -oP '\d+(?= file)' || echo '0')"
    print_info "    Изменений: $file_count файлов"
    print_info "    ---"
    echo "$changed_files" | head -20 | while IFS= read -r line; do
        print_info "      $line"
    done
    print_info "    ---"

    # Apply merge
    print_info "    Применение merge..."
    if git -C "$repo" merge --no-edit "$remote_sha" -m "merge: pull $remote/$branch" 2>&1; then
        print_success "    Pull из $remote завершён успешно"
        return 0
    else
        print_error "    Конфликт слияния!"
        git -C "$repo" merge --abort 2>/dev/null || true
        return 1
    fi
}

# ============================================================================
# Существующие функции (status, fix, probe, интерактивное меню)
# ============================================================================

is_local_repo() { [ -d "$1/.git" ] && git -C "$1" rev-parse --is-inside-work-tree >/dev/null 2>&1; }

remote_repo_exists_for_url() {
    local output
    output="$(git ls-remote "$1" 2>&1)"
    [[ ! "$output" =~ "Repository not found" && ! "$output" =~ "fatal:" && ! "$output" =~ "error:" ]]
}

remote_repo_exists() {
    local url
    url="$(build_repo_url "$GIT_HOST" "$GITFLIC_USER" "$1")" || return 1
    remote_repo_exists_for_url "$url"
}

project_state() {
    local dir="$PROJECTS_DIR/$1" branch dirty
    if ! is_local_repo "$dir"; then
        echo "НЕ GIT"; return
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
    print_header "  GitProject — Sync Manager (хост: ${GIT_HOST})"
    print_header "=============================================================="
    printf "${BLUE}${BOLD}  %-3s %-24s %s${RESET}\n" "N" "ПРОЕКТ" "СОСТОЯНИЕ"
    print_gray "  ─────────────────────────────────────────────────────────────────────"
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
    print_info "  Выбор: номера через запятую (1,3), all, q"
}

read_choice() {
    local max="$1" choice
    read -r -p "$(echo -e "${CYAN}Ваш выбор: ${RESET}")" choice
    case "$choice" in q|Q) print_info "Отмена."; exit 0 ;; all|ALL) echo all; return 0 ;; esac
    if [[ "$choice" =~ ^[0-9]+(,[0-9]+)*$ ]]; then
        local ok=1 n
        IFS=',' read -ra nums <<< "$choice"
        for n in "${nums[@]}"; do { [ "$n" -ge 1 ] && [ "$n" -le "$max" ]; } || ok=0; done
        if [ "$ok" -eq 1 ]; then echo "$choice"; return 0; fi
    fi
    print_error "Неверный ввод. Примеры: 1 | 1,3 | all | q"
    read_choice "$max"
}

sync_project_origin() {
    local slug="$1" commit_msg="${2:-}"
    local repo="$PROJECTS_DIR/$slug"
    print_header ""
    print_header "==> Синхронизация: $slug (origin)"
    [ -d "$repo/.git" ] || { print_error "    $repo не является git-репозиторием"; return 1; }
    cd "$repo" || return 1
    local branch
    branch="$(git branch --show-current)"
    [ -n "$branch" ] || { print_error "    detached HEAD"; return 1; }
    { git config --get user.name >/dev/null && git config --get user.email >/dev/null; } \
        || { print_error "    не настроена идентичность git"; return 1; }
    remote_repo_exists "$slug" || { print_error "    репозиторий на ${GIT_HOST} отсутствует"; return 1; }
    git fetch origin >/dev/null 2>&1 || { print_error "    git fetch origin не удался"; return 1; }

    local remote_ref="origin/$branch" has_remote=0
    git show-ref --verify --quiet "refs/remotes/$remote_ref" && has_remote=1

    if [ -n "$(git status --porcelain)" ]; then
        print_info "    Коммит локальных изменений..."
        git add -A
        local forbidden
        forbidden="$(git diff --cached --name-only \
            | grep -E '^data/|^storage/|^vendor/|\.sqlite(-journal|-wal|-shm)?$|\.log$|\.ged$|^\.env($|[^.])|^\.env\.(local|prod|production)$' \
            | grep -vE '^data/\.gitkeep$|\.env\.example$' || true)"
        if [ -n "$forbidden" ]; then
            print_error "    в staging запрещённые пути"; echo "$forbidden" | sed 's/^/      ! /' >&2
            git reset -q; return 5
        fi
        local msg="$commit_msg"
        [ -z "$msg" ] && msg="sync: $slug $(date +%Y-%m-%d_%H:%M)"
        git commit -q -m "$msg"
        print_success "    коммит: $(git log --oneline -1)"
    else
        print_gray "    рабочий каталог чист"
    fi

    if [ "$has_remote" -eq 1 ]; then
        local behind_n
        behind_n="$(git log --oneline "HEAD..${remote_ref}" 2>/dev/null | wc -l)"
        if [ "$behind_n" -gt 0 ]; then
            print_info "    Интеграция $remote_ref (приоритет локального, -X ours)..."
            git merge -X ours "$remote_ref" -m "merge: интеграция $remote_ref" || return 3
        fi
    fi

    if [ "$has_remote" -eq 1 ]; then
        git push origin "$branch" || return 4
    else
        git push -u origin "$branch" || return 4
    fi
    print_success "    УСПЕХ: $slug/$branch синхронизирован (origin)"
    return 0
}

cmd_status() {
    print_header ""
    print_header "Состояние проектов (хост: ${GIT_HOST}):"
    printf "${BLUE}${BOLD}%-24s %-6s %-7s %-24s %s${RESET}\n" "ПРОЕКТ" "LOCAL" "REMOTE" "ВЕТКА" "ГРЯЗЬ"
    local dir slug local_s remote_s branch dirty
    for dir in "$PROJECTS_DIR"/*/; do
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
    local slug="$1" repo="$PROJECTS_DIR/$1"
    print_header ""
    print_header "==> Подготовка: $slug"
    [ -d "$repo" ] || { print_error "    $repo не существует"; return 2; }
    is_local_repo "$repo" && print_success "    git: да" || { git -C "$repo" init -b master && print_success "    git: инициализирован"; }
    if ! git -C "$repo" remote get-url origin >/dev/null 2>&1; then
        local url
        url="$(build_repo_url "$GIT_HOST" "$GITFLIC_USER" "$slug")"
        git -C "$repo" remote add origin "$url" && print_success "    origin: добавлен ($url)"
    else
        print_info "    origin: $(git -C "$repo" remote get-url origin)"
    fi
    remote_repo_exists "$slug" && print_success "    хост: репозиторий существует" || print_warn "    хост: репозитория нет"
}

cmd_probe() {
    print_header ""
    print_header "Матрица API ${GIT_HOST}:"
    local base path
    for base in "https://${GIT_HOST}/api/v2" "https://${GIT_HOST}/api/v1" "https://${GIT_HOST}/api"; do
        for path in "/user" "/projects" "/projects/${GITFLIC_USER}/archive" "/user/projects"; do
            api_call_url GET "${base}${path}"
            printf "GET  %-58s -> %s %s\n" "${base}${path}" "$API_CODE" "$API_REDIRECT"
        done
    done
}

# ============================================================================
# Главная функция
# ============================================================================

main() {
    load_env

    local sync_all=false sync_push=false sync_pull=false sync_remote="" slugs=() commit_msg="" mode="" rest=()

    while [ $# -gt 0 ]; do
        case "$1" in
            --all)     sync_all=true; shift ;;
            --push)    sync_push=true; shift ;;
            --pull)    sync_pull=true; shift ;;
            --remote)  sync_remote="${2:-}"; [ -n "$sync_remote" ] && shift 2 || { print_error "--remote требует аргумент"; exit 2; } ;;
            -m)        commit_msg="${2:-}"; [ -n "$commit_msg" ] && shift 2 || shift ;;
            status|fix|probe) mode="$1"; shift ;;
            -h|--help)
                echo "Использование:"
                echo "  git-project-sync.sh                         # интерактивное меню"
                echo "  git-project-sync.sh <slug>                  # только origin (push)"
                echo "  git-project-sync.sh <slug> --all            # push на все remote (с fetch/merge)"
                echo "  git-project-sync.sh <slug> --push           # push на все remote (без fetch/merge)"
                echo "  git-project-sync.sh <slug> --remote <r>     # push на конкретный remote"
                echo "  git-project-sync.sh <slug> --pull           # pull из origin"
                echo "  git-project-sync.sh <slug> --pull --remote <r>  # pull из конкретного remote"
                echo "  git-project-sync.sh <slug> -m 'сообщение'"
                echo "  git-project-sync.sh status | fix <slug> | probe"
                exit 0
                ;;
            -*)        print_error "Неизвестный флаг: $1"; exit 2 ;;
            *)         rest+=("$1"); shift ;;
        esac
    done

    case "$mode" in
        status) cmd_status; exit 0 ;;
        fix)    [ -n "${rest[0]:-}" ] || { print_error "fix требует slug"; exit 2; }; cmd_fix "${rest[0]}"; exit $? ;;
        probe)  cmd_probe; exit 0 ;;
    esac

    if [ "$sync_all" = true ] || [ "$sync_push" = true ] || [ "$sync_pull" = true ] || [ -n "$sync_remote" ]; then
        if [ ${#rest[@]} -eq 0 ]; then
            print_error "Укажите проект(ы) для синхронизации"
            exit 2
        fi
        local fail=0
        for slug in "${rest[@]}"; do
            if [ "$sync_all" = true ]; then
                sync_project_all_remotes "$slug" || fail=$((fail + 1))
            elif [ "$sync_push" = true ]; then
                sync_project_push_all "$slug" || fail=$((fail + 1))
            elif [ "$sync_pull" = true ]; then
                if [ -n "$sync_remote" ]; then
                    pull_project_remote "$slug" "$sync_remote" || fail=$((fail + 1))
                else
                    pull_project_remote "$slug" "origin" || fail=$((fail + 1))
                fi
            else
                sync_project_specific_remote "$slug" "$sync_remote" || fail=$((fail + 1))
            fi
        done
        exit $fail
    fi

    if [ ${#rest[@]} -eq 1 ]; then
        sync_project_origin "${rest[0]}" "$commit_msg"
        exit $?
    fi
    if [ ${#rest[@]} -gt 1 ]; then
        print_error "Укажите один проект или используйте --all для нескольких"
        exit 2
    fi

    local -a SLUGS=()
    for dir in "$PROJECTS_DIR"/*/; do
        [ -d "$dir" ] || continue
        SLUGS+=("$(basename "$dir")")
    done
    if [ "${#SLUGS[@]}" -eq 0 ]; then
        print_error "Проекты не найдены в $PROJECTS_DIR"
        exit 2
    fi

    show_menu "${SLUGS[@]}"
    local CHOICE
    CHOICE="$(read_choice "${#SLUGS[@]}")"

    local -a SELECTED=()
    if [ "$CHOICE" = "all" ]; then
        SELECTED=("${SLUGS[@]}")
    else
        IFS=',' read -ra NUMS <<< "$CHOICE"
        for N in "${NUMS[@]}"; do
            SELECTED+=("${SLUGS[$((N - 1))]}")
        done
    fi

    print_header ""
    print_header "Синхронизация: ${#SELECTED[@]} проект(ов)"
    local OK=0 FAIL=0
    for SLUG in "${SELECTED[@]}"; do
        if sync_project_origin "$SLUG" "$commit_msg"; then OK=$((OK + 1)); else FAIL=$((FAIL + 1)); fi
    done
    echo ""
    print_header "=============================================================="
    [ $FAIL -eq 0 ] && print_success "  Итог: успешно $OK, ошибок $FAIL" \
                    || print_warn "  Итог: успешно $OK, ошибок $FAIL"
    print_header "=============================================================="
    exit $FAIL
}

main "$@"
