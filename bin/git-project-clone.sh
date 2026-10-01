#!/usr/bin/env bash
# git-project-clone.sh — интерактивное клонирование проекта с выбором Git-хоста.
# Использует шаблоны URL из config/hosts.json для каждого хоста.
#
# Вызовы:
#   git-project-clone.sh                              # интерактивное меню
#   git-project-clone.sh --host <domain> --owner <owner> --slug <slug> [--as-origin]

set -uo pipefail

source "$HOME/gitflicProject/bin/lib/common.sh"

show_hosts_menu() {
    local -a ids=() domains=() statuses=()
    local id domain status count=0
    
    while IFS= read -r line; do
        id="$(echo "$line" | sed -E 's/^ *"\([a-z]*\)":.*$/\1/')"
        domain="$(host_field "$id" host)"
        status="$(host_field "$id" status)"
        ids+=("$id")
        domains+=("$domain")
        statuses+=("$status")
        ((count++))
    done < <(sed -n 's/^ *"\([a-z]*\)": *{.*$/\1/p' "$CONFIG_FILE" 2>/dev/null)
    
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
    
    for i in "${!ids[@]}"; do
        local num=$((i + 1))
        local status_display
        case "${statuses[$i]}" in
            ready)       status_display="${GREEN}✅ готов${RESET}" ;;
            integrating) status_display="${YELLOW}🚧 в процессе интеграции${RESET}" ;;
            disabled)    status_display="${GRAY}❌ отключен${RESET}" ;;
            *)           status_display="${GRAY}❓ неизвестно${RESET}" ;;
        esac
        printf "  ${CYAN}${BOLD}%-3s${RESET} ${BOLD}%-14s${RESET} %-22s %b\n" "$num" "${ids[$i]}" "${domains[$i]}" "$status_display"
    done
    
    print_header "--------------------------------------------------------------"
    echo ""
}

read_host_choice() {
    local max="$1" choice
    read -r -p "$(echo -e ${CYAN}Ваш выбор: ${RESET})" choice
    if [[ "$choice" =~ ^[0-9]+$ ]] && [ "$choice" -ge 1 ] && [ "$choice" -le "$max" ]; then
        echo "$choice"
        return 0
    fi
    print_error "Неверный выбор. Введите число от 1 до $max."
    read_host_choice "$max"
}

main() {
    load_env
    
    local domain="" owner="" slug="" as_origin=false
    
    while [ $# -gt 0 ]; do
        case "$1" in
            --host)       domain="$2"; shift 2 ;;
            --owner)      owner="$2"; shift 2 ;;
            --slug)       slug="$2"; shift 2 ;;
            --as-origin)  as_origin=true; shift ;;
            -h|--help)
                echo "Использование:"
                echo "  git-project-clone.sh                              # интерактивное меню"
                echo "  git-project-clone.sh --host <domain> --owner <owner> --slug <slug> [--as-origin]"
                exit 0
                ;;
            *)
                print_error "Неизвестный аргумент: $1"
                exit 2
                ;;
        esac
    done
    
    if [ -z "$domain" ]; then
        show_hosts_menu
        
        local -a all_ids=() all_domains=() all_statuses=()
        local id
        while IFS= read -r line; do
            id="$(echo "$line" | sed -E 's/^ *"\([a-z]*\)":.*$/\1/')"
            all_ids+=("$id")
            all_domains+=("$(host_field "$id" host)")
            all_statuses+=("$(host_field "$id" status)")
        done < <(sed -n 's/^ *"\([a-z]*\)": *{.*$/\1/p' "$CONFIG_FILE" 2>/dev/null)
        
        local choice
        choice="$(read_host_choice "${#all_ids[@]}")"
        local idx=$((choice - 1))
        
        local selected_id="${all_ids[$idx]}"
        local selected_domain="${all_domains[$idx]}"
        local selected_status="${all_statuses[$idx]}"
        
        if [ "$selected_status" != "ready" ]; then
            print_error "Хост $selected_id недоступен: $selected_status"
            [ "$selected_status" = "integrating" ] && print_info "Этот хост в процессе интеграции"
            exit 2
        fi
        
        domain="$selected_domain"
        
        read -r -p "$(echo -e ${CYAN}Владелец \(owner\): ${RESET})" owner
        read -r -p "$(echo -e ${CYAN}Имя проекта \(slug\): ${RESET})" slug
        
        read -r -p "$(echo -e ${CYAN}Использовать как origin? \[Y/n\]: ${RESET})" origin_choice
        if [[ "$origin_choice" =~ ^[Nn] ]]; then
            as_origin=false
        else
            as_origin=true
        fi
    fi
    
    [ -z "$owner" ] || [ -z "$slug" ] && { print_error "Не указаны owner или slug"; exit 2; }
    
    local target_dir="$PROJECTS_DIR/$slug"
    [ -d "$target_dir" ] && { print_error "Каталог $target_dir уже существует"; exit 2; }
    
    local url
    url="$(build_repo_url "$domain" "$owner" "$slug")" || { print_error "Нет шаблона URL для $domain"; exit 2; }
    
    local id remote_name
    id="$(host_id_by_domain "$domain")"
    remote_name="$( [ "$as_origin" = true ] && echo "origin" || echo "$id" )"
    
    print_header ""
    print_header "==> Клонирование: $slug"
    print_info "    Хост: $domain"
    print_info "    Владелец: $owner"
    print_info "    URL: $url"
    print_info "    Remote: $remote_name"
    
    mkdir -p "$PROJECTS_DIR"
    if git clone "$url" "$target_dir"; then
        print_success "    ✓ Клонирование успешно"
        
        if [ "$as_origin" = false ]; then
            git -C "$target_dir" remote rename origin "$remote_name"
            print_info "    Remote переименован: origin -> $remote_name"
        fi
        
        log_to_host "$domain" "clone: $owner/$slug -> $target_dir"
        
        print_success "    ✓ Проект клонирован в $target_dir"
        print_info "    Следующий шаг: cd $target_dir && git log --oneline -5"
    else
        print_error "    ✗ Клонирование не удалось"
        log_to_host "$domain" "clone FAILED: $owner/$slug"
        exit 1
    fi
}

main "$@"
