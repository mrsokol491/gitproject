#!/usr/bin/env bash
# gitproject-remove.sh — удаление проекта с выбором области (local/host/all).
#
# Возможности:
#   - Удаление только локально (сохранить на всех хостах)
#   - Удаление с конкретного хоста (выбор remote)
#   - Удаление со всех настроенных хостов
#   - Подтверждение через ввод кода (DELETE-LOCAL, DELETE-<HOST>, DELETE-ALL)
#   - Логирование операций
#
# Вызовы:
#   gitproject-remove.sh <slug>              # интерактивный выбор области
#   gitproject-remove.sh <slug> --local      # удалить только локально
#   gitproject-remove.sh <slug> --host <id>  # удалить с конкретного хоста
#   gitproject-remove.sh <slug> --all        # удалить со всех хостов

set -uo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../lib/common.sh"

# ============================================================================
# Вспомогательные функции
# ============================================================================

print_step() {
    local step="$1" total="$2" title="$3"
    echo "" >&2
    print_header "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" >&2
    print_header "  ШАГ $step из $total: $title" >&2
    print_header "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" >&2
    echo "" >&2
}

print_divider() {
    echo "" >&2
    print_gray "────────────────────────────────────────────────────────────" >&2
    echo "" >&2
}

print_hint() {
    echo -e "${GRAY}   💡 $1${RESET}" >&2
}

# ============================================================================
# Удаление локально
# ============================================================================

remove_local() {
    local slug="$1"
    local repo="$PROJECTS_DIR/$slug"
    
    if [ ! -d "$repo" ]; then
        print_error "Проект $slug не найден локально" >&2
        return 1
    fi
    
    print_warn "  ⚠ Будет удалён локальный каталог: $repo" >&2
    print_info "  Репозитории на хостах НЕ будут затронуты" >&2
    echo "" >&2
    print_hint "Введите 'DELETE-LOCAL' для подтверждения"
    
    local confirm
    read -r -p "$(echo -e "  ${CYAN}➤ Подтверждение: ${RESET}")" confirm
    
    if [ "$confirm" = "DELETE-LOCAL" ]; then
        rm -rf "$repo"
        print_success "  ✓ Локальный каталог удалён"
        log_to_host "local" "remove local: $slug"
        return 0
    else
        print_info "  Отмена удаления" >&2
        return 1
    fi
}

# ============================================================================
# Удаление с конкретного хоста
# ============================================================================

remove_from_host() {
    local slug="$1" remote_name="$2"
    local repo="$PROJECTS_DIR/$slug"
    
    if [ ! -d "$repo/.git" ]; then
        print_error "Проект $slug не является git-репозиторием" >&2
        return 1
    fi
    
    if ! git -C "$repo" remote get-url "$remote_name" >/dev/null 2>&1; then
        print_error "Remote $remote_name не настроен для проекта $slug" >&2
        return 1
    fi
    
    local url domain owner slug
    url="$(git -C "$repo" remote get-url "$remote_name")"
    domain="$(echo "$url" | sed -E 's|https?://([^/]+)/.*|\1|')"
    # Универсальное извлечение: убрать .git, взять dirname и basename
    local path_no_git="${url%.git}"
    owner="$(basename "$(dirname "$path_no_git")")"
    slug="$(basename "$path_no_git")"
    
    print_warn "  ⚠ Будет удалён репозиторий на хосте:" >&2
    echo "    Хост:  $remote_name ($domain)" >&2
    echo "    URL:   $url" >&2
    echo "    Владелец: $owner" >&2
    echo "" >&2
    print_error "  ВНИМАНИЕ: репозиторий будет удалён навсегда!" >&2
    print_hint "Введите 'DELETE-${remote_name^^}' для подтверждения (например, DELETE-GITVERSE)"
    
    local confirm expected="DELETE-${remote_name^^}"
    read -r -p "$(echo -e "  ${CYAN}➤ Подтверждение: ${RESET}")" confirm
    
    if [ "$confirm" = "$expected" ]; then
        # Удаляем репозиторий на хосте (требуется API-вызов)
        print_info "  Удаление репозитория на $domain..." >&2
        
        # Временная реализация: только удаление remote локально
        # Полное удаление требует API-вызова к хосту
        print_warn "  ⚠ API-удаление репозитория не реализовано" >&2
        print_info "  Удалите репозиторий вручную через Web-UI $domain" >&2
        
        # Удаляем remote локально
        git -C "$repo" remote remove "$remote_name"
        print_success "  ✓ Remote $remote_name удалён локально"
        
        log_to_host "$domain" "remove remote: $slug (remote: $remote_name)"
        return 0
    else
        print_info "  Отмена удаления" >&2
        return 1
    fi
}

