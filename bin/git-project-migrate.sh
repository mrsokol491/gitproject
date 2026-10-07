#!/usr/bin/env bash
# git-project-migrate.sh — миграция проекта между Git-хостами с подсветкой актуальности.
#
# Показывает таблицу версий: локальная + все remote (SHA, дата, отставание).
# Подсветка: 🟢 самый свежий | 🟡 отстаёт на N | 🔴 расходится | ⚪ не инициализирован.
# Правила (ТЗ-001 v2 §7):
#   - fast-forward: источник не отстаёт от приёмника — прямой push;
#   - приёмник пуст: git push <target> <src>:refs/heads/<branch>;
#   - источник отстаёт: операция блокируется (предлагается обратное направление);
#   - расхождение историй: merge с приоритетом источника (-X ours в detached на источнике),
#     без force-push; локальная ветка обновляется только fast-forward.
# Подтверждение: код MIGRATE-<SOURCE>-TO-<TARGET> (верхний регистр).
#
# Вызовы:
#   git-project-migrate.sh <slug>                          # интерактивно
#   git-project-migrate.sh <slug> --source <имя|local> --target <имя>

set -uo pipefail

source "$HOME/gitproject/bin/lib/common.sh"

REPO=""
BRANCH=""
NAMES=(); DOMAINS=(); SHAS=(); DATES=(); STATES=()

collect() {
    REPO="$PROJECTS_DIR/$1"
    [ -d "$REPO/.git" ] || { print_error "Проект $1 не найден или не git-репозиторий"; exit 2; }
    BRANCH="$(git -C "$REPO" branch --show-current 2>/dev/null)"
    [ -n "$BRANCH" ] || { print_error "Не определена текущая ветка"; exit 2; }

    NAMES=("local"); DOMAINS=("локально"); SHAS=("$(git -C "$REPO" rev-parse HEAD 2>/dev/null)")
    DATES=("$(git -C "$REPO" log -1 --format='%ci' HEAD 2>/dev/null | cut -d' ' -f1,2 | tr ' ' 'T')")

    local remote url sha date
    while read -r remote; do
        [ -n "$remote" ] || continue
        url="$(git -C "$REPO" remote get-url "$remote" 2>/dev/null)"
        sha=""; date=""
        if git -C "$REPO" show-ref --verify --quiet "refs/remotes/$remote/$BRANCH" 2>/dev/null; then
            sha="$(git -C "$REPO" rev-parse "refs/remotes/$remote/$BRANCH" 2>/dev/null)"
            [ -n "$sha" ] && date="$(git -C "$REPO" log -1 --format='%ci' "$sha" 2>/dev/null | cut -d' ' -f1,2 | tr ' ' 'T')"
        fi
        NAMES+=("$remote"); DOMAINS+=("$url"); SHAS+=("$sha"); DATES+=("$date")
    done < <(git -C "$REPO" remote 2>/dev/null | sort)

    # Самый свежий по дате коммитера
    local latest_sha="" latest_date="" i
    for i in "${!NAMES[@]}"; do
        if [ -n "${SHAS[$i]}" ] && { [ -z "$latest_date" ] || [[ "${DATES[$i]}" > "$latest_date" ]]; }; then
            latest_sha="${SHAS[$i]}"; latest_date="${DATES[$i]}"
        fi
    done

    # Состояния
    STATES=()
    for i in "${!NAMES[@]}"; do
        local sha="${SHAS[$i]}" mb
        if [ -z "$sha" ]; then
            STATES+=("empty")
        elif [ "$sha" = "$latest_sha" ]; then
            STATES+=("fresh")
        else
            mb="$(git -C "$REPO" merge-base "$sha" "$latest_sha" 2>/dev/null || echo '')"
            if [ "$mb" = "$sha" ]; then
                STATES+=("behind:$(git -C "$REPO" rev-list --count "$sha".."$latest_sha" 2>/dev/null || echo '?')")
            elif [ "$mb" = "$latest_sha" ]; then
                STATES+=("fresh")
            else
                STATES+=("diverged")
            fi
        fi
    done
}

