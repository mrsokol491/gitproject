#!/usr/bin/env bash
# gitproject-sync-hub.sh — мульти-push самого хаба на все настроенные remote.
# Отличается от git-project-update.sh тем, что работает со всеми remote хаба
# (origin, gitverse, ...) за один вызов.

set -uo pipefail

source "$HOME/gitproject/bin/lib/common.sh"

HUB="$HOME/gitproject"

main() {
    load_env
    cd "$HUB" || exit 2
    [ -d ".git" ] || { print_error "Хаб не является git-репозиторием"; exit 2; }

    local branch
    branch="$(git branch --show-current 2>/dev/null)"
    [ -n "$branch" ] || { print_error "Не определена текущая ветка"; exit 2; }

    local -a remotes=()
    while read -r remote; do
        [ -n "$remote" ] && remotes+=("$remote")
    done < <(git remote 2>/dev/null | sort)

    if [ ${#remotes[@]} -eq 0 ]; then
        print_warn "У хаба нет настроенных remote"
        exit 1
    fi

    print_header "==> Мульти-push хаба (все remote)"
    print_info "    Ветка:  $branch"
    print_info "    Remote: ${remotes[*]}"

    # Коммит локальных изменений (один раз перед push на все remote)
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
        if git push "$remote" "$branch"; then
            print_success "    ✓ УСПЕХ: hub/$branch -> $remote"
            ((success++))
        else
            print_error "    ✗ Ошибка push на $remote"
            ((fail++))
        fi
    done

    print_header ""
    [ $fail -eq 0 ] && print_success "    Мульти-push хаба завершён: $success remote" \
                    || print_warn "    Мульти-push хаба с ошибками: $success успешно, $fail ошибок"
    exit $fail
}

main "$@"
