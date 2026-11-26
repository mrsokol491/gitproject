#!/usr/bin/env bash
# git-project-add-host.sh — интерактивный wizard добавления нового Git-хоста.
#
# Возможности:
#   - Выбор хоста из каталога (ready/integrating/disabled)
#   - Ввод учётных данных (user, token) с маскированием
#   - Тест подключения перед сохранением
#   - Запись в .env без перезаписи существующих значений
#   - Обновление config/hosts.json (добавление нового хоста или изменение статуса)
#   - Поддержка scope: HUB, PROJECT или оба
#   - Поддержка российских Git-хостов (реестр ПО РФ)
#
# Вызовы:
#   git-project-add-host.sh                # интерактивный wizard
#   git-project-add-host.sh --host <id>    # сразу выбрать хост
#   git-project-add-host.sh --list         # показать список хостов

set -uo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/lib/common.sh"

CONFIG_FILE="${HUB_ROOT}/config/hosts.json"
ENV_FILE="${HUB_ROOT}/.env"

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
# Работа с hosts.json
# ============================================================================

get_all_hosts() {
    if [ ! -f "$CONFIG_FILE" ]; then
        print_error "config/hosts.json не найден" >&2
        exit 2
    fi
    
    HOSTS_IDS=()
    HOSTS_DOMAINS=()
    HOSTS_STATUSES=()
    HOSTS_PREFIXES=()
    HOSTS_TEMPLATES=()
    
    while IFS= read -r line; do
        [ -z "$line" ] && continue
        local id
        id="$(echo "$line" | sed -E 's/^ *"([a-z]*)":.*$/\1/')"
        HOSTS_IDS+=("$id")
        HOSTS_DOMAINS+=("$(host_field "$id" host)")
        HOSTS_STATUSES+=("$(host_field "$id" status)")
        HOSTS_PREFIXES+=("$(host_field "$id" env_prefix)")
        HOSTS_TEMPLATES+=("$(host_field "$id" url_template)")
    done < <(sed -n 's/^ *"\([a-z]*\)": *{.*$/\1/p' "$CONFIG_FILE" 2>/dev/null)
}

host_exists_in_env() {
    local prefix="$1"
    grep -q "^${prefix}_" "$ENV_FILE" 2>/dev/null
}

# ============================================================================
# Интерактивное меню выбора хоста
# ============================================================================

show_hosts_menu() {
    get_all_hosts
    local count="${#HOSTS_IDS[@]}"
    
    if [ $count -eq 0 ]; then
        print_error "Хосты не настроены в config/hosts.json" >&2
        exit 2
    fi
    
    print_header "  Доступные Git-хосты" >&2
    echo "" >&2
    printf "  ${BLUE}${BOLD}%-3s  %-12s  %-20s  %-14s  %-12s${RESET}\n" "№" "ID" "ДОМЕН" "СТАТУС" "НАСТРОЕН" >&2
    print_gray "  ─────────────────────────────────────────────────────────────────────" >&2
    
    for i in "${!HOSTS_IDS[@]}"; do
        local num=$((i + 1))
        local id="${HOSTS_IDS[$i]}"
        local domain="${HOSTS_DOMAINS[$i]}"
        local status="${HOSTS_STATUSES[$i]}"
        local prefix="${HOSTS_PREFIXES[$i]}"
        local status_display configured_display
        
        case "$status" in
            ready)       status_display="${GREEN}✅ готов${RESET}" ;;
            integrating) status_display="${YELLOW}🚧 интеграция${RESET}" ;;
            disabled)    status_display="${GRAY}❌ отключён${RESET}" ;;
            *)           status_display="${GRAY}❓ неизвестно${RESET}" ;;
        esac
        
        if host_exists_in_env "$prefix"; then
            configured_display="${GREEN}✅ да${RESET}"
        else
            configured_display="${GRAY}❌ нет${RESET}"
        fi
        
        printf "  ${CYAN}${BOLD}%-3s${RESET}  ${BOLD}%-12s${RESET}  %-20s  %-14s  %s\n" "$num" "$id" "$domain" "$status_display" "$configured_display" >&2
    done
    
    echo "" >&2
    print_hint "Введите номер хоста или 'q' для отмены"
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
        
        print_error "  Неверный ввод. Ожидается число от 1 до $max или 'q'" >&2
    done
}

