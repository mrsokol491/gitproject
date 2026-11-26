# audit-php-security.sh

Аудит безопасности PHP: CVE-2026-109618 / CVE-2026-109619 (PHP2Shell) и конфигурация php.ini.

## Вызов
    bin/audit-php-security.sh
Аргументов и флагов нет.

## Что проверяет
1. Версия PHP против исправленных: 8.1.33+, 8.2.25+, 8.3.12+, 8.4.0+ (или новее 8.x).
2. php.ini (если загружен):
   - disable_functions: exec, system, shell_exec, passthru, proc_open, popen, pcntl_exec;
   - open_basedir установлен;
   - allow_url_include = Off/0;
   - cgi.fix_pathinfo = 0.

## Вывод
Маркеры [PASS] / [FAIL] / [WARN] по каждому пункту; при FAIL — минимальные требуемые версии.
Если php.ini не загружен — WARN и команда для ручной проверки.

## Ограничения
- Только чтение конфигурации; ничего не меняет.
- Требует php в PATH.
