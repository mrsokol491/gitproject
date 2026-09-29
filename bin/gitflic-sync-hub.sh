#!/usr/bin/env bash
# gitflic-sync-hub.sh — синхронизация и публикация репозитория workspace (хаба) ~/gitflicProject.
# Аналог bin/gitflic-sync.sh, но для репозитория самой оболочки:
#   gitflic-sync.sh     -> проекты контейнера projects/<slug>
#   gitflic-sync-hub.sh -> репозиторий хаба (router.php, index.php, bin/, assets/, docs)
#
# ФАКТ 2026-09-24: фактический slug репозитория хаба на GitFlic — gitflicproject (нижний
# регистр); git-транспорт GitFlic регистрозависим, Web-UI открывает оба написания.
# Поэтому базовый URL строится с нижним регистром, а при настроенном origin
# используется именно URL origin (единственный источник истины по регистру).
#
# Вызовы:
#   gitflic-sync-hub.sh [-m "сообщение коммита"] — полный цикл: fetch, сверка
#       ahead/behind с upstream, коммит локальных изменений (защита запрещённых путей),
#       интеграция upstream с приоритетом локальной версии (merge -X ours),
#       публикация (push локальная:upstream), сверка хэшей.
#   gitflic-sync-hub.sh status — состояние хаба: git? ветка/upstream? грязь? репозиторий на GitFlic?
#   gitflic-sync-hub.sh fix    — подготовка к публикации: git init -b master (если нет),
#       origin, .gitignore (шаблон хаба), data/.gitkeep, контроль доступности.
#
# В коммит хаба НИКОГДА не попадают: .env, data/ (кроме .gitkeep), projects/
# (контейнер репозиториев других проектов), логи, служебные файлы SQLite.
# Запрещены: push --force, переписывание истории (guide раздел 4).
#
# Коды выхода: 0 успех; 2 ошибка аргументов/окружения (в т.ч. нет репозитория на GitFlic);
#              3 конфликт слияния; 4 ошибка push/расхождение хэшей; 5 запрещённые пути в staging.

set -uo pipefail

HUB="$HOME/gitflicProject"
ENV_FILE="$HUB/.env"
HUB_SLUG="gitflicproject"   # фактический slug на GitFlic (нижний регистр), факт 2026-09-24

GITFLIC_USER=""

load_env_soft() {
    GITFLIC_USER="$(sed -n 's/^GITFLIC_USER=//p' "$ENV_FILE" 2>/dev/null | tail -n 1 | tr -d '\r')"
    GITFLIC_USER="${GITFLIC_USER:-sokol491}"
}

# URL репозитория хаба: настроенный origin приоритетен, иначе конструируется с нижним slug
remote_url() {
    local u
    if [ -d "$HUB/.git" ] && u="$(git -C "$HUB" remote get-url origin 2>/dev/null)" && [ -n "$u" ]; then
        printf '%s' "$u"
    else
        printf 'https://gitflic.ru/project/%s/%s.git' "$GITFLIC_USER" "$HUB_SLUG"
    fi
}

is_local_repo() { [ -d "$HUB/.git" ] && git -C "$HUB" rev-parse --is-inside-work-tree >/dev/null 2>&1; }

