#!/usr/bin/env bash
# git-project-remote.sh — управление remote проекта с подсветкой актуальности версий.
#
# Вызовы:
#   git-project-remote.sh <slug> --list                    # таблица remote + актуальность
#   git-project-remote.sh <slug> --add <domain>            # добавить remote (owner из .env)
#   git-project-remote.sh <slug> --add <domain> --owner <owner>
#   git-project-remote.sh <slug> --remove <remote>         # удалить remote (с подтверждением)

set -uo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/lib/common.sh"

cmd_list() {
    local slug="$1" repo="$PROJECTS_DIR/$1"
    if [ ! -d "$repo/.git" ]; then
        print_error "Проект $slug не найден или не является git-репозиторием"
        exit 2
    fi
    local branch
    branch="$(git -C "$repo" branch --show-current 2>/dev/null)"
    [ -n "$branch" ] || { print_error "Не определена текущая ветка"; exit 2; }

    print_header ""
    print_header "Remote проекта: $slug (ветка: $branch)"
    print_header "=============================================================="
    printf "${BLUE}${BOLD}  %-18s %-44s %-9s %-17s %s${RESET}\n" "СЕРВЕР" "URL" "SHA" "ДАТА" "СТАТУС"
    print_header "--------------------------------------------------------------"

    local -a names=() shas=() dates=() urls=()
    local remote url sha date
    while read -r remote; do
        [ -n "$remote" ] || continue
        url="$(git -C "$repo" remote get-url "$remote" 2>/dev/null || echo '')"
        sha=""
        date=""
        if git -C "$repo" show-ref --verify --quiet "refs/remotes/$remote/$branch" 2>/dev/null; then
            sha="$(git -C "$repo" rev-parse "refs/remotes/$remote/$branch" 2>/dev/null || echo '')"
            if [ -n "$sha" ]; then
                date="$(git -C "$repo" log -1 --format='%ci' "$sha" 2>/dev/null | cut -d' ' -f1,2 | tr ' ' 'T' || echo '')"
            fi
        fi
        names+=("$remote")
        urls+=("$url")
        shas+=("$sha")
        dates+=("$date")
    done < <(get_project_remotes "$slug")

    if [ "${#names[@]}" -eq 0 ]; then
        print_warn "У проекта нет настроенных remote"
        exit 0
    fi

    # Самый свежий коммит среди remote
    local latest_sha="" latest_date="" i
    for i in "${!names[@]}"; do
        if [ -n "${shas[$i]}" ] && { [ -z "$latest_date" ] || [[ "${dates[$i]}" > "$latest_date" ]]; }; then
            latest_sha="${shas[$i]}"; latest_date="${dates[$i]}"
        fi
    done

    for i in "${!names[@]}"; do
        local sha="${shas[$i]}" date="${dates[$i]}" status
        if [ -z "$sha" ]; then
            status="${GRAY}⚪ не инициализирован${RESET}"
            printf "  ${BOLD}%-18s${RESET} %-44s %-9s %-17s %b\n" "$(remote_display_name "$slug" "${names[$i]}")" "${urls[$i]}" "—" "—" "$status"
        elif [ "$sha" = "$latest_sha" ]; then
            status="${GREEN}✅ актуален${RESET}"
            printf "  ${BOLD}%-18s${RESET} %-44s %-9s %-17s %b\n" "$(remote_display_name "$slug" "${names[$i]}")" "${urls[$i]}" "${sha:0:7}" "$date" "$status"
        else
            local behind
            behind="$(git -C "$repo" rev-list --count "$sha".."$latest_sha" 2>/dev/null || echo '?')"
            status="${YELLOW}⚠ отстаёт на $behind${RESET}"
            printf "  ${BOLD}%-18s${RESET} %-44s %-9s %-17s %b\n" "$(remote_display_name "$slug" "${names[$i]}")" "${urls[$i]}" "${sha:0:7}" "$date" "$status"
        fi
    done
    print_header "--------------------------------------------------------------"
    [ -n "$latest_sha" ] && print_info "Самый свежий коммит: ${latest_sha:0:7} ($latest_date)"
    return 0
}

