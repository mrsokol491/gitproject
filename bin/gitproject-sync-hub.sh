#!/usr/bin/env bash
# gitproject-sync-hub.sh — мульти-push самого хаба на все настроенные remote.
# Автоматически добавляет remote из .env, если токен не placeholder.
# FIX: .env парсится построчно (key=${line%%=*}, value=${line#*=}); раньше IFS='='
# отрезал значение от key и regex не совпадал — remote не добавлялись никогда.

set -uo pipefail

source "$HOME/gitproject/bin/lib/common.sh"

HUB="$HOME/gitproject"
ENV_FILE="$HOME/gitproject/.env"

# ============================================================================
# Автоматическое добавление remote из .env
# ============================================================================

ensure_remotes_from_env() {
    local repo="$1"
    local added=0
    local line key value prefix token host_id domain url_template owner_var owner slug url remote_name

    while IFS= read -r line || [ -n "$line" ]; do
        line="${line%$'\r'}"
        case "$line" in
            ''|\#*) continue ;;
        esac
        case "$line" in
            *=*) ;;
            *) continue ;;
        esac
        key="${line%%=*}"
        value="${line#*=}"

        if [[ "$key" =~ ^([A-Z][A-Z_]*)_HUB_TOKEN$ ]]; then
            prefix="${BASH_REMATCH[1]}"
            token="$value"
            [ -z "$token" ] && continue
            [ "$token" = "placeholder" ] && continue

            host_id="$(printf '%s' "$prefix" | tr '[:upper:]' '[:lower:]')"
            domain=""
            url_template=""
            if [ -f "$HOME/gitproject/config/hosts.json" ]; then
                domain="$(jq -r --arg id "$host_id" '.[$id].host // empty' "$HOME/gitproject/config/hosts.json" 2>/dev/null)"
                url_template="$(jq -r --arg id "$host_id" '.[$id].url_template // empty' "$HOME/gitproject/config/hosts.json" 2>/dev/null)"
            fi
            [ -z "$domain" ] && continue
            [ -z "$url_template" ] && continue

            owner_var="${prefix}_HUB_USER"
            owner="$(sed -n "s/^${owner_var}=//p" "$ENV_FILE" 2>/dev/null | tail -n 1 | tr -d '\r')"
            [ -z "$owner" ] && owner="sokol491"

            slug="gitproject"
            url="${url_template//\{owner\}/$owner}"
            url="${url//\{slug\}/$slug}"

            remote_name="$host_id"
            if ! git -C "$repo" remote get-url "$remote_name" >/dev/null 2>&1; then
                print_info "    Добавляю remote: $remote_name ($url)"
                if git -C "$repo" remote add "$remote_name" "$url" 2>/dev/null; then
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
# Главная функция
# ============================================================================

main() {
    load_env
    cd "$HUB" || exit 2
    [ -d ".git" ] || { print_error "Хаб не является git-репозиторием"; exit 2; }

    local branch
    branch="$(git branch --show-current 2>/dev/null)"
    [ -n "$branch" ] || { print_error "Не определена текущая ветка"; exit 2; }

    print_header "==> Мульти-push хаба (все remote)"
    print_info "    Ветка: $branch"

    print_info "    Проверяю remote из .env..."
    ensure_remotes_from_env "$HUB"

    local -a remotes=()
    while read -r remote; do
        [ -n "$remote" ] && remotes+=("$remote")
    done < <(git remote 2>/dev/null | sort)

    if [ ${#remotes[@]} -eq 0 ]; then
        print_warn "    У хаба нет настроенных remote"
        exit 1
    fi
    print_info "    Remote: ${remotes[*]}"

    if [ -n "$(git status --porcelain)" ]; then
        print_info "    Коммит локальных изменений..."
        git add -A
        local forbidden
        forbidden="$(git diff --cached --name-only \
            | grep -E '^\.env($|[^.])|^\.env\.(local|prod|production)$|^data/|^projects/|\.log$|\.sqlite(-journal|-wal|-shm)?$' \
            | grep -vE '^data/\.gitkeep$|\.env\.example$' || true)"
        if [ -n "$forbidden" ]; then
            print_error "    В staging запрещённые пути:"
            echo "$forbidden" | sed 's/^/      ! /' >&2
            git reset -q
            exit 5
        fi
        git commit -q -m "sync: gitproject hub $(date +%Y-%m-%d_%H:%M)"
        print_success "    ✓ Коммит создан"
    else
        print_gray "    Рабочий каталог чист"
    fi

    local success=0 fail=0
    for remote in "${remotes[@]}"; do
        print_info ""
        print_info "    Публикация: $branch -> $remote"
        if git push "$remote" "$branch" 2>&1 | grep -E "Writing objects|up-to-date|fast-forward|remote: Updating references" >/dev/null; then
            print_success "    ✓ УСПЕХ: hub/$branch -> $remote"
            success=$((success + 1))
        else
            if git push "$remote" "$branch" 2>/dev/null; then
                print_success "    ✓ УСПЕХ: hub/$branch -> $remote"
                success=$((success + 1))
            else
                print_error "    ✗ Ошибка push на $remote"
                fail=$((fail + 1))
            fi
        fi
    done

    # Маркетплейс: освежить запись хаба после публикации (если CLI ещё существует)
    if command -v php >/dev/null 2>&1 && [ -f "$HUB/bin/marketplace.php" ]; then
        print_info "    Освежаю запись хаба в маркетплейсе..."
        php "$HUB/bin/marketplace.php" refresh-hub >&2 || true
    fi

    print_header ""
    if [ "$fail" -eq 0 ]; then
        print_success "    Мульти-push хаба завершён: $success remote"
    else
        print_warn "    Мульти-push хаба с ошибками: $success успешно, $fail ошибок"
    fi
    exit $fail
}

main "$@"