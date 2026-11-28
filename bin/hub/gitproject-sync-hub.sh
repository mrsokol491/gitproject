#!/usr/bin/env bash
# gitproject-sync-hub.sh — мульти-push самого хаба на все настроенные remote.
# Автоматически добавляет remote из .env, если токен не placeholder.
# --tag <version> "<message>" создаёт аннотированный тег и пушит его на все remote.
# Сообщение релиза используется и как сообщение авто-коммита локальных изменений
# (без тега авто-коммит получает служебное сообщение sync: gitproject hub <дата>).

set -uo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../lib/common.sh"

ENV_FILE="${HUB_ROOT}/.env"
HOSTS_JSON="${HUB_ROOT}/config/hosts.json"
export ENV_FILE HOSTS_JSON

# ============================================================================
# Автоматическое добавление remote из .env
# ============================================================================
ensure_remotes_from_env() {
    local repo="$1"
    local added=0
    local line key value prefix token host_id domain url_template owner_var owner slug url remote_name

    [ -f "$ENV_FILE" ] || return 0
    [ -f "$HOSTS_JSON" ] || return 0

    while IFS= read -r line || [ -n "$line" ]; do
        line="${line%$'\r'}"
        case "$line" in ''|\#*) continue ;; esac
        case "$line" in *=*) ;; *) continue ;; esac
        key="${line%%=*}"
        value="${line#*=}"

        if [[ "$key" =~ ^([A-Z][A-Z_]*)_HUB_TOKEN$ ]]; then
            prefix="${BASH_REMATCH[1]}"
            token="$value"
            [ -z "$token" ] && continue
            [ "$token" = "placeholder" ] && continue

            host_id="$(printf '%s' "$prefix" | tr '[:upper:]' '[:lower:]')"
            domain="$(jq -r --arg id "$host_id" '.[$id].host // empty' "$HOSTS_JSON" 2>/dev/null)"
            url_template="$(jq -r --arg id "$host_id" '.[$id].url_template // empty' "$HOSTS_JSON" 2>/dev/null)"
            [ -z "$domain" ] && continue
            [ -z "$url_template" ] && continue

            owner_var="${prefix}_HUB_USER"
            owner="$(sed -n "s/^${owner_var}=//p" "$ENV_FILE" 2>/dev/null | tail -n 1 | tr -d '\r')"
            [ -z "$owner" ] && { print_warn "    Пропускаю ${host_id}: задайте ${owner_var} в .env"; continue; }

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
# Push тега на все remote
# ============================================================================
push_tag_to_all_remotes() {
    local tag="$1"
    shift
    local -a remotes=("$@")

    if [ ${#remotes[@]} -eq 0 ]; then
        print_warn "    Нет remote для push тега $tag"
        return 1
    fi

    print_info "    Публикация тега: $tag"
    local success=0 fail=0
    for remote in "${remotes[@]}"; do
        print_info "    Тег $tag -> $remote"
        if git push "$remote" "$tag" 2>/dev/null; then
            print_success "    ✓ УСПЕХ: tag $tag -> $remote"
            success=$((success + 1))
        else
            print_error "    ✗ Ошибка push тега на $remote"
            fail=$((fail + 1))
        fi
    done

    if [ "$fail" -eq 0 ]; then
        print_success "    Тег $tag опубликован на $success remote"
    else
        print_warn "    Тег $tag: $success успешно, $fail ошибок"
    fi
    return $fail
}

# ============================================================================
# Главная функция
# ============================================================================
main() {
    load_env
    cd "$HUB_ROOT" || exit 2
    [ -d ".git" ] || { print_error "Хаб не является git-репозиторием"; exit 2; }

    # Identity-guard: repo-local, если глобальный не настроен (новые устройства)
    if ! git config user.email >/dev/null 2>&1 || ! git config user.name >/dev/null 2>&1; then
        git config user.name "GitProject Hub"
        git config user.email "hub@localhost"
        print_info "    repo-local git identity задан (глобальный не настроен)"
    fi

    TAG_VERSION=""
    TAG_MESSAGE=""
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --tag)
                TAG_VERSION="${2:-}"
                TAG_MESSAGE="${3:-}"
                if [ -z "$TAG_VERSION" ] || [ -z "$TAG_MESSAGE" ]; then
                    print_error "Использование: --tag <version> \"<message>\""
                    exit 2
                fi
                shift 3
                ;;
            *)
                print_error "Неизвестный аргумент: $1"
                exit 2
                ;;
        esac
    done

    local branch
    branch="$(git branch --show-current 2>/dev/null)"
    [ -n "$branch" ] || { print_error "Не определена текущая ветка"; exit 2; }

    print_header "==> Мульти-push хаба (все remote)"
    print_info "    Ветка: $branch"
    [ -n "$TAG_VERSION" ] && print_info "    Тег: $TAG_VERSION — $TAG_MESSAGE"

    print_info "    Проверяю remote из .env..."
    ensure_remotes_from_env "$HUB_ROOT"

    local -a remotes=()
    while read -r remote; do
        [ -n "$remote" ] && remotes+=("$remote")
    done < <(git remote 2>/dev/null | sort)

    if [ ${#remotes[@]} -eq 0 ]; then
        print_warn "    У хаба нет настроенных remote"
        exit 1
    fi
    print_info "    Remote: ${remotes[*]}"

    # --- Коммит и push ветки ---
    local branch_fail=0
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
        local commit_msg
        if [ -n "$TAG_MESSAGE" ]; then
            commit_msg="$TAG_MESSAGE"
        else
            commit_msg="sync: gitproject hub $(date +%Y-%m-%d_%H:%M)"
        fi
        git commit -q -m "$commit_msg"
        print_success "    ✓ Коммит создан: $commit_msg"

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
        branch_fail=$fail

        if [ "$fail" -gt 0 ]; then
            print_warn "    Мульти-push ветки с ошибками: $success успешно, $fail ошибок"
            if [ -n "$TAG_VERSION" ]; then
                print_warn "    Тег $TAG_VERSION не опубликован из-за ошибок push ветки"
            fi
        else
            print_success "    Мульти-push ветки завершён: $success remote"
        fi
    else
        print_gray "    Рабочий каталог чист"
    fi

    # --- Создание и push тега ---
    if [ -n "$TAG_VERSION" ] && [ "$branch_fail" -eq 0 ]; then
        print_info ""
        if git rev-parse "$TAG_VERSION" >/dev/null 2>&1; then
            print_warn "    Тег $TAG_VERSION уже существует — пропускаю создание"
        else
            print_info "    Создаю аннотированный тег: $TAG_VERSION"
            if git tag -a "$TAG_VERSION" -m "$TAG_MESSAGE"; then
                print_success "    ✓ Тег $TAG_VERSION создан"
            else
                print_error "    ✗ Не удалось создать тег $TAG_VERSION"
                exit 1
            fi
        fi
        push_tag_to_all_remotes "$TAG_VERSION" "${remotes[@]}"
    fi


    exit "$branch_fail"
}

main "$@"