# ============================================================================
# Удаление со всех хостов
# ============================================================================

remove_from_all_hosts() {
    local slug="$1"
    local repo="$PROJECTS_DIR/$slug"
    
    if [ ! -d "$repo/.git" ]; then
        print_error "Проект $slug не является git-репозиторием" >&2
        return 1
    fi
    
    local -a remotes=()
    while read -r remote; do
        [ -n "$remote" ] && remotes+=("$remote")
    done < <(git -C "$repo" remote 2>/dev/null | sort)
    
    if [ ${#remotes[@]} -eq 0 ]; then
        print_warn "У проекта нет настроенных remote" >&2
        return 1
    fi
    
    print_warn "  ⚠ Будут удалены репозитории на всех хостах:" >&2
    for remote in "${remotes[@]}"; do
        local url
        url="$(git -C "$repo" remote get-url "$remote" 2>/dev/null || echo '')"
        echo "    $remote: $url" >&2
    done
    echo "" >&2
    print_error "  ВНИМАНИЕ: все репозитории будут удалены навсегда!" >&2
    print_hint "Введите 'DELETE-ALL' для подтверждения"
    
    local confirm
    read -r -p "$(echo -e "  ${CYAN}➤ Подтверждение: ${RESET}")" confirm
    
    if [ "$confirm" = "DELETE-ALL" ]; then
        print_info "  Удаление репозиториев на всех хостах..." >&2
        
        for remote in "${remotes[@]}"; do
            local url domain
            url="$(git -C "$repo" remote get-url "$remote" 2>/dev/null || echo '')"
            domain="$(echo "$url" | sed -E 's|https?://([^/]+)/.*|\1|')"
            
            print_info "    $remote ($domain)..." >&2
            print_warn "    ⚠ API-удаление не реализовано — удалите вручную через Web-UI" >&2
            
            git -C "$repo" remote remove "$remote" 2>/dev/null
            log_to_host "$domain" "remove remote: $slug (remote: $remote)"
        done
        
        print_success "  ✓ Все remote удалены локально"
        print_info "  Удалите репозитории вручную через Web-UI каждого хоста" >&2
        return 0
    else
        print_info "  Отмена удаления" >&2
        return 1
    fi
}

# ============================================================================
# Интерактивное меню выбора области
# ============================================================================

show_remove_menu() {
    local slug="$1"
    local repo="$PROJECTS_DIR/$slug"
    
    print_header "  Удаление проекта: $slug" >&2
    echo "" >&2
    
    # Проверяем, существует ли проект локально
    if [ -d "$repo" ]; then
        print_info "  Локальный каталог: $repo" >&2
    else
        print_error "  Локальный каталог не найден" >&2
    fi
    
    # Проверяем remote
    if [ -d "$repo/.git" ]; then
        local -a remotes=()
        while read -r remote; do
            [ -n "$remote" ] && remotes+=("$remote")
        done < <(git -C "$repo" remote 2>/dev/null | sort)
        
        if [ ${#remotes[@]} -gt 0 ]; then
            echo "" >&2
            print_info "  Настроенные remote:" >&2
            for remote in "${remotes[@]}"; do
                local url
                url="$(git -C "$repo" remote get-url "$remote" 2>/dev/null || echo '')"
                echo "    $remote: $url" >&2
            done
        fi
    fi
    
    echo "" >&2
    print_info "  Выберите область удаления:" >&2
    echo "" >&2
    echo -e "  ${BOLD}1${RESET}. Только локально (сохранить на всех хостах)" >&2
    echo "       → Будет удалён каталог $repo" >&2
    echo "" >&2
    echo -e "  ${BOLD}2${RESET}. С конкретного хоста (выбрать)" >&2
    echo "       → Будет удалён репозиторий на выбранном хосте" >&2
    echo "" >&2
    echo -e "  ${BOLD}3${RESET}. Со всех настроенных хостов" >&2
    echo "       → Будут удалены репозитории на всех хостах" >&2
    echo "" >&2
    echo -e "  ${BOLD}q${RESET}. Отменить удаление" >&2
    echo "" >&2
    print_hint "Введите номер варианта (1, 2, 3) или 'q' для отмены"
}

ask_remove_scope() {
    local slug="$1"
    local repo="$PROJECTS_DIR/$slug"
    
    show_remove_menu "$slug"
    
    local choice
    while true; do
        read -r -p "$(echo -e "  ${CYAN}➤ Ваш выбор: ${RESET}")" choice
        case "$choice" in
            1) 
                remove_local "$slug"
                return $?
                ;;
            2)
                # Выбор конкретного remote
                if [ ! -d "$repo/.git" ]; then
                    print_error "Проект не является git-репозиторием" >&2
                    return 1
                fi
                
                local -a remotes=()
                while read -r remote; do
                    [ -n "$remote" ] && remotes+=("$remote")
                done < <(git -C "$repo" remote 2>/dev/null | sort)
                
                if [ ${#remotes[@]} -eq 0 ]; then
                    print_warn "У проекта нет настроенных remote" >&2
                    return 1
                fi
                
                echo "" >&2
                print_info "  Выберите remote для удаления:" >&2
                for i in "${!remotes[@]}"; do
                    local num=$((i + 1))
                    local remote="${remotes[$i]}"
                    local url
                    url="$(git -C "$repo" remote get-url "$remote" 2>/dev/null || echo '')"
                    printf "  ${CYAN}${BOLD}%-3s${RESET}  ${BOLD}%-12s${RESET}  %s\n" "$num" "$remote" "$url" >&2
                done
                echo "" >&2
                
                local remote_choice
                while true; do
                    read -r -p "$(echo -e "  ${CYAN}➤ Номер remote: ${RESET}")" remote_choice
                    if [[ "$remote_choice" =~ ^[0-9]+$ ]] && [ "$remote_choice" -ge 1 ] && [ "$remote_choice" -le "${#remotes[@]}" ]; then
                        local idx=$((remote_choice - 1))
                        remove_from_host "$slug" "${remotes[$idx]}"
                        return $?
                    fi
                    print_error "  Неверный ввод. Ожидается число от 1 до ${#remotes[@]}" >&2
                done
                ;;
            3)
                remove_from_all_hosts "$slug"
                return $?
                ;;
            q|Q)
                print_info "  Отмена удаления" >&2
                return 0
                ;;
            *)
                print_error "  Ожидается 1, 2, 3 или q" >&2
                ;;
        esac
    done
}

# ============================================================================
# Главный сценарий
# ============================================================================

main() {
    load_env
    
    if [ $# -lt 1 ]; then
        echo "Использование:"
        echo "  gitproject-remove.sh <slug>              # интерактивный выбор"
        echo "  gitproject-remove.sh <slug> --local      # удалить только локально"
        echo "  gitproject-remove.sh <slug> --host <id>  # удалить с конкретного хоста"
        echo "  gitproject-remove.sh <slug> --all        # удалить со всех хостов"
        exit 2
    fi
    
    local slug="$1"
    shift
    
    echo "" >&2
    print_header "╔══════════════════════════════════════════════════════════╗" >&2
    print_header "║     GitProject — Удаление проекта                      ║" >&2
    print_header "╚══════════════════════════════════════════════════════════╝" >&2
    
    # Обработка аргументов
    case "${1:-}" in
        --local)
            remove_local "$slug"
            exit $?
            ;;
        --host)
            if [ -z "${2:-}" ]; then
                print_error "Укажите имя remote: --host <id>" >&2
                exit 2
            fi
            remove_from_host "$slug" "$2"
            exit $?
            ;;
        --all)
            remove_from_all_hosts "$slug"
            exit $?
            ;;
        "")
            # Интерактивный режим
            ask_remove_scope "$slug"
            exit $?
            ;;
        *)
            print_error "Неизвестный аргумент: $1" >&2
            exit 2
            ;;
    esac
}

main "$@"
