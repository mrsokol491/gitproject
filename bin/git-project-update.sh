#!/usr/bin/env bash
# gitflic-sync-hub.sh — синхронизация и публикация репозитория workspace (хаба) ~/gitproject.
# Аналог bin/gitflic-sync.sh, но для репозитория самой оболочки.
#
# Вызовы:
#   gitflic-sync-hub.sh [-m "сообщение"] — полный цикл: fetch, сверка, коммит локальных
#       изменений (защита запрещённых путей), merge upstream (приоритет локального,
#       merge -X ours), push локальная:upstream, сверка хэшей.
#   gitflic-sync-hub.sh status — состояние хаба.
#   gitflic-sync-hub.sh fix    — подготовка: init -b master, origin, .gitignore, data/.gitkeep.
#
# В коммит хаба НИКОГДА не попадают: .env, data/ (кроме .gitkeep), projects/,
# логи, служебные файлы SQLite. Запрещены push --force и переписывание истории.
# Коды выхода: 0 успех; 2 окружение; 3 конфликт; 4 push/хэши; 5 запрещённые пути.

set -uo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; GRAY='\033[0;90m'; BOLD='\033[1m'; RESET='\033[0m'

print_header()  { echo -e "${BLUE}${BOLD}$1${RESET}"; }
print_success() { echo -e "${GREEN}$1${RESET}"; }
print_error()   { echo -e "${RED}$1${RESET}" >&2; }
print_warn()    { echo -e "${YELLOW}$1${RESET}"; }
print_info()    { echo -e "${CYAN}$1${RESET}"; }
print_gray()    { echo -e "${GRAY}$1${RESET}"; }

HUB="$HOME/gitproject"
ENV_FILE="$HUB/.env"
HUB_SLUG="${HUB_UPDATE_SLUG:-gitproject}"   # фактический slug на GitFlic (нижний регистр), факт 2026-09-29

GITFLIC_USER=""

load_env_soft() {
    GITFLIC_USER="$(sed -n 's/^GITFLIC_USER=//p' "$ENV_FILE" 2>/dev/null | tail -n 1 | tr -d '\r')"
    GITFLIC_USER="${GITFLIC_USER:-sokol491}"
}

remote_url() {
    local u
    if [ -d "$HUB/.git" ] && u="$(git -C "$HUB" remote get-url origin 2>/dev/null)" && [ -n "$u" ]; then
        printf '%s' "$u"
    else
        printf 'https://gitflic.ru/project/%s/%s.git' "$GITFLIC_USER" "$HUB_SLUG"
    fi
}

is_local_repo() { [ -d "$HUB/.git" ] && git -C "$HUB" rev-parse --is-inside-work-tree >/dev/null 2>&1; }

remote_repo_exists() {
    local output
    output="$(git ls-remote "$(remote_url)" 2>&1)"
    [[ ! "$output" =~ "Repository not found" && ! "$output" =~ "fatal:" && ! "$output" =~ "error:" ]]
}