# ============================================================================
# Ввод учётных данных (ИСПРАВЛЕНО: stderr для меню, stdout для значений)
# ============================================================================

ask_scope() {
    local host_id="$1"
    
    echo "" >&2
    print_info "  Выберите область применения токена:" >&2
    echo "" >&2
    echo -e "  ${BOLD}1${RESET}. HUB только — доступ к репозиторию хаба на $host_id" >&2
    echo "       → Токен используется только для push хаба" >&2
    echo "" >&2
    echo -e "  ${BOLD}2${RESET}. PROJECT только — доступ к проектам в projects/ на $host_id" >&2
    echo "       → Токен используется для клонирования и синхронизации проектов" >&2
    echo "" >&2
    echo -e "  ${BOLD}3${RESET}. Оба — и HUB, и PROJECT" >&2
    echo "       → Один токен для всех операций на $host_id" >&2
    echo "" >&2
    print_hint "Рекомендуется вариант 3 (оба), если хост один"
    
    local choice
    while true; do
        read -r -p "$(echo -e "  ${CYAN}➤ Ваш выбор (1/2/3): ${RESET}")" choice
        case "$choice" in
            1) echo "HUB"; return 0 ;;
            2) echo "PROJECT"; return 0 ;;
            3) echo "BOTH"; return 0 ;;
            *) print_error "  Ожидается 1, 2 или 3" >&2 ;;
        esac
    done
}

ask_user() {
    local prefix="$1" scope="$2"
    local var_name="${prefix}_${scope}_USER"
    
    echo "" >&2
    print_info "  Введите имя пользователя на хосте" >&2
    print_hint "Это имя будет использоваться в git push/pull (username=...)"
    
    local user
    read -r -p "$(echo -e "  ${CYAN}➤ Пользователь ($var_name): ${RESET}")" user
    
    if [ -z "$user" ]; then
        print_error "  Пользователь не указан" >&2
        return 1
    fi
    
    echo "$user"
}

ask_token() {
    local prefix="$1" scope="$2"
    local var_name="${prefix}_${scope}_TOKEN"
    
    echo "" >&2
    print_info "  Введите API-токен" >&2
    print_hint "Токен создаётся в UI хоста (Настройки → Access Tokens)" >&2
    print_hint "Ввод маскируется, токен не отображается на экране"
    
    local token
    read -r -s -p "$(echo -e "  ${CYAN}➤ Токен ($var_name): ${RESET}")" token
    echo "" >&2  # перевод строки после скрытого ввода
    
    if [ -z "$token" ]; then
        print_error "  Токен не указан" >&2
        return 1
    fi
    
    local masked="${token:0:4}...${token: -4}"
    print_info "  Токен принят: $masked" >&2
    
    echo "$token"
}

# ============================================================================
# Тест подключения
# ============================================================================

