#!/usr/bin/env bash
# git-project-remote.sh — управление remote проекта с подсветкой актуальности.
#
# Вызовы:
#   git-project-remote.sh <slug> --list              # список remote с актуальностью
#   git-project-remote.sh <slug> --add <host>        # добавить remote (хост из config/hosts.json)
#   git-project-remote.sh <slug> --add <host> --owner <owner>  # с явным owner
#   git-project-remote.sh <slug> --remove <remote>   # удалить remote

set -uo pipefail

source "$HOME/gitflicProject/bin/lib/common.sh"

CONFIG_FILE="$HOME/gitflicProject/config/hosts.json"
PROJECTS_DIR="$HOME/gitflicProject/projects"

# Получить маппинг host -> env_prefix из config/hosts.json
get_host_prefix() {
    local host="$1"
    if [ ! -f "$CONFIG_FILE" ]; then
        echo ""
        return 1
    fi
    # Ищем строку с нужным host и извлекаем env_prefix
    grep -A2 "\"host\":\s*\"$host\"" "$CONFIG_FILE" | sed -n 's/.*"env_prefix":\s*"\([^"]*\)".*/\1/p' | head -1
}

# Получить список готовых хостов
get_ready_hosts() {
    if [ ! -f "$CONFIG_FILE" ]; then
        return 1
    fi
    grep -B1 '"status": "ready"' "$CONFIG_FILE" | grep -oE '"[a-z]+":\s*\{' | sed -E 's/^"([^"]+)".*/\1/'
}

# Получить дату коммита по SHA
get_commit_date() {
    local repo="$1" sha="$2"
    if [ -z "$sha" ]; then
        echo ""
        return
    fi
    git -C "$repo" log -1 --format='%ci' "$sha" 2>/dev/null | cut -d' ' -f1,2 | sed 's/ /T/' || echo ""
}

