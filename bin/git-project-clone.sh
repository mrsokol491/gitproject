#!/usr/bin/env bash
# git-project-clone.sh — интерактивное клонирование проекта с выбором Git-хоста.
#
# Возможности:
#   - Пошаговое клонирование с явными этапами и описаниями
#   - Выбор хоста-источника (ОТКУДА клонировать)
#   - Уточнение локального имени каталога (КУДА сохранить)
#   - Обработка существующего каталога с понятными вариантами
#   - Добавление дополнительных remote для мульти-хост синхронизации
#   - Итоговое резюме перед выполнением

set -uo pipefail

source "$HOME/gitproject/bin/lib/common.sh"

# ============================================================================
# Вспомогательные функции для UX
# ============================================================================

# Показать заголовок шага
print_step() {
    local step="$1" total="$2" title="$3"
    echo ""
    print_header "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    print_header "  ШАГ $step из $total: $title"
    print_header "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
}

# Показать разделитель
print_divider() {
    echo ""
    print_gray "────────────────────────────────────────────────────────────"
    echo ""
}

# Показать подсказку о ожидаемом вводе
print_hint() {
    echo -e "${GRAY}   💡 $1${RESET}"
}

# ============================================================================
# Получение списка хостов
# ============================================================================

get_hosts_list() {
    local filter_ready="${1:-false}"
    local -a result_ids=() result_domains=() result_statuses=()
    local id domain status
    
    while IFS= read -r line; do
        [ -z "$line" ] && continue
        id="$(echo "$line" | sed -E 's/^ *"([a-z]*)":.*$/\1/')"
        domain="$(host_field "$id" host)"
        status="$(host_field "$id" status)"
        
        if [ "$filter_ready" = "true" ] && [ "$status" != "ready" ]; then
            continue
        fi
        
        result_ids+=("$id")
        result_domains+=("$domain")
        result_statuses+=("$status")
    done < <(sed -n 's/^ *"\([a-z]*\)": *{.*$/\1/p' "$CONFIG_FILE" 2>/dev/null)
    
    # Возвращаем через глобальные массивы
    HOST_IDS=("${result_ids[@]}")
    HOST_DOMAINS=("${result_domains[@]}")
    HOST_STATUSES=("${result_statuses[@]}")
}

# ============================================================================
# Интерактивное меню выбора хоста
# ============================================================================

show_hosts_menu() {
    local title="$1"
    local filter_ready="${2:-false}"
    
    get_hosts_list "$filter_ready"
    local count="${#HOST_IDS[@]}"
    
    if [ $count -eq 0 ]; then
        print_error "Хосты не настроены в config/hosts.json"
        return 1
    fi
    
    print_header "  $title"
    echo ""
    printf "  ${BLUE}${BOLD}%-3s  %-12s  %-20s  %-28s${RESET}\n" "№" "ID" "ДОМЕН" "СТАТУС"
    print_gray "  ───────────────────────────────────────────────────────────"
    
    for i in "${!HOST_IDS[@]}"; do
        local num=$((i + 1))
        local id="${HOST_IDS[$i]}"
        local domain="${HOST_DOMAINS[$i]}"
        local status="${HOST_STATUSES[$i]}"
        local status_display
        
        case "$status" in
            ready)       status_display="${GREEN}✅ готов${RESET}" ;;
            integrating) status_display="${YELLOW}🚧 интеграция${RESET}" ;;
            disabled)    status_display="${GRAY}❌ отключён${RESET}" ;;
            *)           status_display="${GRAY}❓ неизвестно${RESET}" ;;
        esac
        printf "  ${CYAN}${BOLD}%-3s${RESET}  ${BOLD}%-12s${RESET}  %-20s  %b\n" "$num" "$id" "$domain" "$status_display"
    done
    
    echo ""
    print_hint "Введите номер (1-$count) или 'q' для отмены"
}

read_host_choice() {
    local max="$1" choice
    while true; do
        read -r -p "$(echo -e "  ${CYAN}➤ Ваш выбор: ${RESET}")" choice
        
        if [[ "$choice" =~ ^[Qq]$ ]]; then
            echo "q"
            return 0
        fi
        
        if [[ "$choice" =~ ^[0-9]+$ ]] && [ "$choice" -ge 1 ] && [ "$choice" -le "$max" ]; then
            echo "$choice"
            return 0
        fi
        
        print_error "  Неверный ввод. Ожидается число от 1 до $max или 'q'"
    done
}

# ============================================================================
# Обработка существующего каталога
# ============================================================================

