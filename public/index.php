<?php

declare(strict_types=1);

/**
 * Docroot-лицо хаба: новый UI (карточки проектов / страница проекта).
 * Легаси-панель переехала в public/panel.php (маршрут /panel.php).
 * Для клона хаба внутри projects/ превью показывает рабочее пространство хаба:
 * роутер передаёт HUB_ROOT, корневой index.php сканирует его projects/.
 */

require dirname(__DIR__) . '/index.php';