show_table() {
    print_header ""
    print_header "Актуальность версий: $1 (ветка: $BRANCH)"
    print_header "=============================================================="
    printf "${BLUE}${BOLD}  %-3s %-12s %-9s %-17s %s${RESET}\n" "N" "ИСТОЧНИК" "SHA" "ДАТА" "СОСТОЯНИЕ"
    print_header "--------------------------------------------------------------"
    local i
    for i in "${!NAMES[@]}"; do
        local n=$((i + 1)) st="${STATES[$i]}" disp sha7="${SHAS[$i]:0:7}"
        [ -z "$sha7" ] && sha7="—"
        case "$st" in
            fresh)        disp="${GREEN}🟢 самый свежий${RESET}" ;;
            behind:*)     disp="${YELLOW}🟡 отстаёт на ${st#behind:}${RESET}" ;;
            diverged)     disp="${RED}🔴 расходится${RESET}" ;;
            empty)        disp="${GRAY}⚪ не инициализирован${RESET}"; sha7="—" ;;
        esac
        printf "  ${CYAN}${BOLD}%-3s${RESET} ${BOLD}%-12s${RESET} %-9s %-17s %b\n" \
            "$n" "${NAMES[$i]}" "$sha7" "${DATES[$i]:-—}" "$disp"
    done
    print_header "--------------------------------------------------------------"
    print_gray "  1 = локальная версия; остальные — remote проекта"
}

read_num() {
    local max="$1" prompt="$2" choice
    while true; do
        read -r -p "$(echo -e "  ${CYAN}➤ $prompt: ${RESET}")" choice
        if [[ "$choice" =~ ^[0-9]+$ ]] && [ "$choice" -ge 1 ] && [ "$choice" -le "$max" ]; then
            echo "$choice"; return 0
        fi
        print_error "  Ожидается число от 1 до $max"
    done
}

do_migrate() {
    local si="$1" ti="$2"
    local src="${NAMES[$si]}" tgt="${NAMES[$ti]}"
    local src_sha="${SHAS[$si]}" tgt_sha="${SHAS[$ti]}"

    [ -n "$src_sha" ] || { print_error "Источник $src не инициализирован — миграция невозможна"; return 1; }
    [ "$src" != "$tgt" ] || { print_error "Источник и приёмник совпадают"; return 1; }

    print_header ""
    print_header "==> Миграция: $src -> $tgt"

    # Приёмник пуст: публикация истории источника
    if [ -z "$tgt_sha" ]; then
        print_info "    Приёмник пуст: публикация истории $src"
        git -C "$REPO" push "$tgt" "$src_sha:refs/heads/$BRANCH" || { print_error "    push не удался"; return 4; }
        git -C "$REPO" fetch "$tgt" >/dev/null 2>&1 || true
        print_success "    ✓ УСПЕХ: $tgt инициализирован версией ${src_sha:0:7}"
        return 0
    fi

    [ "$src_sha" = "$tgt_sha" ] && { print_info "    Версии совпадают — делать нечего"; return 0; }

    local mb
    mb="$(git -C "$REPO" merge-base "$src_sha" "$tgt_sha" 2>/dev/null || echo '')"

    # Источник отстаёт: блокировка
    if [ "$mb" = "$src_sha" ]; then
        print_error "    Источник $src ОТСТАЁТ от приёмника $tgt — миграция заблокирована"
        print_info "    Рекомендация: обратное направление ($tgt -> $src) или синхронизация источника"
        return 3
    fi

    # Fast-forward
    if [ "$mb" = "$tgt_sha" ]; then
        print_info "    Fast-forward: $tgt ${tgt_sha:0:7} -> ${src_sha:0:7}"
        git -C "$REPO" push "$tgt" "$src_sha:refs/heads/$BRANCH" || { print_error "    push не удался"; return 4; }
        git -C "$REPO" fetch "$tgt" >/dev/null 2>&1 || true
        print_success "    ✓ УСПЕХ: $tgt обновлён до ${src_sha:0:7}"
        return 0
    fi

    # Расхождение: merge с приоритетом источника, без force-push
    print_warn "    Истории расходятся: слияние с приоритетом источника"
    print_info "    Будет создан merge-коммит на вершине $src и опубликован в $tgt"
    local orig_branch="$BRANCH"
    git -C "$REPO" checkout --detach "$src_sha" -- 2>/dev/null || git -C "$REPO" checkout --detach "$src_sha" || { print_error "    checkout не удался"; return 3; }
    if ! git -C "$REPO" merge -X ours "$tgt_sha" -m "merge: миграция $src -> $tgt, приоритет источника"; then
        print_error "    Слияние остановилось — разрешите вручную"
        git -C "$REPO" checkout "$orig_branch" 2>/dev/null || true
        return 3
    fi
    if ! git -C "$REPO" push "$tgt" "HEAD:refs/heads/$BRANCH"; then
        print_error "    push не удался"
        git -C "$REPO" checkout "$orig_branch" 2>/dev/null || true
        return 4
    fi
    # Локальную ветку обновляем только fast-forward
    if [ "$(git -C "$REPO" rev-parse "$orig_branch" 2>/dev/null)" = "$src_sha" ]; then
        git -C "$REPO" checkout "$orig_branch" >/dev/null 2>&1
        git -C "$REPO" merge --ff-only HEAD >/dev/null 2>&1 \
            && print_success "    ✓ Локальная ветка обновлена fast-forward" \
            || print_warn "    ⚠ Локальная ветка не обновлена"
    else
        git -C "$REPO" checkout "$orig_branch" >/dev/null 2>&1
        print_warn "    ⚠ Локальная ветка осталась прежней (обновите синхронизацией)"
    fi
    git -C "$REPO" fetch "$tgt" >/dev/null 2>&1 || true
    print_success "    ✓ УСПЕХ: $tgt получил слияние с приоритетом $src"
    return 0
}