ask_existing_dir_action() {
    local target_dir="$1" slug="$2"
    
    print_warn "  Каталог уже существует: $target_dir"
    echo ""
    print_info "  Что сделать?"
    echo ""
    echo "  ${BOLD}1${RESET}. Использовать существующий каталог (добавить remote в него)"
    echo "       → Клонирование НЕ выполняется, добавляется только remote"
    echo ""
    echo "  ${BOLD}2${RESET}. Задать другое локальное имя для каталога"
    echo "       → Будет запрошено новое имя каталога"
    echo ""
    echo "  ${BOLD}3${RESET}. Удалить каталог и клонировать заново"
    echo "       → ${RED}ВНИМАНИЕ: каталог будет удалён!${RESET}"
    echo ""
    echo "  ${BOLD}q${RESET}. Отменить клонирование полностью"
    echo ""
    print_hint "Введите номер варианта (1, 2, 3) или 'q' для отмены"
    
    local choice
    while true; do
        read -r -p "$(echo -e "  ${CYAN}➤ Ваш выбор: ${RESET}")" choice
        case "$choice" in
            1|2|3|q|Q) echo "$choice"; return 0 ;;
            *) print_error "  Ожидается 1, 2, 3 или q" ;;
        esac
    done
}

ask_new_local_name() {
    local original_slug="$1"
    echo ""
    print_info "  Оригинальный slug: $original_slug"
    print_hint "Введите НОВОЕ имя для локального каталога (латиница, цифры, -, _)"
    local new_name
    while true; do
        read -r -p "$(echo -e "  ${CYAN}➤ Новое имя: ${RESET}")" new_name
        if [ -n "$new_name" ] && [[ "$new_name" =~ ^[a-zA-Z0-9_-]+$ ]]; then
            echo "$new_name"
            return 0
        fi
        print_error "  Некорректное имя. Используйте латиницу, цифры, - и _"
    done
}

# ============================================================================
# Добавление дополнительных remote
# ============================================================================

ask_add_more_remotes() {
    local slug="$1" repo="$2" source_domain="$3"
    
    print_divider
    print_header "  Мульти-хост синхронизация"
    echo ""
    print_info "  Хотите добавить remote с других хостов?"
    echo ""
    print_info "  Это позволит синхронизировать проект на несколько Git-хостов"
    print_info "  одновременно через команду: git-project-sync.sh $slug --all"
    echo ""
    
    local ready_count=0
    get_hosts_list "true"
    for i in "${!HOST_IDS[@]}"; do
        if [ "${HOST_DOMAINS[$i]}" != "$source_domain" ]; then
            ((ready_count++))
        fi
    done
    
    if [ $ready_count -eq 0 ]; then
        print_info "  Нет других готовых хостов для добавления"
        return 0
    fi
    
    echo "  ${BOLD}1${RESET}. Да, добавить remote с других хостов"
    echo "  ${BOLD}2${RESET}. Нет, пропустить"
    echo ""
    print_hint "Введите номер варианта (1 или 2)"
    
    local choice
    while true; do
        read -r -p "$(echo -e "  ${CYAN}➤ Ваш выбор: ${RESET}")" choice
        case "$choice" in
            1) echo "yes"; return 0 ;;
            2) echo "no"; return 0 ;;
            *) print_error "  Ожидается 1 или 2" ;;
        esac
    done
}