# Репозиторий на GitFlic существует: парсим вывод ls-remote (GitFlic отвечает
# HTTP 500 на несуществующие/недоступные репозитории — трактуем как "нет", guide раздел 11)
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
        echo "локальный git: да"
    else
        git -C "$HUB" init -b master && echo "локальный git: инициализирован (master)"
    fi
    if ! { git -C "$HUB" config --get user.name >/dev/null && git -C "$HUB" config --get user.email >/dev/null; }; then
        echo "ВНИМАНИЕ: не настроена идентичность git (guide раздел 4)"
    fi
    if ! git -C "$HUB" remote get-url origin >/dev/null 2>&1; then
        git -C "$HUB" remote add origin "$(remote_url)" && echo "origin: добавлен ($(remote_url))"
    else
        echo "origin: $(git -C "$HUB" remote get-url origin)"
    fi
    if [ ! -f "$HUB/.gitignore" ]; then
        write_gitignore && echo ".gitignore: создан по шаблону хаба"
    else
        grep -q '^/projects/' "$HUB/.gitignore" || echo "ВНИМАНИЕ: в .gitignore нет строки /projects/ — контейнер может попасть в коммит"
        grep -q '^\.env' "$HUB/.gitignore" || echo "ВНИМАНИЕ: в .gitignore нет строки .env"
    fi
    mkdir -p "$HUB/data"
    [ -f "$HUB/data/.gitkeep" ] || touch "$HUB/data/.gitkeep"
    if remote_repo_exists; then
        echo "GitFlic: репозиторий хаба существует, remote доступен"
    else
        echo "GitFlic: репозитория хаба нет — создайте через Web-UI (guide раздел 7):"
        echo "        имя gitflicProject, пустой, без README"
    fi
}

cmd_status() {
    if ! is_local_repo; then
        echo "хаб: не git-репозиторий (выполните: gitflic-sync-hub.sh fix)"
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
    echo "хаб:            $HUB"
    echo "ветка:          $branch${up:+ -> ${up#origin/}}"
    echo "ahead/behind:   $ahead/$behind (по последнему fetch)"
    echo "грязных файлов: $dirty"
    if remote_repo_exists; then
        echo "GitFlic:        репозиторий хаба существует ($(remote_url))"
    else
        echo "GitFlic:        репозитория хаба нет ($(remote_url))"
    fi
}

