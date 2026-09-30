#!/usr/bin/env bash
# gitflic-sync-hub.sh — синхронизация и публикация хаба gitflicProject
set -euo pipefail

REPO_DIR="${HOME}/gitflicProject"
cd "$REPO_DIR"

# Цвета
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

usage() {
    echo "Использование: $0 [-m 'сообщение'] | status | fix"
    exit 1
}

check_staging() {
    # Проверка staging на запрещённые пути
    # Запрещено: .env, data/ (кроме .gitkeep), projects/ (кроме .gitkeep), логи, SQLite
    local forbidden=()
    
    while IFS= read -r file; do
        # Пропускаем удалённые файлы (они начинаются с D)
        [[ "$file" =~ ^D\  ]] && continue
        
        local path="${file:2}"
        
        # .env на любой глубине
        if [[ "$path" == ".env" || "$path" == *"/.env" || "$path" == *".env."* ]]; then
            forbidden+=("$path")
        fi
        
        # data/ (кроме data/.gitkeep)
        if [[ "$path" == "data/"* && "$path" != "data/.gitkeep" ]]; then
            forbidden+=("$path")
        fi
        
        # projects/ (кроме projects/.gitkeep)
        if [[ "$path" == "projects/"* && "$path" != "projects/.gitkeep" ]]; then
            forbidden+=("$path")
        fi
        
        # Логи и SQLite на любой глубине
        if [[ "$path" == *.log || "$path" == *.sqlite || "$path" == *.sqlite-journal || 
              "$path" == *.sqlite-wal || "$path" == *.sqlite-shm || "$path" == *.bak-* ]]; then
            forbidden+=("$path")
        fi
    done < <(git status --short)
    
    if [ ${#forbidden[@]} -gt 0 ]; then
        echo -e "${RED}ОШИБКА: в staging попали запрещённые пути — коммит отменён:${NC}"
        for f in "${forbidden[@]}"; do
            echo "  ! $f"
        done
        exit 5
    fi
}

sync() {
    local message="${1:-sync: автоматическая публикация хаба}"
    
    echo -e "${YELLOW}== СВЕРКА: gitflicProject (локально master -> GitFlic master) ==${NC}"
    
    # Получаем хеши
    local local_hash
    local_hash=$(git rev-parse HEAD)
    
    local remote_hash
    remote_hash=$(git ls-remote origin master | awk '{print $1}')
    
    echo "Локально:  ${local_hash:0:7}"
    echo "На GitFlic (master): ${remote_hash:0:7}"
    
    # Считаем коммиты
    local commits_ahead
    commits_ahead=$(git rev-list --count origin/master..HEAD 2>/dev/null || echo "0")
    
    local commits_behind
    commits_behind=$(git rev-list --count HEAD..origin/master 2>/dev/null || echo "0")
    
    echo "Коммитов локально, которых нет на GitFlic: $commits_ahead"
    echo "Коммитов на GitFlic, которых нет локально: $commits_behind"
    
    # Если есть локальные изменения — коммитим
    if [ -n "$(git status --short)" ]; then
        echo -e "\n${YELLOW}-- Коммит локальных изменений --${NC}"
        check_staging
        
        git add -A
        git commit -m "$message"
        echo -e "${GREEN}✓ Коммит создан${NC}"
    else
        echo -e "\n${GREEN}✓ Локальных изменений нет${NC}"
    fi
    
    # Если есть отставание — pull
    if [ "$commits_behind" -gt 0 ]; then
        echo -e "\n${YELLOW}-- Pull с GitFlic --${NC}"
        git pull origin master
        echo -e "${GREEN}✓ Pull завершён${NC}"
    fi
    
    # Push
    if [ -n "$(git log origin/master..HEAD --oneline 2>/dev/null)" ]; then
        echo -e "\n${YELLOW}-- Push на GitFlic --${NC}"
        git push origin master
        echo -e "${GREEN}✓ Push завершён${NC}"
    else
        echo -e "\n${GREEN}✓ Push не требуется${NC}"
    fi
    
    echo -e "\n${GREEN}== Синхронизация завершена ==${NC}"
}

status() {
    echo -e "${YELLOW}== Статус хаба gitflicProject ==${NC}"
    git status --short
    echo ""
    git log --oneline -5
}

fix() {
    echo -e "${YELLOW}== Подготовка к первой публикации ==${NC}"
    
    # Создаём структуру
    mkdir -p data projects assets bin
    touch data/.gitkeep projects/.gitkeep assets/.gitkeep
    
    # Создаём .gitignore если нет
    if [ ! -f .gitignore ]; then
        cat > .gitignore <<'GITIGNORE'
# Workspace VSCode
*.code-workspace
# Секреты
.env
# Логи и приватные данные хаба
!/data/.gitkeep
/data/*
# Assets
!/assets/.gitkeep
# Контейнер репозиториев других проектов
!/projects/.gitkeep
/projects/*
# Логи и служебные файлы
*.log
*.sqlite
*.sqlite-journal
*.sqlite-wal
*.sqlite-shm
*.bak-*
GITIGNORE
        echo "✓ Создан .gitignore"
    fi
    
    # Настраиваем origin
    if ! git remote get-url origin &>/dev/null; then
        git remote add origin https://gitflic.ru/project/sokol491/gitflicproject.git
        echo "✓ Добавлен origin"
    fi
    
    # Инициализация если нужно
    if [ ! -d .git ]; then
        git init
        git branch -M master
        echo "✓ Инициализирован git"
    fi
    
    echo -e "\n${GREEN}== Подготовка завершена. Запустите sync для публикации ==${NC}"
}

# Парсинг аргументов
if [ $# -eq 0 ]; then
    sync
elif [ "$1" = "status" ]; then
    status
elif [ "$1" = "fix" ]; then
    fix
elif [ "$1" = "-m" ]; then
    [ -z "${2:-}" ] && usage
    sync "$2"
else
    usage
fi
