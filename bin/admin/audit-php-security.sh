#!/bin/bash
# ==============================================================================
# PHP2Shell Security Audit Script
# CVE-2026-109618 / CVE-2026-109619
# ==============================================================================
# Запуск: ./bin/audit-php-security.sh
# ==============================================================================

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

PASS="${GREEN}[PASS]${NC}"
FAIL="${RED}[FAIL]${NC}"
WARN="${YELLOW}[WARN]${NC}"

echo "============================================================"
echo "  PHP2Shell Security Audit (CVE-2026-109618 / CVE-2026-109619)"
echo "============================================================"
echo ""

# ------------------------------------------------------------------------------
# 1. Версия PHP
# ------------------------------------------------------------------------------
echo "--- 1. Версия PHP ---"
PHP_VERSION=$(php -v 2>/dev/null | head -n 1 | grep -oP '\d+\.\d+\.\d+' || echo "unknown")
echo "PHP version: $PHP_VERSION"

MAJOR=$(echo $PHP_VERSION | cut -d. -f1)
MINOR=$(echo $PHP_VERSION | cut -d. -f2)
PATCH=$(echo $PHP_VERSION | cut -d. -f3)

PHP2SHELL_FIXED_81=33
PHP2SHELL_FIXED_82=25
PHP2SHELL_FIXED_83=12
PHP2SHELL_FIXED_84=0

if [ "$MAJOR" -eq 8 ] && [ "$MINOR" -eq 1 ] && [ "$PATCH" -ge $PHP2SHELL_FIXED_81 ]; then
    echo -e "$PASS PHP 8.1.x — версия патчная (>= 8.1.33)"
elif [ "$MAJOR" -eq 8 ] && [ "$MINOR" -eq 2 ] && [ "$PATCH" -ge $PHP2SHELL_FIXED_82 ]; then
    echo -e "$PASS PHP 8.2.x — версия патчная (>= 8.2.25)"
elif [ "$MAJOR" -eq 8 ] && [ "$MINOR" -eq 3 ] && [ "$PATCH" -ge $PHP2SHELL_FIXED_83 ]; then
    echo -e "$PASS PHP 8.3.x — версия патчная (>= 8.3.12)"
elif [ "$MAJOR" -eq 8 ] && [ "$MINOR" -eq 4 ]; then
    echo -e "$PASS PHP 8.4.x — версия патчная (>= 8.4.0)"
elif [ "$MAJOR" -gt 8 ]; then
    echo -e "$PASS PHP $MAJOR.x — версия новее, CVE-2026-109618/9 исправлены"
else
    echo -e "$FAIL PHP $MAJOR.$MINOR.$PATCH — НЕИСПРАВЛЕНА CVE-2026-109618/9"
    echo "     Требуется обновление до минимум:"
    echo "       PHP 8.1.33, 8.2.25, 8.3.12, или 8.4.0+"
fi
echo ""

# ------------------------------------------------------------------------------
# 2. Конфигурация php.ini
# ------------------------------------------------------------------------------
echo "--- 2. Конфигурация php.ini ---"

PHP_INI_PATH=$(php -i 2>/dev/null | grep "^Loaded Configuration File" | awk '{print $NF}' || echo "")
if [ -z "$PHP_INI_PATH" ] || [ "$PHP_INI_PATH" = "none" ]; then
    echo -e "$WARN Файл php.ini не загружен (используется конфигурация по умолчанию)"
    PHP_INI_PATH=""
else
    echo "php.ini загружен: $PHP_INI_PATH"
fi

if [ -n "$PHP_INI_PATH" ] && [ -f "$PHP_INI_PATH" ]; then
    # disable_functions
    DISABLED=$(grep -oP '^disable_functions\s*=\s*\K.*' "$PHP_INI_PATH" 2>/dev/null || echo "")
    if [ -n "$DISABLED" ]; then
        echo ""
        echo "disable_functions: $DISABLED"
        for func in exec system shell_exec passthru proc_open popen pcntl_exec; do
            if echo "$DISABLED" | grep -qi "$func"; then
                echo -e "$PASS   $func — отключена"
            else
                echo -e "$FAIL   $func — ВКЛЮЧЕНА (риск PHP2Shell)"
            fi
        done
    else
        echo -e "$WARN disable_functions не задан"
        echo "     Рекомендуется: exec,system,shell_exec,passthru,proc_open,popen,pcntl_exec"
    fi
    echo ""

    # open_basedir
    OPEN_BASEDIR=$(grep -oP '^open_basedir\s*=\s*\K.*' "$PHP_INI_PATH" 2>/dev/null || echo "")
    if [ -n "$OPEN_BASEDIR" ]; then
        echo -e "$PASS open_basedir установлен: $OPEN_BASEDIR"
    else
        echo -e "$WARN open_basedir не установлен"
        echo "     Рекомендуется: ~/gitproject/public/ (для изоляции веб-выполнения)"
    fi
    echo ""

    # allow_url_include
    ALLOW_URL_INCLUDE=$(grep -oP '^allow_url_include\s*=\s*\K.*' "$PHP_INI_PATH" 2>/dev/null || echo "")
    if [ "$ALLOW_URL_INCLUDE" = "0" ]; then
        echo -e "$PASS allow_url_include = 0"
    elif [ -z "$ALLOW_URL_INCLUDE" ]; then
        echo -e "$PASS allow_url_include = Off (по умолчанию)"
    else
        echo -e "$FAIL allow_url_include включен!"
    fi
    echo ""

    # cgi.fix_pathinfo
    CGI_FIX_PATHINFO=$(grep -oP '^cgi\.fix_pathinfo\s*=\s*\K.*' "$PHP_INI_PATH" 2>/dev/null || echo "")
    if [ "$CGI_FIX_PATHINFO" = "0" ]; then
        echo -e "$PASS cgi.fix_pathinfo = 0"
    elif [ -z "$CGI_FIX_PATHINFO" ]; then
        echo -e "$WARN cgi.fix_pathinfo не установлен (по умолчанию 1)"
        echo "     Рекомендуется: 0"
    else
        echo -e "$FAIL cgi.fix_pathinfo = $CGI_FIX_PATHINFO (риск Path-Info атак)"
    fi