main() {
    load_env
    [ -n "${1:-}" ] || { echo "Использование: git-project-migrate.sh <slug> [--source <имя|local>] --target <имя>"; exit 2; }
    local slug="$1"; shift
    local src_arg="" tgt_arg=""
    while [ $# -gt 0 ]; do
        case "$1" in
            --source) src_arg="${2:-}"; shift 2 ;;
            --target) tgt_arg="${2:-}"; shift 2 ;;
            *) print_error "Неизвестный аргумент: $1"; exit 2 ;;
        esac
    done

    collect "$slug"
    show_table "$slug"

    local si ti
    if [ -n "$src_arg" ]; then
        si=-1; local i
        for i in "${!NAMES[@]}"; do [ "${NAMES[$i]}" = "$src_arg" ] && si=$i; done
        [ $si -ge 0 ] || { print_error "Источник $src_arg не найден"; exit 2; }
    else
        print_header ""
        print_info "  Выберите ИСТОЧНИК миграции (рекомендуется 🟢 самый свежий)"
        si=$(( $(read_num "${#NAMES[@]}" "Источник") - 1 ))
    fi
    if [ -n "$tgt_arg" ]; then
        ti=-1; local j
        for j in "${!NAMES[@]}"; do [ "${NAMES[$j]}" = "$tgt_arg" ] && ti=$j; done
        [ $ti -ge 0 ] || { print_error "Приёмник $tgt_arg не найден"; exit 2; }
    else
        print_info "  Выберите ПРИЁМНИК миграции"
        ti=$(( $(read_num "${#NAMES[@]}" "Приёмник") - 1 ))
    fi

    local code="MIGRATE-$(echo "${NAMES[$si]}" | tr '[:lower:]' '[:upper:]')-TO-$(echo "${NAMES[$ti]}" | tr '[:lower:]' '[:upper:]')"
    print_header ""
    print_warn "  Будет изменён remote: ${NAMES[$ti]} (${DOMAINS[$ti]})"
    print_hint "Введите код подтверждения: $code"
    local confirm
    read -r -p "$(echo -e "  ${CYAN}➤ Подтверждение: ${RESET}")" confirm
    if [ "$confirm" != "$code" ]; then
        print_info "  Отмена миграции"
        exit 0
    fi

    do_migrate "$si" "$ti"
    local rc=$?
    log_to_host "$(echo "${DOMAINS[$ti]}" | sed -E 's|https?://([^/]+)/.*|\1|')" "migrate: $slug ${NAMES[$si]} -> ${NAMES[$ti]} (rc=$rc)"
    show_table "$slug"
    exit $rc
}

main "$@"
