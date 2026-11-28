#!/usr/bin/env bash
# gitproject-force-update.sh — принудительное обновление хаба gitproject из GitVerse.
# Источник (основной репозиторий): https://gitverse.ru/Sokol491/gitproject.git
# Семантика: fetch + reset --hard до состояния сервера (сервер всегда прав).
# В отличие от gitproject-pull.sh (--ff-only) этот скрипт ОТБРАСЫВАЕТ локальные
# коммиты и правки, сохраняя их перед этим:
#   - локальные коммиты      -> backup-ветка backup/pre-force-<timestamp>
#   - незакоммиченные правки -> git stash
# Секреты и данные не трогаются: .env, data/, storage/, vendor/, projects/.
# ЗАПРЕЩЕНО: запускать через sudo. Токены напрямую не используются —
# доступ через credential helper (bin/git-credential-multi.sh) из .env.

set -euo pipefail

REMOTE_NAME="gitverse"
REMOTE_URL="https://gitverse.ru/Sokol491/gitproject.git"
DEFAULT_BRANCH="master"

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'
BLUE=$'\033[0;34m'; CYAN=$'\033[0;36m'; BOLD=$'\033[1m'; RESET=$'\033[0m'

# Весь интерактив и логи — в stderr (stdout остаётся чистым).
print_header()  { printf '%b\n' "${BLUE}${BOLD}$1${RESET}" >&2; }
print_info()    { printf '%b\n' "${CYAN}$1${RESET}" >&2; }
print_success() { printf '%b\n' "${GREEN}$1${RESET}" >&2; }
print_warn()    { printf '%b\n' "${YELLOW}$1${RESET}" >&2; }
print_error()   { printf '%b\n' "${RED}$1${RESET}" >&2; }
die() { print_error "✗ ОШИБКА: $1"; exit 1; }

usage() {
    cat >&2 <<'USAGE'
Использование: gitproject-force-update.sh [ветка] [--yes] [--clean]
  ветка       ветка GitVerse (по умолчанию текущая; если HEAD detached — master)
  --yes       без подтверждения Д/Н (для автоматизации; ввод пароля git отключается)
  --clean     удалить untracked-файлы перед переключением,
              КРОМЕ: .env, data/, storage/, vendor/, projects/ (и git-ignored)
  -h, --help  эта справка
Пример: ~/gitproject/bin/gitproject-force-update.sh master --yes
USAGE
}

log_event() {
    local log_dir
    log_dir="$(git rev-parse --show-toplevel)/data"
    mkdir -p "$log_dir"
    printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1" >> "$log_dir/gitverse.ru.log"
}

