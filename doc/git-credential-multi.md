# git-credential-multi.sh

Credential helper нескольких Git-хостов для git (credential.helper).

## Вызов
    git-credential-multi.sh get
Другие операции (store/erase) молча игнорируются.

## Вход (stdin, протокол git)
    protocol=https
    host=<домен>
    path=<owner>/<repo>.git

## Логика
- case по домену: gitverse.ru, gitflic.ru, git.sourcecraft.dev, hub.mos.ru,
  altlinux.space, github.com, gitlab.com, codeberg.org, gitea.com → префикс .env.
- SCOPE=HUB, если path содержит gitproject (репозиторий хаба на любом хосте), иначе PROJECT.
- Читает <PREFIX>_<SCOPE>_USER/_TOKEN; при пустом/placeholder PROJECT — fallback на HUB.
- placeholder трактуется как отсутствие токена.
- Вывод: username=… / password=… ; при пустом токене — подсказка в stderr и exit 0.

## Ограничения
- .env читается по $HOME/gitproject/.env. На устройстве с другим путём создайте симлинк:
  ln -s ~/ИмяВашейПапки/gitproject ~/gitproject
- Токены не логируются.