test_connection() {
    local domain="$1" user="$2" token="$3" prefix="$4"
    
    print_divider
    print_info "  Тест подключения к $domain..."
    
    local test_repo="$user/test-connection-$(date +%s)"
    local url="https://${domain}/${user}/${test_repo}.git"
    
    local temp_env
    temp_env="$(mktemp)"
    cat > "$temp_env" <<EOF
GIT_HOST=$domain
${prefix}_HUB_USER=$user
${prefix}_HUB_TOKEN=$token
${prefix}_PROJECT_USER=$user
${prefix}_PROJECT_TOKEN=$token
EOF
    
    local old_env="$ENV_FILE"
    ENV_FILE="$temp_env"
    
    local output
    output="$(git ls-remote "$url" 2>&1 || true)"
    
    ENV_FILE="$old_env"
    rm -f "$temp_env"
    
    if echo "$output" | grep -qi "authentication failed\|invalid credentials\|401\|403"; then
        print_error "  ✗ Ошибка аутентификации" >&2
        print_info "  Проверьте правильность пользователя и токена" >&2
        return 1
    elif echo "$output" | grep -qi "not found\|does not exist\|404"; then
        print_success "  ✓ Подключение успешно (репозиторий не существует, но auth прошёл)"
        return 0
    elif echo "$output" | grep -qi "fatal:\|error:"; then
        print_warn "  ⚠ Не удалось проверить подключение"
        print_info "  Возможные причины: сеть, таймаут, некорректный URL" >&2
        print_hint "Продолжить без проверки? (y/N)"
        local choice
        read -r -p "$(echo -e "  ${CYAN}➤ ${RESET}")" choice
        [[ "$choice" =~ ^[Yy]$ ]] && return 0 || return 1
    else
        print_success "  ✓ Подключение успешно"
        return 0
    fi
}

# ============================================================================
# Запись в .env
# ============================================================================

write_to_env() {
    local prefix="$1" scope="$2" user="$3" token="$4"
    local user_var="${prefix}_${scope}_USER"
    local token_var="${prefix}_${scope}_TOKEN"
    
    local existing_user existing_token
    existing_user="$(sed -n "s/^${user_var}=//p" "$ENV_FILE" 2>/dev/null | tail -n 1 | tr -d '\r')"
    existing_token="$(sed -n "s/^${token_var}=//p" "$ENV_FILE" 2>/dev/null | tail -n 1 | tr -d '\r')"
    
    if [ -n "$existing_user" ] || [ -n "$existing_token" ]; then
        print_warn "  Переменные уже существуют в .env:" >&2
        [ -n "$existing_user" ] && echo "    $user_var=$existing_user" >&2
        [ -n "$existing_token" ] && echo "    $token_var=${existing_token:0:4}...${existing_token: -4}" >&2
        echo "" >&2
        print_hint "Перезаписать? (y/N)"
        local choice
        read -r -p "$(echo -e "  ${CYAN}➤ ${RESET}")" choice
        if [[ ! "$choice" =~ ^[Yy]$ ]]; then
            print_info "  Отмена перезаписи" >&2
            return 1
        fi
        
        sed -i "/^${user_var}=/d" "$ENV_FILE"
        sed -i "/^${token_var}=/d" "$ENV_FILE"
    fi
    
    cat >> "$ENV_FILE" <<EOF

# $scope токены для $HOST_DOMAIN (добавлено $(date +%Y-%m-%d))
${user_var}=$user
${token_var}=$token
EOF
    
    print_success "  ✓ Записано в .env: $user_var, $token_var"
    return 0
}

# ============================================================================
# Обновление hosts.json
# ============================================================================

update_hosts_json() {
    local host_id="$1" new_status="$2"
    
    if [ ! -f "$CONFIG_FILE" ]; then
        print_error "config/hosts.json не найден" >&2
        return 1
    fi
    
    if grep -q "\"${host_id}\":" "$CONFIG_FILE"; then
        print_info "  Обновление статуса $host_id в hosts.json..."
        
        local temp_file
        temp_file="$(mktemp)"
        sed "s/\"${host_id}\": *{[^}]*\"status\": *\"[^\"]*\"/\"${host_id}\": {\"status\": \"${new_status}\"/" "$CONFIG_FILE" > "$temp_file"
        mv "$temp_file" "$CONFIG_FILE"
        
        print_success "  ✓ Статус обновлён: $new_status"
    else
        print_info "  Добавление нового хоста $host_id в hosts.json..." >&2
        print_hint "Введите домен (например, github.com)"
        local domain
        read -r -p "$(echo -e "  ${CYAN}➤ Домен: ${RESET}")" domain
        
        print_hint "Введите префикс переменных (например, GITHUB)"
        local prefix
        read -r -p "$(echo -e "  ${CYAN}➤ Префикс: ${RESET}")" prefix
        
        print_hint "Введите шаблон URL (используйте {owner} и {slug})" >&2
        print_info "  Примеры:" >&2
        echo "    https://github.com/{owner}/{slug}.git" >&2
        echo "    https://gitflic.ru/project/{owner}/{slug}.git" >&2
        local template
        read -r -p "$(echo -e "  ${CYAN}➤ Шаблон: ${RESET}")" template
        
        sed -i '$ s/}$//' "$CONFIG_FILE"
        
        cat >> "$CONFIG_FILE" <<EOF
  "${host_id}": {
    "host": "${domain}",
    "status": "${new_status}",
    "env_prefix": "${prefix}",
    "url_template": "${template}"
  }
}
EOF
        
        print_success "  ✓ Хост добавлен: $host_id"
    fi
}

