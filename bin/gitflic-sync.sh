#!/usr/bin/env bash
# gitflic-sync.sh — сверка и синхронизация проектов контейнера projects/ с GitFlic.
#
# Модель публикации: локальная ветка -> её upstream-ветка на GitFlic.
#   visual-novel-engine: qwen (локально) -> master (на GitFlic), настраивается один раз:
#   git branch --set-upstream-to=origin/master qwen
#   Без upstream публикуется одноимённая ветка с установкой -u.
#
# Вызовы:
#   gitflic-sync.sh <slug> [-m "сообщение коммита"]   — ПОЛНЫЙ ЦИКЛ (поведение v1):
#       fetch -> сверка ahead/behind с upstream -> коммит локальных изменений (защита
#       запрещённых путей) -> интеграция upstream с приоритетом локальной версии
#       (merge -X ours) -> публикация (push local:upstream) -> сверка хэшей.
#   gitflic-sync.sh status [slug] — таблица: локальный git? репозиторий на GitFlic?
#                                   ветка и её upstream? грязная копия?
#   gitflic-sync.sh fix [slug]    — git init (если нет .git), привязка origin, контроль.
#   gitflic-sync.sh probe         — матрица кандидатов API GitFlic: код + redirect_url.
#
# Приоритет — локальные изменения (merge -X ours). История не переписывается,
# push --force запрещён (guide раздел 4).
#
# Коды выхода: 0 успех; 2 ошибка аргументов/окружения; 3 конфликт слияния;
#              4 ошибка push/расхождение хэшей; 5 в staging запрещённые пути.

set -uo pipefail

WORKSPACE="$HOME/gitflicProject/projects"
ENV_FILE="$HOME/gitflicProject/.env"

GITFLIC_API="${GITFLIC_API:-https://gitflic.ru/api/v2}"
API_PATH_PROJECTS="/projects"

GITFLIC_USER=""
GITFLIC_TOKEN=""
API_CODE=""
API_REDIRECT=""
BODY_FILE="$(mktemp)"
trap 'rm -f "$BODY_FILE"' EXIT

load_env_soft() {
    GITFLIC_USER="$(sed -n 's/^GITFLIC_USER=//p' "$ENV_FILE" 2>/dev/null | tail -n 1 | tr -d '\r')"
    GITFLIC_TOKEN="$(sed -n 's/^GITFLIC_TOKEN=//p' "$ENV_FILE" 2>/dev/null | tail -n 1 | tr -d '\r')"
    GITFLIC_USER="${GITFLIC_USER:-sokol491}"
}

is_local_repo() { [ -d "$1/.git" ] && git -C "$1" rev-parse --is-inside-work-tree >/dev/null 2>&1; }

remote_repo_exists() {
    local output
    output="$(git ls-remote "https://gitflic.ru/project/${GITFLIC_USER}/$1.git" 2>&1)"
    [[ ! "$output" =~ "Repository not found" && ! "$output" =~ "fatal:" && ! "$output" =~ "error:" ]]
}

api_call_url() {
    local method="$1" url="$2" json="${3:-}" out
    local args=(-s -o "$BODY_FILE" -w '%{http_code} %{redirect_url}'
                -H "Authorization: Bearer ${GITFLIC_TOKEN}")
    if [ -n "$json" ]; then
        args+=(-H 'Content-Type: application/json' -X "$method" --data "$json")
    else
        args+=(-X "$method")
    fi
    out="$(curl "${args[@]}" "$url")"
    API_CODE="${out%% *}"
    API_REDIRECT="${out#* }"
}

cmd_status() {
    local filter="${1:-}" dir slug local_s remote_s branch dirty up
    printf '%-22s %-7s %-7s %-22s %s\n' PROJECT LOCAL REMOTE BRANCH DIRTY
    for dir in "$WORKSPACE"/*/; do
        [ -d "$dir" ] || continue
        slug="$(basename "$dir")"
        { [ -z "$filter" ] || [ "$slug" = "$filter" ]; } || continue
        local_s="нет"; remote_s="нет"; branch="-"; dirty="-"
        if is_local_repo "$dir"; then
            local_s="да"
            branch="$(git -C "$dir" branch --show-current 2>/dev/null)"
            [ -n "$branch" ] || branch="(без коммитов)"
            up="$(git -C "$dir" rev-parse --abbrev-ref '@{u}' 2>/dev/null || true)"
            [ -n "$up" ] && branch="$branch -> ${up#origin/}"
            dirty="$(git -C "$dir" status --porcelain 2>/dev/null | wc -l)"
        fi
        remote_repo_exists "$slug" && remote_s="да"
        printf '%-22s %-7s %-7s %-22s %s\n' "$slug" "$local_s" "$remote_s" "$branch" "$dirty"
    done
}

cmd_fix() {
    local filter="${1:-}" dir slug
    for dir in "$WORKSPACE"/*/; do
        [ -d "$dir" ] || continue
        slug="$(basename "$dir")"
        { [ -z "$filter" ] || [ "$slug" = "$filter" ]; } || continue
        echo "== $slug"
        if is_local_repo "$dir"; then
            echo "   локальный git: да"
        else
            git -C "$dir" init -b main && echo "   локальный git: инициализирован (main)"
        fi
        if ! git -C "$dir" remote get-url origin >/dev/null 2>&1; then
            git -C "$dir" remote add origin "https://gitflic.ru/project/${GITFLIC_USER}/${slug}.git"
            echo "   origin: добавлен"
        fi
        if remote_repo_exists "$slug"; then
            echo "   GitFlic: репозиторий существует, remote доступен"
        else
            echo "   GitFlic: репозитория нет — создайте через UI (guide раздел 7)"
        fi
    done
}