cmd_sync() {
    local commit_msg="${1:-}"
    cd "$HUB" || exit 2
    if ! is_local_repo; then
        echo "ОШИБКА: $HUB не является git-репозиторием." >&2
        echo "Подсказка: gitflic-sync-hub.sh fix" >&2
        exit 2
    fi
    if ! { git config --get user.name >/dev/null && git config --get user.email >/dev/null; }; then
        echo "ОШИБКА: не настроена идентичность git (user.name / user.email)." >&2
        exit 2
    fi

    local branch upstream remote_branch remote_ref
    branch="$(git branch --show-current)"
    if [ -z "$branch" ]; then
        echo "ОШИБКА: detached HEAD — синхронизация невозможна." >&2
        exit 2
    fi
    upstream="$(git rev-parse --abbrev-ref '@{u}' 2>/dev/null || true)"
    if [ -n "$upstream" ]; then
        remote_branch="${upstream#origin/}"
    else
        remote_branch="$branch"
    fi
    remote_ref="origin/$remote_branch"

    echo "== СВЕРКА: gitflicProject (локально $branch -> GitFlic $remote_branch) =="

    if ! remote_repo_exists; then
        echo "ОШИБКА: репозиторий хаба на GitFlic отсутствует ($(remote_url))." >&2
        echo "Создайте через Web-UI (guide раздел 7): имя gitflicProject, пустой, без README." >&2
        exit 2
    fi

    if ! git fetch origin >/dev/null 2>&1; then
        echo "ОШИБКА: git fetch origin не удался (сеть/доступ). Проверьте credential helper." >&2
        exit 2
    fi

    local has_remote=0 has_local=0
    git rev-parse HEAD >/dev/null 2>&1 && has_local=1
    if git show-ref --verify --quiet "refs/remotes/$remote_ref"; then
        has_remote=1
    fi

    local local_head ahead_list behind_list remote_head
    local_head="$(git rev-parse --short HEAD 2>/dev/null || echo '(нет коммитов)')"
    ahead_list=""; behind_list=""
    if [ "$has_local" -eq 1 ]; then
        ahead_list="$(git log --oneline "${remote_ref}..HEAD" 2>/dev/null || true)"
    fi
    if [ "$has_remote" -eq 1 ] && [ "$has_local" -eq 1 ]; then
        remote_head="$(git rev-parse --short "$remote_ref")"
        behind_list="$(git log --oneline "HEAD..${remote_ref}" 2>/dev/null || true)"
        echo "Локально:  $local_head | На GitFlic ($remote_branch): $remote_head"
    else
        echo "Локально:  $local_head | На GitFlic: ветка $remote_branch отсутствует (будет создана)"
    fi

    local ahead_n=0 behind_n=0
    [ -n "$ahead_list" ] && ahead_n="$(wc -l <<<"$ahead_list")"
    [ -n "$behind_list" ] && behind_n="$(wc -l <<<"$behind_list")"
    echo "Коммитов локально, которых нет на GitFlic: $ahead_n"
    [ -n "$ahead_list" ] && echo "$ahead_list" | head -n 10 | sed 's/^/  > /'
    echo "Коммитов на GitFlic, которых нет локально:  $behind_n"
    [ -n "$behind_list" ] && echo "$behind_list" | head -n 10 | sed 's/^/  < /'

    if [ -n "$(git status --porcelain)" ]; then
        echo "-- Коммит локальных изменений --"
        git add -A

        # Защита хаба: секреты, логи, приватные данные и контейнер projects/ не коммитятся
        local forbidden
        forbidden="$(git diff --cached --name-only \
            | grep -E '^\.env($|[^.])|^\.env\.(local|prod|production)$|^data/|^projects/|\.log$|\.sqlite(-journal|-wal|-shm)?$|\.ged$' \
            | grep -vE '^data/\.gitkeep$|\.env\.example$' || true)"
        if [ -n "$forbidden" ]; then
            echo "ОШИБКА: в staging попали запрещённые пути — коммит отменён:" >&2
            echo "$forbidden" | sed 's/^/  ! /' >&2
            git reset -q
            exit 5
        fi

        local msg="$commit_msg"
        [ -z "$msg" ] && msg="sync: gitflicProject $(date +%Y-%m-%d_%H:%M) — локальные изменения"
        git commit -q -m "$msg"
        echo "Создан коммит: $(git log --oneline -1)"
    else
        echo "-- Рабочий каталог чист, некоммиченных изменений нет --"
        if [ "$has_local" -eq 0 ]; then
            echo "ОШИБКА: нет ни коммитов, ни изменений — публиковать нечего." >&2
            exit 2
        fi
    fi

    if [ "$has_remote" -eq 1 ] && [ "$behind_n" -gt 0 ]; then
        echo "-- Интеграция $remote_ref (конфликты — в пользу локальной версии) --"
        if ! git merge -X ours "$remote_ref" \
            -m "merge: интеграция $remote_ref, приоритет локальных изменений"; then
            echo "ОШИБКА: слияние остановилось (конфликт вне содержимого). Разрешите вручную:" >&2
            echo "  git status / git add ... / git commit, затем повторите синхронизацию." >&2
            exit 3
        fi
    fi

    echo "-- Публикация на GitFlic: $branch -> $remote_branch --"
    if [ "$has_remote" -eq 1 ]; then
        git push origin "$branch:$remote_branch" || exit 4
    else
        git push -u origin "$branch:$remote_branch" || exit 4
    fi

    local local_now remote_now
    local_now="$(git rev-parse HEAD)"
    remote_now="$(git ls-remote origin "refs/heads/$remote_branch" | awk '{print $1}')"
    if [ -n "$remote_now" ] && [ "$local_now" = "$remote_now" ]; then
        echo "УСПЕХ: gitflicProject/$branch -> $remote_branch синхронизирован, хэш ${local_now:0:7} совпадает с GitFlic."
        exit 0
    fi
    echo "ВНИМАНИЕ: хэши не совпали (локально ${local_now:0:7}, на GitFlic ${remote_now:0:7})." >&2
    echo "Повторите синхронизацию или проверьте вывод push выше." >&2
    exit 4
}

# ---------- Разбор аргументов ----------
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