else
    echo ""
    echo -e "$WARN Не удалось прочитать php.ini для детального аудита"
    echo "     Запустите команду вручную:"
    echo "       php -i | grep -E 'disable_functions|open_basedir|allow_url_include|cgi.fix_pathinfo'"
fi
echo ""

# ------------------------------------------------------------------------------
# 3. Аудит кода на shell-вызовы
# ------------------------------------------------------------------------------
echo "--- 3. Аудит кода на уязвимые паттерны shell-вызовов ---"
echo ""

HUB_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Проверяем router.php
echo "router.php:"
SHELL_FUNCTIONS=$(grep -P '(exec|system|shell_exec|passthru|popen|proc_open|pcntl_exec)\s*\(' "$HUB_ROOT/router.php" 2>/dev/null || true)
if [ -z "$SHELL_FUNCTIONS" ]; then
    echo -e "  $PASS Нет вызовов shell-функций"
else
    echo -e "  $FAIL Обнаружены вызовы shell-функций:"
    echo "  $SHELL_FUNCTIONS"
fi

PHP_SELF_CALLS=$(grep -P '\\$_SERVER.*exec|\\$_REQUEST.*system|\\$_GET.*shell_exec|\\$_POST.*passthru' "$HUB_ROOT/router.php" 2>/dev/null || true)
if [ -z "$PHP_SELF_CALLS" ]; then
    echo -e "  $PASS Нет передачи переменных \$_SERVER/\$_REQUEST в shell"
else
    echo -e "  $FAIL Переменные запроса передаются в shell!"
fi
echo ""

# Проверяем bin/*.sh
echo "bin/*.sh:"
SH_COUNT=0
SH_ISSUES=0
for script in "$HUB_ROOT/bin"/*.sh; do
    [ -f "$script" ] || continue
    SCRIPT_NAME=$(basename "$script")
    SH_COUNT=$((SH_COUNT + 1))

    DANGEROUS=$(grep -P '(exec|system|shell_exec|passthru|popen)\s*\(' "$script" 2>/dev/null || true)
    if [ -n "$DANGEROUS" ]; then
        echo -e "  $WARN $SCRIPT_NAME: содержит shell-вызовы"
        USER_INPUT=$(grep -P '\\$[0-9a-z_]+\s*\)' "$script" 2>/dev/null | head -n 3 || true)
        if [ -n "$USER_INPUT" ]; then
            echo -e "    $FAIL Обнаружены переменные в shell-вызовах (проверить ручную проверку)"
            SH_ISSUES=$((SH_ISSUES + 1))
        else
            echo -e "    $PASS Shell-вызовы используют только константы/локальные переменные"
        fi
    fi

    CONCAT_SH=$(grep -P 'sh\s+-c\s+.*\$' "$script" 2>/dev/null || true)
    if [ -n "$CONCAT_SH" ]; then
        echo -e "    $WARN sh -c с конкатенацией переменных (проверить экранирование)"
        SH_ISSUES=$((SH_ISSUES + 1))
    fi
done

if [ "$SH_ISSUES" -eq 0 ] && [ "$SH_COUNT" -gt 0 ]; then
    echo -e "  $PASS Все $SH_COUNT скриптов в bin/ безопасны"
fi
echo ""

# ------------------------------------------------------------------------------
# Итог
# ------------------------------------------------------------------------------
echo "============================================================"
echo "  ИТОГО АУДИТА"
echo "============================================================"
if [ "$SH_ISSUES" -eq 0 ]; then
    echo "  Код: Безопасно (нет уязвимых паттернов)"
else
    echo "  Код: ТРЕБУЕТСЯ РУЧНАЯ ПРОВЕРКА ($SH_ISSUES замечаний)"
fi
echo ""
echo "  Рекомендуется:"
echo "  1. Обновить PHP до патчной версии, если версия ниже"
echo "  2. Настроить php.ini (disable_functions, open_basedir)"
echo "  3. Провести аудит bin/*.sh вручную на shell-вызовы"
echo ""