add_remote_interactive() {
    local slug="$1" repo="$2" exclude_domain="$3"
    
    echo ""
    print_info "  Выберите хост для добавления remote:"
    echo ""
    
    local -a available_ids=() available_domains=()
    local count=0
    
    for i in "${!HOST_IDS[@]}"; do
        local domain="${HOST_DOMAINS[$i]}"
        if [ "$domain" != "$exclude_domain" ] && [ "${HOST_STATUSES[$i]}" = "ready" ]; then
            ((count++))
            available_ids+=("${HOST_IDS[$i]}")
            available_domains+=("$domain")
            printf "  ${CYAN}${BOLD}%-3s${RESET}  ${BOLD}%-12s${RESET}  %s\n" "$count" "${HOST_IDS[$i]}" "$domain"
        fi
    done
    
    echo "  ${BOLD}0${RESET}.  Завершить (не добавлять больше)"
    echo ""
    print_hint "Введите номер хоста или 0 для завершения"
    
    local choice
    while true; do
        read -r -p "$(echo -e "  ${CYAN}➤ Ваш выбор: ${RESET}")" choice
        
        if [ "$choice" = "0" ]; then
            echo "done"
            return 0
        fi
        
        if [[ "$choice" =~ ^[0-9]+$ ]] && [ "$choice" -ge 1 ] && [ "$choice" -le $count ]; then
            local idx=$((choice - 1))
            local selected_domain="${available_domains[$idx]}"
            local selected_id="${available_ids[$idx]}"
            
            echo ""
            print_info "  Добавление remote: ${BOLD}$selected_id${RESET} ($selected_domain)"
            
            local owner
            read -r -p "$(echo -e "  ${CYAN}➤ Владелец (owner) на $selected_domain: ${RESET}")" owner
            
            if [ -z "$owner" ]; then
                print_error "  Владелец не указан, пропуск"
                echo "skip"
                return 0
            fi
            
            local url
            url="$(build_repo_url "$selected_domain" "$owner" "$slug")" || {
                print_error "  Нет шаблона URL для $selected_domain"
                echo "skip"
                return 0
            }
            
            print_info "  Проверка репозитория: $url"
            if ! check_remote_exists "$selected_domain" "$owner" "$slug"; then
                print_error "  Репозиторий $owner/$slug не найден на $selected_domain"
                print_hint "Создайте его через Web-UI и повторите позже"
                echo "skip"
                return 0
            fi
            
            if git -C "$repo" remote get-url "$selected_id" >/dev/null 2>&1; then
                print_warn "  Remote $selected_id уже существует, пропуск"
                echo "skip"
                return 0
            fi
            
            if git -C "$repo" remote add "$selected_id" "$url"; then
                print_success "  ✓ Remote $selected_id добавлен"
                if git -C "$repo" fetch "$selected_id" >/dev/null 2>&1; then
                    print_success "  ✓ Fetch выполнен"
                else
                    print_warn "  ⚠ Fetch не удался (возможно, репозиторий пуст)"
                fi
                log_to_host "$selected_domain" "remote add (при клонировании): $owner/$slug -> $slug"
                echo "added"
                return 0
            else
                print_error "  ✗ Не удалось добавить remote"
                echo "skip"
                return 0
            fi
        fi
        
        print_error "  Неверный выбор. Ожидается число от 0 до $count"
    done
}

add_multiple_remotes() {
    local slug="$1" repo="$2" exclude_domain="$3"
    
    while true; do
        local result
        result="$(add_remote_interactive "$slug" "$repo" "$exclude_domain")"
        
        case "$result" in
            done) 
                print_success "  ✓ Добавление remote завершено"
                return 0 ;;
            added|skip)
                # Продолжаем цикл
                ;;
        esac
    done
}

# ============================================================================
# Главный сценарий
# ============================================================================