# ============================================================================
# Главный сценарий
# ============================================================================

main() {
    load_env
    
    echo "" >&2
    print_header "╔══════════════════════════════════════════════════════════╗" >&2
    print_header "║     GitProject — Добавление Git-хоста                   ║" >&2
    print_header "║     Мульти-хост система (GitFlic, GitVerse, GitHub...)  ║" >&2
    print_header "║     Поддержка реестра ПО РФ                             ║" >&2
    print_header "╚══════════════════════════════════════════════════════════╝" >&2
    
    local selected_host=""
    while [ $# -gt 0 ]; do
        case "$1" in
            --host)
                selected_host="$2"
                shift 2
                ;;
            --list)
                show_hosts_menu
                exit 0
                ;;
            -h|--help)
                echo "Использование:"
                echo "  git-project-add-host.sh              # интерактивный wizard"
                echo "  git-project-add-host.sh --host <id>  # сразу выбрать хост"
                echo "  git-project-add-host.sh --list       # показать список хостов"
                exit 0
                ;;
            *)
                print_error "Неизвестный аргумент: $1" >&2
                exit 2
                ;;
        esac
    done
    
    print_step 1 5 "Выбор Git-хоста"
    
    if [ -z "$selected_host" ]; then
        show_hosts_menu
        local choice
        choice="$(read_host_choice "${#HOSTS_IDS[@]}")"
        
        if [ "$choice" = "q" ]; then
            print_info "Отмена операции" >&2
            exit 0
        fi
        
        local idx=$((choice - 1))
        selected_host="${HOSTS_IDS[$idx]}"
    fi
    
    local host_idx=-1
    for i in "${!HOSTS_IDS[@]}"; do
        local num=$((i + 1))
        local id="${HOSTS_IDS[$i]}"
        local domain="${HOSTS_DOMAINS[$i]}"
        local status="${HOSTS_STATUSES[$i]}"
        local prefix="${HOSTS_PREFIXES[$i]}"
        local status_display configured_display
        
        case "$status" in
            ready)       status_display="${GREEN}✅ готов${RESET}" ;;
            integrating) status_display="${YELLOW}🚧 интеграция${RESET}" ;;
            disabled)    status_display="${GRAY}❌ отключён${RESET}" ;;
            *)           status_display="${GRAY}❓ неизвестно${RESET}" ;;
        esac
        
        if host_exists_in_env "$prefix"; then
            configured_display="${GREEN}✅ да${RESET}"
        else
            configured_display="${GRAY}❌ нет${RESET}"
        fi
        
        printf "  ${CYAN}${BOLD}%-3s${RESET}  ${BOLD}%-12s${RESET}  %-20s  %-14s  %s\n" "$num" "$id" "$domain" "$status_display" "$configured_display" >&2
    done
    
    if [ $host_idx -eq -1 ]; then
        print_error "Хост $selected_host не найден" >&2
        exit 2
    fi
    
    HOST_ID="$selected_host"
    HOST_DOMAIN="${HOSTS_DOMAINS[$host_idx]}"
    HOST_STATUS="${HOSTS_STATUSES[$host_idx]}"
    HOST_PREFIX="${HOSTS_PREFIXES[$host_idx]}"
    HOST_TEMPLATE="${HOSTS_TEMPLATES[$host_idx]}"
    
    print_success "  ✓ Выбран хост: ${BOLD}$HOST_ID${RESET} ($HOST_DOMAIN)"
    
    if [ "$HOST_STATUS" = "integrating" ]; then
        print_warn ""
        print_warn "  ⚠ ВНИМАНИЕ: этот хост находится в процессе интеграции" >&2
        print_info "  Возможны проблемы с подключением или неполная функциональность" >&2
        echo "" >&2
        print_hint "Продолжить? (y/N)"
        local choice
        read -r -p "$(echo -e "  ${CYAN}➤ ${RESET}")" choice
        [[ "$choice" =~ ^[Yy]$ ]] || { print_info "Отмена" >&2; exit 0; }
    fi
    
    print_step 2 5 "Область применения токена"
    
    local scope
    scope="$(ask_scope "$HOST_ID")"
    
    print_success "  ✓ Область: ${BOLD}$scope${RESET}"
    
    print_step 3 5 "Учётные данные"
    
    local user token
    user="$(ask_user "$HOST_PREFIX" "$scope")" || exit 2
    token="$(ask_token "$HOST_PREFIX" "$scope")" || exit 2
    
    print_success "  ✓ Пользователь: ${BOLD}$user${RESET}"
    print_success "  ✓ Токен: ${BOLD}${token:0:4}...${token: -4}${RESET}"
    
    print_step 4 5 "Тест подключения"
    
    if ! test_connection "$HOST_DOMAIN" "$user" "$token" "$HOST_PREFIX"; then
        print_error "  ✗ Тест подключения не прошёл" >&2
        print_hint "Повторить ввод учётных данных? (y/N)"
        local choice
        read -r -p "$(echo -e "  ${CYAN}➤ ${RESET}")" choice
        [[ "$choice" =~ ^[Yy]$ ]] || { print_info "Отмена" >&2; exit 0; }
        
        user="$(ask_user "$HOST_PREFIX" "$scope")" || exit 2
        token="$(ask_token "$HOST_PREFIX" "$scope")" || exit 2
        
        if ! test_connection "$HOST_DOMAIN" "$user" "$token" "$HOST_PREFIX"; then
            print_error "  ✗ Повторный тест не прошёл" >&2
            print_info "  Отмена операции" >&2
            exit 1
        fi
    fi
    
    print_step 5 5 "Сохранение конфигурации"
    
    if [ "$scope" = "BOTH" ]; then
        write_to_env "$HOST_PREFIX" "HUB" "$user" "$token" || exit 2
        write_to_env "$HOST_PREFIX" "PROJECT" "$user" "$token" || exit 2
    else
        write_to_env "$HOST_PREFIX" "$scope" "$user" "$token" || exit 2
    fi
    
    update_hosts_json "$HOST_ID" "ready" || exit 2
    
    log_to_host "$HOST_DOMAIN" "host added: $HOST_ID (scope: $scope, user: $user)"
    
    print_divider
    print_success "╔══════════════════════════════════════════════════════════╗"
    print_success "║  ✓ Git-хост успешно добавлен                         ║"
    print_success "╚══════════════════════════════════════════════════════════╝"
    echo "" >&2
    print_info "  Хост:      $HOST_ID ($HOST_DOMAIN)"
    print_info "  Область:   $scope"
    print_info "  Пользователь: $user"
    echo "" >&2
    print_info "  Следующие шаги:"
    echo "    1. Проверить подключение: git-project-clone.sh" >&2
    echo "    2. Добавить remote к проекту: git-project-remote.sh <slug> --add $HOST_DOMAIN" >&2
    echo "    3. Синхронизировать на все хосты: git-project-sync.sh <slug> --all" >&2
    echo "" >&2
}

main "$@"
