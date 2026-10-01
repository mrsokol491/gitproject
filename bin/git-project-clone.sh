#!/usr/bin/env bash
# git-project-clone.sh — интерактивное клонирование проекта с выбором Git-хоста.
# Поддерживает: GitFlic, GitHub, GitLab, GitVerse (готовые); Bitbucket, Gitea (в процессе интеграции).
#
# Вызовы:
#   git-project-clone.sh                              # интерактивное меню
#   git-project-clone.sh --host <host> --owner <owner> --slug <slug> [--as-origin]

set -uo pipefail

source "$HOME/gitflicProject/bin/lib/common.sh"

CONFIG_FILE="$HOME/gitflicProject/config/hosts.json"
PROJECTS_DIR="$HOME/gitflicProject/projects"

# Получить список хостов из config/hosts.json
get_hosts_list() {
    if [ ! -f "$CONFIG_FILE" ]; then
        print_error "config/hosts.json не найден"
        exit 2
    fi
    # Парсим JSON вручную (без jq для минимизации зависимостей)
    grep -oE '"[a-z]+":\s*\{[^}]+\}' "$CONFIG_FILE" | while read -r line; do
        id="$(echo "$line" | sed -E 's/^"([^"]+)".*/\1/')"
        host="$(echo "$line" | sed -E 's/.*"host":\s*"([^"]+)".*/\1/')"
        status="$(echo "$line" | sed -E 's/.*"status":\s*"([^"]+)".*/\1/')"
        echo "$id|$host|$status"
    done
}

# Показать интерактивное меню выбора хоста
show_hosts_menu() {
    local -a hosts=()
    local -a statuses=()
    local count=0
    
    while IFS='|' read -r id host status; do
        hosts+=("$id|$host|$status")
        case "$status" in
            ready)       statuses+=("✅ готов") ;;
            integrating) statuses+=("🚧 в процессе интеграции") ;;
            disabled)    statuses+=("❌ отключен") ;;
            *)           statuses+=("❓ неизвестно") ;;
        esac
        ((count++))
    done < <(get_hosts_list)
    
    if [ $count -eq 0 ]; then
        print_error "Хосты не настроены в config/hosts.json"
        exit 2
    fi
    
    echo ""
    print_header "=============================================================="
    print_header "  GitProject — Клонирование проекта"
    print_header "=============================================================="
    printf "${BLUE}${BOLD}  %-3s %-14s %-22s %s${RESET}\n" "N" "ХОСТ" "URL" "СТАТУС"
    print_header "--------------------------------------------------------------"
    
    for i in "${!hosts[@]}"; do
        local num=$((i + 1))
        IFS='|' read -r id host status <<< "${hosts[$i]}"
        local display_status="${statuses[$i]}"
        case "$status" in
            ready)       printf "  ${CYAN}${BOLD}%-3s${RESET} ${BOLD}%-14s${RESET} %-22s ${GREEN}%s${RESET}\n" "$num" "$id" "$host" "$display_status" ;;
            integrating) printf "  ${CYAN}${BOLD}%-3s${RESET} ${BOLD}%-14s${RESET} %-22s ${YELLOW}%s${RESET}\n" "$num" "$id" "$host" "$display_status" ;;
            *)           printf "  ${CYAN}${BOLD}%-3s${RESET} ${BOLD}%-14s${RESET} %-22s ${GRAY}%s${RESET}\n" "$num" "$id" "$host" "$display_status" ;;
        esac
    done
    
    print_header "--------------------------------------------------------------"
    echo ""
}

# Прочитать выбор хоста
read_host_choice() {
    local max="$1"
    local choice
    read -r -p "$(echo -e ${CYAN}Ваш выбор: ${RESET})" choice
    
    if [[ "$choice" =~ ^[0-9]+$ ]] && [ "$choice" -ge 1 ] && [ "$choice" -le "$max" ]; then
        echo "$choice"
        return 0
    fi
    
    print_error "Неверный выбор. Введите число от 1 до $max."
    read_host_choice "$max"
}