main() {
    load_env
    
    echo ""
    print_header "╔══════════════════════════════════════════════════════════╗"
    print_header "║     GitProject — Мастер клонирования проекта            ║"
    print_header "║     Мульти-хост система (GitFlic, GitVerse и др.)       ║"
    print_header "╚══════════════════════════════════════════════════════════╝"
    
    # ========================================================================
    # ШАГ 1: Выбор хоста-источника (ОТКУДА клонировать)
    # ========================================================================
    print_step 1 4 "Выбор источника клонирования"
    print_info "  Выберите Git-хост, ОТКУДА будет клонирован проект."
    print_hint "Показаны только готовые к использованию хосты"
    echo ""
    
    show_hosts_menu "Доступные хосты" "true"
    local choice
    choice="$(read_host_choice "${#HOST_IDS[@]}")"
    
    if [ "$choice" = "q" ]; then
        print_info "Отмена операции"
        exit 0
    fi
    
    local idx=$((choice - 1))
    local source_domain="${HOST_DOMAINS[$idx]}"
    local source_id="${HOST_IDS[$idx]}"
    
    print_success "  ✓ Выбран хост: ${BOLD}$source_id${RESET} ($source_domain)"
    
    # ========================================================================
    # ШАГ 2: Запрос owner и slug
    # ========================================================================
    print_step 2 4 "Параметры проекта"
    print_info "  Укажите владельца и имя проекта на выбранном хосте."
    echo ""
    
    local owner slug
    print_hint "Введите имя пользователя или организации на $source_domain"
    read -r -p "$(echo -e "  ${CYAN}➤ Владелец (owner): ${RESET}")" owner
    [ -n "$owner" ] || { print_error "Владелец не указан"; exit 2; }
    
    echo ""
    print_hint "Введите имя репозитория (slug) на $source_domain"
    read -r -p "$(echo -e "  ${CYAN}➤ Имя проекта (slug): ${RESET}")" slug
    [ -n "$slug" ] || { print_error "Имя проекта не указано"; exit 2; }
    
    print_success "  ✓ Проект: ${BOLD}$owner/$slug${RESET} на $source_domain"
    
    # ========================================================================
    # ШАГ 3: Определение локального каталога
    # ========================================================================
    print_step 3 4 "Локальный каталог"
    print_info "  Укажите, КУДА сохранить проект локально."
    print_hint "По умолчанию используется имя проекта (slug): $slug"
    echo ""
    
    local target_slug="$slug"
    local target_dir="$PROJECTS_DIR/$target_slug"
    
    read -r -p "$(echo -e "  ${CYAN}➤ Локальное имя каталога [${slug}]: ${RESET}")" custom_slug
    [ -n "$custom_slug" ] && target_slug="$custom_slug"
    target_dir="$PROJECTS_DIR/$target_slug"
    
    # Проверка существования каталога
    if [ -d "$target_dir" ]; then
        local action
        action="$(ask_existing_dir_action "$target_dir" "$slug")"
        
        case "$action" in
            q|Q)
                print_info "Отмена операции"
                exit 0
                ;;
            1)
                if [ ! -d "$target_dir/.git" ]; then
                    print_error "  Каталог существует, но не является git-репозиторием"
                    exit 1
                fi
                print_info "  Используется существующий репозиторий"
                
                # Переходим сразу к добавлению remote
                local repo="$target_dir"
                local more
                more="$(ask_add_more_remotes "$target_slug" "$repo" "$source_domain")"
                if [ "$more" = "yes" ]; then
                    add_multiple_remotes "$target_slug" "$repo" "$source_domain"
                fi
                
                print_divider
                print_success "╔══════════════════════════════════════════════════════════╗"
                print_success "║  ✓ Проект готов: $target_dir"
                print_success "╚══════════════════════════════════════════════════════════╝"
                exit 0
                ;;
            2)
                target_slug="$(ask_new_local_name "$slug")"
                target_dir="$PROJECTS_DIR/$target_slug"
                
                if [ -d "$target_dir" ]; then
                    print_error "  Каталог $target_dir тоже существует"
                    exit 1
                fi
                print_success "  ✓ Будет использован каталог: $target_slug"
                ;;
            3)
                print_warn "  ⚠ Будет удалён каталог: $target_dir"
                read -r -p "$(echo -e "  ${YELLOW}➤ Подтвердите удаление (введите 'DELETE'): ${RESET}")" confirm
                if [ "$confirm" = "DELETE" ]; then
                    rm -rf "$target_dir"
                    print_success "  ✓ Каталог удалён"
                else
                    print_info "  Отмена удаления"
                    exit 0
                fi
                ;;
        esac
    else
        print_success "  ✓ Каталог будет создан: $target_dir"
    fi
    
    # ========================================================================
    # ШАГ 4: Подтверждение и выполнение
    # ========================================================================
    print_step 4 4 "Подтверждение и выполнение"
    
    local source_url
    source_url="$(build_repo_url "$source_domain" "$owner" "$slug")" || {
        print_error "Нет шаблона URL для $source_domain"
        exit 2
    }
    
    local remote_name="origin"
    
    echo "  ${BOLD}Сводка операции:${RESET}"
    echo ""
    echo "    ОТКУДА клонируем:"
    echo "      • Хост:       $source_domain"
    echo "      • Владелец:   $owner"
    echo "      • Проект:     $slug"
    echo "      • URL:        $source_url"
    echo ""
    echo "    КУДА сохраняем:"
    echo "      • Локальный каталог: $target_dir"
    echo "      • Имя remote:        $remote_name"
    echo ""
    print_hint "Введите 'y' для продолжения или 'n' для отмены"
    
    read -r -p "$(echo -e "  ${CYAN}➤ Выполнить клонирование? [Y/n]: ${RESET}")" confirm
    if [[ "$confirm" =~ ^[Nn] ]]; then
        print_info "Отмена операции"
        exit 0
    fi
    
    # Выполнение клонирования
    print_divider
    print_info "  Выполняется клонирование..."
    echo ""
    
    mkdir -p "$PROJECTS_DIR"
    if git clone "$source_url" "$target_dir"; then
        print_success "  ✓ Клонирование успешно"
        log_to_host "$source_domain" "clone: $owner/$slug -> $target_dir"
    else
        print_error "  ✗ Клонирование не удалось"
        log_to_host "$source_domain" "clone FAILED: $owner/$slug"
        exit 1
    fi
    
    # Предложение добавить дополнительные remote
    local repo="$target_dir"
    local more
    more="$(ask_add_more_remotes "$target_slug" "$repo" "$source_domain")"
    
    if [ "$more" = "yes" ]; then
        add_multiple_remotes "$target_slug" "$repo" "$source_domain"
    fi
    
    # Финальное сообщение
    echo ""
    print_success "╔══════════════════════════════════════════════════════════╗"
    print_success "║  ✓ Проект успешно клонирован                            ║"
    print_success "╚══════════════════════════════════════════════════════════╝"
    echo ""
    print_info "  Расположение: $target_dir"
    print_info "  Следующие шаги:"
    echo "    cd $target_dir"
    echo "    git log --oneline -5   # просмотр истории"
    echo "    git remote -v          # список настроенных remote"
    echo ""
}

main "$@"