# Список remote с подсветкой актуальности
cmd_list() {
    local slug="$1"
    local repo="$PROJECTS_DIR/$slug"
    
    if [ ! -d "$repo/.git" ]; then
        print_error "Проект $slug не найден или не является git-репозиторием"
        exit 2
    fi
    
    local branch
    branch="$(git -C "$repo" branch --show-current 2>/dev/null)"
    if [ -z "$branch" ]; then
        print_error "Не удалось определить текущую ветку"
        exit 2
    fi
    
    print_header ""
    print_header "Remote проекта: $slug (ветка: $branch)"
    print_header "=============================================================="
    
    # Собираем информацию о всех remote
    local -a remotes=()
    local -a shas=()
    local -a dates=()
    local -a urls=()
    
    while read -r remote; do
        local url sha date
        url="$(git -C "$repo" remote get-url "$remote" 2>/dev/null || echo '')"
        sha="$(git -C "$repo" rev-parse "refs/remotes/$remote/$branch" 2>/dev/null || echo '')"
        date="$(get_commit_date "$repo" "$sha")"
        
        remotes+=("$remote")
        shas+=("$sha")
        dates+=("$date")
        urls+=("$url")
    done < <(git -C "$repo" remote 2>/dev/null | sort)
    
    if [ ${#remotes[@]} -eq 0 ]; then
        print_warn "У проекта нет настроенных remote"
        exit 0
    fi
    
    # Определяем самый свежий SHA
    local latest_sha=""
    local latest_date=""
    for i in "${!remotes[@]}"; do
        local sha="${shas[$i]}"
        local date="${dates[$i]}"
        if [ -n "$sha" ] && { [ -z "$latest_sha" ] || [[ "$date" > "$latest_date" ]]; }; then
            latest_sha="$sha"
            latest_date="$date"
        fi
    done
    
    # Выводим таблицу с подсветкой
    printf "${BLUE}${BOLD}  %-12s %-22s %-10s %-20s %s${RESET}\n" "REMOTE" "URL" "SHA" "ДАТА" "СТАТУС"
    print_header "--------------------------------------------------------------"
    
    for i in "${!remotes[@]}"; do
        local remote="${remotes[$i]}"
        local url="${urls[$i]}"
        local sha="${shas[$i]}"
        local date="${dates[$i]}"
        local short_sha="${sha:0:7}"
        local status=""
        
        if [ -z "$sha" ]; then
            status="${GRAY}⚪ не инициализирован${RESET}"
            printf "  ${BOLD}%-12s${RESET} %-22s %-10s %-20s %b\n" "$remote" "$url" "—" "—" "$status"
        elif [ "$sha" = "$latest_sha" ]; then
            status="${GREEN}✅ актуален${RESET}"
            printf "  ${BOLD}%-12s${RESET} %-22s %-10s %-20s %b\n" "$remote" "$url" "$short_sha" "$date" "$status"
        else
            # Подсчитываем, на сколько коммитов отстаёт
            local behind
            behind="$(git -C "$repo" rev-list --count "$sha".."$latest_sha" 2>/dev/null || echo '?')"
            status="${YELLOW}⚠ отстаёт на $behind${RESET}"
            printf "  ${BOLD}%-12s${RESET} %-22s %-10s %-20s %b\n" "$remote" "$url" "$short_sha" "$date" "$status"
        fi
    done
    
    print_header "--------------------------------------------------------------"
    if [ -n "$latest_sha" ]; then
        print_info "Самый свежий коммит: ${latest_sha:0:7} ($latest_date)"
    fi
}

# Добавить remote
cmd_add() {
    local slug="$1" host="$2" owner="${3:-}"
    local repo="$PROJECTS_DIR/$slug"
    
    if [ ! -d "$repo/.git" ]; then
        print_error "Проект $slug не найден или не является git-репозиторием"
        exit 2
    fi
    
    # Проверяем, что хост готов
    local prefix
    prefix="$(get_host_prefix "$host")"
    if [ -z "$prefix" ]; then
        print_error "Хост $host не найден в config/hosts.json или не готов"
        exit 2
    fi
    
    # Запрашиваем owner, если не указан
    if [ -z "$owner" ]; then
        read -r -p "$(echo -e ${CYAN}Владелец \(owner\) на $host: ${RESET})" owner
    fi
    
    if [ -z "$owner" ]; then
        print_error "Не указан владелец"
        exit 2
    fi
    
    # Проверяем, существует ли уже remote с таким именем
    local remote_name
    remote_name="$(echo "$host" | sed 's/\..*//')"
    if git -C "$repo" remote get-url "$remote_name" >/dev/null 2>&1; then
        print_error "Remote $remote_name уже существует"
        print_info "Используйте: git-project-remote.sh $slug --remove $remote_name"
        exit 2
    fi
    
    # Проверяем существование репозитория на хосте
    local url="https://${host}/project/${owner}/${slug}.git"
    print_info "Проверка доступности репозитория на $host..."
    
    if ! check_remote_exists "$host" "$owner" "$slug"; then
        print_error "Репозиторий $owner/$slug не найден на $host"
        print_info "Создайте репозиторий через Web-UI $host перед добавлением remote"
        exit 1
    fi
    
    # Добавляем remote
    print_info "Добавление remote $remote_name -> $url"
    if git -C "$repo" remote add "$remote_name" "$url"; then
        print_success "✓ Remote $remote_name добавлен"
        
        # Fetch для инициализации
        print_info "Fetch из $remote_name..."
        if git -C "$repo" fetch "$remote_name" >/dev/null 2>&1; then
            print_success "✓ Fetch успешен"
        else
            print_warn "⚠ Fetch не удался (возможно, репозиторий пуст или нет доступа)"
        fi
        
        # Логирование
        log_to_host "$host" "remote add: $owner/$slug to $slug (remote: $remote_name)"
        
        # Показываем обновлённый список
        cmd_list "$slug"
    else
        print_error "✗ Не удалось добавить remote"
        exit 1
    fi
}

# Удалить remote
cmd_remove() {
    local slug="$1" remote="$2"
    local repo="$PROJECTS_DIR/$slug"
    
    if [ ! -d "$repo/.git" ]; then
        print_error "Проект $slug не найден или не является git-репозиторием"
        exit 2
    fi
    
    if [ "$remote" = "origin" ]; then
        print_error "Нельзя удалить origin (основной remote)"
        print_info "Сначала добавьте другой remote и сделайте его origin:"
        print_info "  git -C $repo remote rename origin <старое_имя>"
        print_info "  git -C $repo remote rename <новое_имя> origin"
        exit 2
    fi
    
    if ! git -C "$repo" remote get-url "$remote" >/dev/null 2>&1; then
        print_error "Remote $remote не существует"
        exit 2
    fi
    
    local url
    url="$(git -C "$repo" remote get-url "$remote")"
    
    print_warn "Удаление remote: $remote ($url)"
    read -r -p "$(echo -e ${YELLOW}Подтвердите \(y/N\): ${RESET})" confirm
    if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
        print_info "Отмена"
        exit 0
    fi
    
    if git -C "$repo" remote remove "$remote"; then
        print_success "✓ Remote $remote удалён"
        log_to_host "$(echo "$url" | sed -E 's|https?://([^/]+)/.*|\1|')" "remote remove: $remote from $slug"
        cmd_list "$slug"
    else
        print_error "✗ Не удалось удалить remote"
        exit 1
    fi
}

# Основная функция
main() {
    load_env
    
    if [ $# -lt 2 ]; then
        echo "Использование:"
        echo "  git-project-remote.sh <slug> --list"
        echo "  git-project-remote.sh <slug> --add <host> [--owner <owner>]"
        echo "  git-project-remote.sh <slug> --remove <remote>"
        exit 2
    fi
    
    local slug="$1"
    shift
    
    case "$1" in
        --list)
            cmd_list "$slug"
            ;;
        --add)
            if [ $# -lt 2 ]; then
                print_error "Укажите хост: --add <host>"
                exit 2
            fi
            local host="$2"
            shift 2
            local owner=""
            if [ "${1:-}" = "--owner" ]; then
                owner="${2:-}"
            fi
            cmd_add "$slug" "$host" "$owner"
            ;;
        --remove)
            if [ $# -lt 2 ]; then
                print_error "Укажите remote: --remove <remote>"
                exit 2
            fi
            cmd_remove "$slug" "$2"
            ;;
        *)
            print_error "Неизвестная команда: $1"
            exit 2
            ;;
    esac
}

main "$@"