main() {
    local branch_arg="" assume_yes=0 do_clean=0
    while [ $# -gt 0 ]; do
        case "$1" in
            --yes) assume_yes=1 ;;
            --clean) do_clean=1 ;;
            -h|--help) usage; exit 0 ;;
            --*) die "неизвестный флаг: $1 (см. --help)" ;;
            *)
                [ -z "$branch_arg" ] || die "ветка уже задана: $branch_arg"
                branch_arg="$1"
                ;;
        esac
        shift
    done

    [ "$(id -u)" -ne 0 ] || die "запуск через sudo/root запрещён (правила хаба)"
    command -v git >/dev/null 2>&1 || die "git не найден в PATH"

    local script_dir hub
    script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
    hub="$(dirname -- "$script_dir")"
    cd -- "$hub"
    git rev-parse --is-inside-work-tree >/dev/null 2>&1 || die "$hub не является git-репозиторием"

    local git_dir
    git_dir="$(git rev-parse --absolute-git-dir)"
    if [ -e "$git_dir/MERGE_HEAD" ] || [ -e "$git_dir/CHERRY_PICK_HEAD" ] \
        || [ -d "$git_dir/rebase-merge" ] || [ -d "$git_dir/rebase-apply" ]; then
        die "незавершённый merge/rebase/cherry-pick: сначала git merge --abort / git rebase --abort"
    fi

    # Remote gitverse: создаём или выравниваем URL (идемпотентно).
    local current_url
    if current_url="$(git remote get-url "$REMOTE_NAME" 2>/dev/null)"; then
        if [ "$current_url" != "$REMOTE_URL" ]; then
            print_warn "    remote '$REMOTE_NAME': URL отличается, выравниваю на $REMOTE_URL"
            git remote set-url "$REMOTE_NAME" "$REMOTE_URL"
        fi
    else
        print_info "    Добавляю remote '$REMOTE_NAME' -> $REMOTE_URL"
        git remote add "$REMOTE_NAME" "$REMOTE_URL"
    fi

    local current_branch branch
    current_branch="$(git symbolic-ref --quiet --short HEAD || true)"
    branch="${branch_arg:-${current_branch:-$DEFAULT_BRANCH}}"
    if [ -z "$current_branch" ]; then
        print_warn "    HEAD detached или история пуста — целевая ветка: $branch"
    fi

    if [ "$assume_yes" -eq 1 ]; then
        export GIT_TERMINAL_PROMPT=0
    fi

    print_header "==> Принудительное обновление хаба из GitVerse"
    print_info "    Получаю '$branch' из $REMOTE_URL ..."
    git fetch "$REMOTE_NAME" "+refs/heads/$branch:refs/remotes/$REMOTE_NAME/$branch" >&2 \
        || die "fetch не удался: проверьте ветку '$branch', сеть и credential helper (.env)"

    local new_sha old_sha ahead=0 behind=0
    new_sha="$(git rev-parse --verify "refs/remotes/$REMOTE_NAME/$branch")"
    old_sha="$(git rev-parse --verify --quiet HEAD || true)"
    if [ -n "$old_sha" ]; then
        read -r ahead behind < <(git rev-list --left-right --count "$old_sha...$new_sha")
    fi

    local dirty_tracked=0
    if [ -n "$old_sha" ]; then
        if ! git diff --quiet || ! git diff --cached --quiet; then
            dirty_tracked=1
        fi
    fi

    if [ -n "$old_sha" ] && [ "$old_sha" = "$new_sha" ] \
        && [ "$dirty_tracked" -eq 0 ] && [ "$do_clean" -eq 0 ]; then
        print_success "✓ Версия хаба уже совпадает с GitVerse: $(git log -1 --format='%h %s' "$new_sha")"
        exit 0
    fi

    local state
    if [ -z "$old_sha" ]; then
        state="⚪ локальная история пуста — будет создана ветка '$branch'"
    elif [ "$ahead" -eq 0 ] && [ "$behind" -eq 0 ]; then
        state="🟢 коммиты идентичны (будет выровнено рабочее дерево)"
    elif [ "$ahead" -eq 0 ]; then
        state="🟡 💻 на компьютере отстаёт на $behind коммит(ов)"
    elif [ "$behind" -eq 0 ]; then
        state="🔴 💻 на компьютере опережает на $ahead — эти коммиты уйдут в backup-ветку"
    else
        state="🔴 истории разошлись: локальных $ahead, серверных $behind — локальные уйдут в backup-ветку"
    fi

    print_info "    Откуда: GitVerse (основной), ветка '$branch'"
    print_info "            $(git log -1 --format='%h %s' "$new_sha")"
    print_info "    Куда:   💻 на компьютере — $hub, ветка '$branch'"
    if [ -n "$old_sha" ]; then
        print_info "            $(git log -1 --format='%h %s' "$old_sha")"
    fi
    print_info "    Состояние: $state"
    if [ "$dirty_tracked" -eq 1 ]; then
        print_warn "    Незакоммиченные правки будут сохранены в git stash."
    fi
    if [ "$do_clean" -eq 1 ]; then
        print_warn "    --clean: будут удалены untracked-файлы (кроме .env, data/, storage/, vendor/, projects/):"
        git clean -nd -e .env -e data -e storage -e vendor -e projects >&2
    fi

    if [ "$assume_yes" -ne 1 ]; then
        [ -t 0 ] || die "stdin не интерактивен: для запуска без вопросов добавьте --yes"
        print_warn "    💡 Локальная версия будет ПЕРЕЗАПИСАНА версией GitVerse. Подтвердить? (Д — да, Н — нет)"
        local answer=""
        printf '    ➤ Ваш выбор: ' >&2
        IFS= read -r answer || true
        case "$answer" in
            Д|д|Y|y) ;;
            *)
                log_event "force-update отменён владельцем (ответ: '$answer')"
                die "Отменено владельцем"
                ;;
        esac
    fi

    local stamp backup_branch=""
    stamp="$(date +%Y%m%d-%H%M%S)"
    if [ -n "$old_sha" ]; then
        backup_branch="backup/pre-force-$stamp"
        if git show-ref --verify --quiet "refs/heads/$backup_branch"; then
            backup_branch="${backup_branch}-$$"
        fi
        git branch -- "$backup_branch" "$old_sha" || die "не удалось создать backup-ветку"
        print_info "    Backup локальных коммитов: ветка '$backup_branch'"
    fi
    if [ "$dirty_tracked" -eq 1 ]; then
        git stash push -m "pre-force-update-$stamp" >&2 \
            || die "не удалось сохранить правки в stash (проверьте git user.name/user.email)"
        print_info "    Правки сохранены в stash (вернуть: git stash pop)"
    fi

    if [ "$do_clean" -eq 1 ]; then
        git clean -fd -e .env -e data -e storage -e vendor -e projects >&2
    fi

    if ! git checkout -B "$branch" "$new_sha" >&2; then
        die "checkout заблокирован untracked-файлами: повторите с --clean (backup/stash уже сохранены)"
    fi
    git reset --hard "$new_sha" >&2

    log_event "force-update ok: $branch ${old_sha:0:7} -> ${new_sha:0:7} (backup: ${backup_branch:-нет})"
    print_success "✓ УСПЕХ: '$branch' принудительно обновлена из GitVerse до $(git log -1 --format='%h %s' "$new_sha")"
    if [ -n "$backup_branch" ] && [ "$old_sha" != "$new_sha" ]; then
        print_info "    Откат при необходимости: git reset --hard '$backup_branch'"
    fi
}

# Весь файл парсится до вызова main: безопасно, даже если reset --hard
# перезапишет сам этот скрипт во время выполнения (самообновление хаба).
main "$@"