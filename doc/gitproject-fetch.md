# gitproject-fetch.sh

Fetch хаба из GitVerse с троттлингом.

## Вызов
    bin/gitproject-fetch.sh [--force]

## Поведение
- Троттлинг 24ч по data/last-fetch.txt; --force обходит троттлинг.
- Remote gitverse создаётся сам; если origin уже смотрит на GitVerse — берётся origin;
  URL выравнивается идемпотентно.
- git fetch <remote> master --tags --force.
- После успеха пишет метку времени в data/last-fetch.txt.

## Ограничения
- exit 1 если не git-репозиторий или fetch не удался.