write_gitignore() {
    cat > "$HUB/.gitignore" <<'EOF'
# Секреты
.env
# Логи и приватные данные хаба
/data/*
!/data/.gitkeep
# Контейнер репозиториев других проектов
/projects/
# Логи и служебные файлы SQLite
*.log
*.sqlite
*.sqlite-journal
*.sqlite-wal
*.sqlite-shm
# Резервные копии файлов перед правками
*.bak-*
EOF
}

cmd_fix() {
    if is_local_repo; then
        print_success "локальный git: да"
    else
        git -C "$HUB" init -b master && print_success "локальный git: инициализирован (master)"
    fi
    if ! { git -C "$HUB" config --get user.name >/dev/null && git -C "$HUB" config --get user.email >/dev/null; }; then
        print_warn "ВНИМАНИЕ: не настроена идентичность git (guide раздел 4)"
    fi
    if ! git -C "$HUB" remote get-url origin >/dev/null 2>&1; then
        git -C "$HUB" remote add origin "$(remote_url)" && print_success "origin: добавлен ($(remote_url))"
    else
        print_info "origin: $(git -C "$HUB" remote get-url origin)"
    fi
    if [ ! -f "$HUB/.gitignore" ]; then
        write_gitignore && print_success ".gitignore: создан по шаблону хаба"
    else
        grep -q '^/projects/' "$HUB/.gitignore" || print_warn "ВНИМАНИЕ: в .gitignore нет строки /projects/"
        grep -q '^\.env' "$HUB/.gitignore" || print_warn "ВНИМАНИЕ: в .gitignore нет строки .env"
    fi
    mkdir -p "$HUB/data"
    [ -f "$HUB/data/.gitkeep" ] || touch "$HUB/data/.gitkeep"
    if remote_repo_exists; then
        print_success "GitFlic: репозиторий хаба существует, remote доступен"
    else
        print_warn "GitFlic: репозитория хаба нет — создайте через Web-UI (guide раздел 7)"
    fi
}

cmd_status() {
    if ! is_local_repo; then
        print_warn "хаб: не git-репозиторий (выполните: gitflic-sync-hub.sh fix)"
        return 0
    fi
    local branch up dirty ahead behind
    branch="$(git -C "$HUB" branch --show-current 2>/dev/null)"
    [ -n "$branch" ] || branch="(без коммитов)"
    up="$(git -C "$HUB" rev-parse --abbrev-ref '@{u}' 2>/dev/null || true)"
    dirty="$(git -C "$HUB" status --porcelain 2>/dev/null | wc -l)"
    ahead="-"; behind="-"
    if [ -n "$up" ] && git -C "$HUB" rev-parse HEAD >/dev/null 2>&1; then
        ahead="$(git -C "$HUB" log --oneline "${up}..HEAD" 2>/dev/null | wc -l)"
        behind="$(git -C "$HUB" log --oneline "HEAD..${up}" 2>/dev/null | wc -l)"
    fi
    print_header "хаб:            $HUB"
    print_info    "ветка:          $branch${up:+ -> ${up#origin/}}"
    print_info    "ahead/behind:   $ahead/$behind (по последнему fetch)"
    print_info    "грязных файлов: $dirty"
    if remote_repo_exists; then
        print_success "GitFlic:        репозиторий хаба существует ($(remote_url))"
    else
        print_error "GitFlic:        репозитория хаба нет ($(remote_url))"
    fi
}

cmd_sync() {
    local commit_msg="${1:-}"
    cd "$HUB" || exit 2
    if ! is_local_repo; then
        print_error "ОШИБКА: $HUB не является git-репозиторием."
        print_info "Подсказка: gitflic-sync-hub.sh fix"
        exit 2
    fi
    if ! { git config --get user.name >/dev/null && git config --get user.email >/dev/null; }; then
        print_error "ОШИБКА: не настроена идентичность git (user.name / user.email)."
        exit 2
    fi

    local branch upstream remote_branch remote_ref
    branch="$(git branch --show-current)"
    if [ -z "$branch" ]; then
        print_error "ОШИБКА: detached HEAD — синхронизация невозможна."
        exit 2
    fi
    upstream="$(git rev-parse --abbrev-ref '@{u}' 2>/dev/null || true)"
    if [ -n "$upstream" ]; then
        remote_branch="${upstream#origin/}"
    else
        remote_branch="$branch"
    fi
    remote_ref="origin/$remote_branch"

    print_header "== СВЕРКА: $HUB_SLUG (локально $branch -> GitFlic $remote_branch) =="

    if ! remote_repo_exists; then
        print_error "ОШИБКА: репозиторий хаба на GitFlic отсутствует ($(remote_url))."
        print_info "Создайте через Web-UI (guide раздел 7): имя $HUB_SLUG, пустой, без README."
        exit 2
    fi

    if ! git fetch origin >/dev/null 2>&1; then
        print_error "ОШИБКА: git fetch origin не удался (сеть/доступ). Проверьте credential helper."
        exit 2
    fi

    local has_remote=0 has_local=0
    git rev-parse HEAD >/dev/null 2>&1 && has_local=1
    git show-ref --verify --quiet "refs/remotes/$remote_ref" && has_remote=1

    local local_head ahead_list behind_list remote_head
    local_head="$(git rev-parse --short HEAD 2>/dev/null || echo '(нет коммитов)')"
    ahead_list=""; behind_list=""
    if [ "$has_local" -eq 1 ]; then
        ahead_list="$(git log --oneline "${remote_ref}..HEAD" 2>/dev/null || true)"
    fi
    if [ "$has_remote" -eq 1 ] && [ "$has_local" -eq 1 ]; then
        remote_head="$(git rev-parse --short "$remote_ref")"
        behind_list="$(git log --oneline "HEAD..${remote_ref}" 2>/dev/null || true)"
        print_info "Локально:  $local_head | На GitFlic ($remote_branch): $remote_head"
    else
        print_info "Локально:  $local_head | На GitFlic: ветка $remote_branch отсутствует (будет создана)"
    fi

    local ahead_n=0 behind_n=0
    [ -n "$ahead_list" ] && ahead_n="$(wc -l <<<"$ahead_list")"
    [ -n "$behind_list" ] && behind_n="$(wc -l <<<"$behind_list")"
    print_info "Коммитов локально, которых нет на GitFlic: $ahead_n"
    [ -n "$ahead_list" ] && echo "$ahead_list" | head -n 10 | sed 's/^/  > /'
    print_info "Коммитов на GitFlic, которых нет локально:  $behind_n"
    [ -n "$behind_list" ] && echo "$behind_list" | head -n 10 | sed 's/^/  < /'

    if [ -n "$(git status --porcelain)" ]; then
        print_info "-- Коммит локальных изменений --"
        git add -A
        local forbidden
        forbidden="$(git diff --cached --name-only \
            | grep -E '^\.env($|[^.])|^\.env\.(local|prod|production)$|^data/|^projects/|\.log$|\.sqlite(-journal|-wal|-shm)?$|\.ged$' \
            | grep -vE '^data/\.gitkeep$|\.env\.example$' || true)"
        if [ -n "$forbidden" ]; then
            print_error "ОШИБКА: в staging попали запрещённые пути — коммит отменён:"
            echo "$forbidden" | sed 's/^/  ! /' >&2
            git reset -q
            exit 5
        fi
        local msg="$commit_msg"
        [ -z "$msg" ] && msg="sync: $HUB_SLUG $(date +%Y-%m-%d_%H:%M) — локальные изменения"
        git commit -q -m "$msg"
        print_success "Создан коммит: $(git log --oneline -1)"
    else
        print_gray "-- Рабочий каталог чист, некоммиченных изменений нет --"
        if [ "$has_local" -eq 0 ]; then
            print_error "ОШИБКА: нет ни коммитов, ни изменений — публиковать нечего."
            exit 2
        fi
    fi

    if [ "$has_remote" -eq 1 ] && [ "$behind_n" -gt 0 ]; then
        print_info "-- Интеграция $remote_ref (конфликты — в пользу локальной версии) --"
        if ! git merge -X ours "$remote_ref" \
            -m "merge: интеграция $remote_ref, приоритет локальных изменений"; then
            print_error "ОШИБКА: слияние остановилось (конфликт вне содержимого). Разрешите вручную:"
            print_info "  git status / git add ... / git commit, затем повторите синхронизацию."
            exit 3
        fi
    fi

    print_info "-- Публикация на GitFlic: $branch -> $remote_branch --"
    if [ "$has_remote" -eq 1 ]; then
        git push origin "$branch:$remote_branch" || exit 4
    else
        git push -u origin "$branch:$remote_branch" || exit 4
    fi

    local local_now remote_now
    local_now="$(git rev-parse HEAD)"
    remote_now="$(git ls-remote origin "refs/heads/$remote_branch" | awk '{print $1}')"
    if [ -n "$remote_now" ] && [ "$local_now" = "$remote_now" ]; then
        print_success "УСПЕХ: $HUB_SLUG/$branch -> $remote_branch синхронизирован, хэш ${local_now:0:7} совпадает с GitFlic."
        exit 0
    fi
    print_error "ВНИМАНИЕ: хэши не совпали (локально ${local_now:0:7}, на GitFlic ${remote_now:0:7})."
    print_info "Повторите синхронизацию или проверьте вывод push выше."
    exit 4
}

load_env_soft

MODE="${1:-sync}"
if [ "$MODE" = "status" ] || [ "$MODE" = "fix" ]; then
    shift 2>/dev/null || true
    case "$MODE" in
        status) cmd_status; exit 0 ;;
        fix)    cmd_fix;    exit 0 ;;
    esac
fi

COMMIT_MSG=""
if [ "${1:-}" = "-m" ]; then
    COMMIT_MSG="${2:-}"
fi
cmd_sync "$COMMIT_MSG"