cmd_probe() {
    local base path
    for base in "https://gitflic.ru/api/v2" "https://gitflic.ru/api/v1" "https://gitflic.ru/api"; do
        for path in "/user" "/projects" "/projects/${GITFLIC_USER}/archive" "/user/projects"; do
            api_call_url GET "${base}${path}"
            printf 'GET  %-58s -> %s %s\n' "${base}${path}" "$API_CODE" "$API_REDIRECT"
        done
        api_call_url POST "${base}/projects" '{"name":"archive"}'
        printf 'POST %-57s -> %s %s\n' "${base}/projects name=archive" "$API_CODE" "$API_REDIRECT"
    done
}

# ---------- Полный цикл синхронизации и публикации ----------
cmd_sync() {
    local slug="$1" commit_msg="${2:-}"
    local repo="$WORKSPACE/$slug"

    if [ ! -d "$repo/.git" ]; then
        echo "ОШИБКА: каталог $repo не существует или не является git-репозиторием." >&2
        echo "Подсказка: gitflic-sync.sh fix $slug" >&2
        exit 2
    fi
    cd "$repo" || exit 2

    local branch
    branch="$(git branch --show-current)"
    if [ -z "$branch" ]; then
        echo "ОШИБКА: detached HEAD — синхронизация невозможна." >&2
        exit 2
    fi

    if ! { git config --get user.name >/dev/null && git config --get user.email >/dev/null; }; then
        echo "ОШИБКА: не настроена идентичность git (user.name / user.email)." >&2
        exit 2
    fi

    # Целевая ветка на GitFlic: upstream локальной ветки, иначе одноимённая
    local upstream remote_branch
    upstream="$(git rev-parse --abbrev-ref '@{u}' 2>/dev/null || true)"
    if [ -n "$upstream" ]; then
        remote_branch="${upstream#origin/}"
    else
        remote_branch="$branch"
    fi
    local remote_ref="origin/$remote_branch"

    echo "== СВЕРКА: $slug (локально $branch -> GitFlic $remote_branch) =="

    if ! remote_repo_exists "$slug"; then
        echo "ОШИБКА: репозиторий $slug на GitFlic отсутствует." >&2
        echo "Создайте репозиторий через UI (guide раздел 7) и повторите синхронизацию." >&2
        exit 2
    fi

    if ! git fetch origin >/dev/null 2>&1; then
        echo "ОШИБКА: git fetch origin не удался (сеть/доступ). Проверьте credential helper." >&2
        exit 2
    fi

    local has_remote=0
    if git show-ref --verify --quiet "refs/remotes/$remote_ref"; then
        has_remote=1
    fi

    local local_head ahead_list behind_list remote_head
    local_head="$(git rev-parse --short HEAD)"
    ahead_list="$(git log --oneline "${remote_ref}..HEAD" 2>/dev/null || true)"
    behind_list=""
    if [ "$has_remote" -eq 1 ]; then
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

        # Защита: секреты и приватные данные не должны попасть в коммит (STANDARD.md разд. 3, 9)
        # ИСПРАВЛЕНО: запрещены только КОРНЕВЫЕ data/, storage/, vendor/, а не public/data/
        local forbidden
        forbidden="$(git diff --cached --name-only \
            | grep -E '^data/|^storage/|^vendor/|\.sqlite(-journal|-wal|-shm)?$|\.log$|\.ged$|^\.env($|[^.])|^\.env\.(local|prod|production)$' \
            | grep -vE '\.gitkeep$|\.env\.example$' || true)"
        if [ -n "$forbidden" ]; then
            echo "ОШИБКА: в staging попали запрещённые пути — коммит отменён:" >&2
            echo "$forbidden" | sed 's/^/  ! /' >&2
            git reset -q
            exit 5
        fi

        local msg="$commit_msg"
        [ -z "$msg" ] && msg="sync: $slug $(date +%Y-%m-%d_%H:%M) — локальные изменения"
        git commit -q -m "$msg"
        echo "Создан коммит: $(git log --oneline -1)"
    else
        echo "-- Рабочий каталог чист, некоммиченных изменений нет --"
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
        echo "УСПЕХ: $slug/$branch -> $remote_branch синхронизирован, хэш ${local_now:0:7} совпадает с GitFlic."
        exit 0
    fi
    echo "ВНИМАНИЕ: хэши не совпали (локально ${local_now:0:7}, на GitFlic ${remote_now:0:7})." >&2
    echo "Повторите синхронизацию или проверьте вывод push выше." >&2
    exit 4
}

MODE="${1:-}"
if [ -z "$MODE" ]; then
    echo "Использование: gitflic-sync.sh <slug> [-m \"сообщение\"] | status [slug] | fix [slug] | probe" >&2
    echo "Пример: gitflic-sync.sh visual-novel-engine" >&2
    exit 2
fi

load_env_soft

case "$MODE" in
    status) shift; cmd_status "${1:-}"; exit 0 ;;
    fix)    shift; cmd_fix "${1:-}";    exit 0 ;;
    probe)  shift; cmd_probe;           exit 0 ;;
esac

SLUG="$MODE"
shift
COMMIT_MSG=""
if [ "${1:-}" = "-m" ]; then
    COMMIT_MSG="${2:-}"
fi
cmd_sync "$SLUG" "$COMMIT_MSG"