cmd_add() {
    local slug="$1" domain="$2" owner="${3:-}"
    local repo="$PROJECTS_DIR/$slug"
    [ -d "$repo/.git" ] || { print_error "Проект $slug не найден"; exit 2; }

    local status
    status="$(host_status "$domain")"
    if [ "$status" != "ready" ]; then
        print_error "Хост $domain недоступен: статус «$status»"
        [ "$status" = "integrating" ] && print_info "Хост в процессе интеграции — следите за обновлениями GitProject"
        exit 2
    fi

    [ -n "$owner" ] || owner="$(get_host_owner "$domain")"
    if [ -z "$owner" ]; then
        read -r -p "$(echo -e ${CYAN}Владелец \(owner\) на $domain: ${RESET})" owner
    fi
    [ -n "$owner" ] || { print_error "Не указан владелец"; exit 2; }

    local id remote_name url
    id="$(host_id_by_domain "$domain")"
    remote_name="$id"
    git -C "$repo" remote get-url "$remote_name" >/dev/null 2>&1 \
        && { print_error "Remote $remote_name уже существует"; exit 2; }

    url="$(build_repo_url "$domain" "$owner" "$slug")" || { print_error "Нет шаблона URL для $domain"; exit 2; }
    print_info "Проверка репозитория: $url"
    if ! check_remote_exists "$domain" "$owner" "$slug"; then
        print_error "Репозиторий $owner/$slug не найден на $domain"
        print_info "Создайте его через Web-UI $domain и повторите"
        exit 1
    fi

    if git -C "$repo" remote add "$remote_name" "$url"; then
        print_success "✓ Remote $remote_name добавлен ($url)"
        print_info "Fetch из $remote_name..."
        git -C "$repo" fetch "$remote_name" >/dev/null 2>&1 \
            && print_success "✓ Fetch успешен" \
            || print_warn "⚠ Fetch пуст или не удался (репозиторий пуст?)"
        log_to_host "$domain" "remote add: $owner/$slug -> проект $slug (remote: $remote_name)"
        cmd_list "$slug"
    else
        print_error "✗ Не удалось добавить remote"
        exit 1
    fi
}

cmd_remove() {
    local slug="$1" remote="$2" repo="$PROJECTS_DIR/$1"
    [ -d "$repo/.git" ] || { print_error "Проект $slug не найден"; exit 2; }
    [ "$remote" = "origin" ] && { print_error "origin удалять нельзя (смените origin через git remote rename)"; exit 2; }
    git -C "$repo" remote get-url "$remote" >/dev/null 2>&1 || { print_error "Remote $remote не существует"; exit 2; }

    local url
    url="$(git -C "$repo" remote get-url "$remote")"
    print_warn "Удаление remote: $remote ($url). Репозиторий на хосте НЕ удаляется."
    read -r -p "$(echo -e ${YELLOW}Подтвердите \(y/N\): ${RESET})" confirm
    [[ "$confirm" =~ ^[Yy]$ ]] || { print_info "Отмена"; exit 0; }

    if git -C "$repo" remote remove "$remote"; then
        print_success "✓ Remote $remote удалён"
        cmd_list "$slug"
    else
        print_error "✗ Не удалось удалить remote"
        exit 1
    fi
}

load_env
if [ $# -lt 2 ]; then
    echo "Использование: git-project-remote.sh <slug> --list | --add <domain> [--owner <owner>] | --remove <remote>"
    exit 2
fi
SLUG="$1"; shift
case "$1" in
    --list)   cmd_list "$SLUG" ;;
    --add)    [ -n "${2:-}" ] || { print_error "Укажите домен: --add <domain>"; exit 2; }
              OWNER=""
              if [ "${3:-}" = "--owner" ]; then OWNER="${4:-}"; fi
              cmd_add "$SLUG" "$2" "$OWNER" ;;
    --remove) [ -n "${2:-}" ] || { print_error "Укажите remote: --remove <remote>"; exit 2; }
              cmd_remove "$SLUG" "$2" ;;
    *)        print_error "Неизвестная команда: $1"; exit 2 ;;
esac