# Основная функция
main() {
    load_env
    
    local host="" owner="" slug="" as_origin=false
    
    # Парсинг аргументов
    while [ $# -gt 0 ]; do
        case "$1" in
            --host)       host="$2"; shift 2 ;;
            --owner)      owner="$2"; shift 2 ;;
            --slug)       slug="$2"; shift 2 ;;
            --as-origin)  as_origin=true; shift ;;
            -h|--help)
                echo "Использование:"
                echo "  git-project-clone.sh                              # интерактивное меню"
                echo "  git-project-clone.sh --host <host> --owner <owner> --slug <slug> [--as-origin]"
                exit 0
                ;;
            *)
                print_error "Неизвестный аргумент: $1"
                exit 2
                ;;
        esac
    done
    
    # Интерактивный режим
    if [ -z "$host" ]; then
        show_hosts_menu
        
        local -a hosts_array=()
        while IFS='|' read -r id host_url status; do
            hosts_array+=("$id|$host_url|$status")
        done < <(get_hosts_list)
        
        local choice
        choice="$(read_host_choice "${#hosts_array[@]}")"
        local idx=$((choice - 1))
        
        IFS='|' read -r host_id host_url host_status <<< "${hosts_array[$idx]}"
        
        # Проверка статуса: разрешены только ready
        if [ "$host_status" != "ready" ]; then
            print_error "Хост $host_id недоступен: $host_status"
            case "$host_status" in
                integrating) print_info "Этот хост находится в процессе интеграции. Выберите другой." ;;
                disabled)    print_info "Этот хост отключен. Обратитесь к администратору." ;;
                *)           print_info "Неизвестный статус хоста." ;;
            esac
            exit 2
        fi
        
        host="$host_url"
        
        # Запрос owner и slug
        read -r -p "$(echo -e ${CYAN}Владелец \(owner\): ${RESET})" owner
        read -r -p "$(echo -e ${CYAN}Имя проекта \(slug\): ${RESET})" slug
        
        # Запрос: использовать как origin?
        read -r -p "$(echo -e ${CYAN}Использовать как origin? \[Y/n\]: ${RESET})" origin_choice
        if [[ "$origin_choice" =~ ^[Nn] ]]; then
            as_origin=false
        else
            as_origin=true
        fi
    fi
    
    # Валидация
    if [ -z "$owner" ] || [ -z "$slug" ]; then
        print_error "Не указаны owner или slug"
        exit 2
    fi
    
    local target_dir="$PROJECTS_DIR/$slug"
    if [ -d "$target_dir" ]; then
        print_error "Каталог $target_dir уже существует"
        exit 2
    fi
    
    # Построение URL
    local url="https://${host}/project/${owner}/${slug}.git"
    local remote_name="origin"
    
    if [ "$as_origin" = false ]; then
        # Если не origin, то имя remote = имя хоста
        remote_name="$(echo "$host" | sed 's/\..*//')"
    fi
    
    print_header ""
    print_header "==> Клонирование: $slug"
    print_info "    Хост: $host"
    print_info "    Владелец: $owner"
    print_info "    URL: $url"
    print_info "    Remote: $remote_name"
    
    # Клонирование
    mkdir -p "$PROJECTS_DIR"
    if git clone "$url" "$target_dir"; then
        print_success "    ✓ Клонирование успешно"
        
        # Если не origin, переименовать remote
        if [ "$as_origin" = false ]; then
            git -C "$target_dir" remote rename origin "$remote_name"
            print_info "    Remote переименован: origin -> $remote_name"
        fi
        
        # Логирование
        log_to_host "$host" "clone: $owner/$slug -> $target_dir"
        
        print_success "    ✓ Проект клонирован в $target_dir"
        print_info "    Следующий шаг: cd $target_dir && git log --oneline -5"
    else
        print_error "    ✗ Клонирование не удалось"
        log_to_host "$host" "clone FAILED: $owner/$slug"
        exit 1
    fi
}

main "$@"
