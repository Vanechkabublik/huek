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

// В uploads отдаются только картинки и видео; PHP-файлы оттуда никогда не исполняются
if (str_starts_with($path, '/uploads/') && !preg_match('#\.(jpe?g|png|gif|webp|svg|mp4|mov|webm)$#i', $path)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// Видео: встроенный сервер PHP не умеет Range-запросы, без них iPhone не воспроизводит ролик и нельзя перематывать.
// (За nginx/Caddy файлы из /uploads лучше отдавать самим прокси — см. DEPLOY.md; это запасной путь для разработки.)
if (preg_match('#^/uploads/(v_[a-f0-9]{24}\.(mp4|mov|webm))$#', $path, $m)) {
    $file = __DIR__ . '/../../public/uploads/' . $m[1];
    if (!is_file($file)) {
        http_response_code(404);
        return true;
    }
    $size = filesize($file);
    $types = ['mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm'];
    $start = 0;
    $end = $size - 1;
    header('Content-Type: ' . $types[$m[2]]);
    header('Accept-Ranges: bytes');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=2592000');
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $r) && ($r[1] !== '' || $r[2] !== '')) {
        if ($r[1] === '') {                       // последние N байт
            $start = max(0, $size - (int) $r[2]);
        } else {
            $start = (int) $r[1];
            $end = $r[2] !== '' ? min((int) $r[2], $size - 1) : $end;
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            return true;
        }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        return true;
    }
    $fh = fopen($file, 'rb');
    fseek($fh, $start);
    for ($left = $end - $start + 1; $left > 0 && !feof($fh); ) {
        $chunk = fread($fh, min(262144, $left));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $left -= strlen($chunk);
        flush();
    }
    fclose($fh);
    return true;
}

// Статика с версией (?v=mtime) может кэшироваться надолго
if (preg_match('#^/assets/#', $path)) {
    header('Cache-Control: public, max-age=604800');
}

return false;
