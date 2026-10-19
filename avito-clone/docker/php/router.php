<?php
/**
 * Роутер встроенного сервера PHP (php -S ... router.php): заменяет правила Apache.
 * Возврат false — сервер сам отдаёт файл или выполняет PHP-скрипт из public/.
 */
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

// Скрытые файлы и каталоги (.git, .env, .gitkeep) наружу не отдаём
if (preg_match('#(^|/)\.#', $path)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// В uploads отдаются только картинки; PHP-файлы оттуда никогда не исполняются
if (str_starts_with($path, '/uploads/') && !preg_match('#\.(jpe?g|png|gif|webp|svg)$#i', $path)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// Статика с версией (?v=mtime) может кэшироваться надолго
if (preg_match('#^/assets/#', $path)) {
    header('Cache-Control: public, max-age=604800');
}

return false;
