<?php
declare(strict_types=1);

/**
 * Эндпоинт загрузки фото.
 *   POST ?action=upload  multipart: file         → {filename, url, thumb}
 *   POST ?action=video   multipart: file         → {filename, url}   (видеообзор: MP4/MOV/WebM до 50 МБ)
 *   POST ?action=delete  filename                → удаляет ещё не привязанное фото или видео (из превью)
 */

require __DIR__ . '/../../src/bootstrap.php';

use App\Auth;
use App\HttpException;
use App\Request;
use App\Response;
use App\Upload;

Response::handleExceptions();
Request::requirePost();
Auth::requireLogin(json: true);

switch (Request::action('upload')) {
    case 'upload':
        Response::ok(Upload::store($_FILES['file'] ?? []), 201);

    case 'video':
        Response::ok(Upload::storeVideo($_FILES['file'] ?? []), 201);

    case 'delete':
        $filename = Request::str('filename');
        // Удалять можно только то, что пользователь загрузил в этой сессии и не привязал к объявлению
        if ((Upload::isManaged($filename) || Upload::isVideoName($filename)) && Upload::isPending($filename)) {
            Upload::remove($filename);
        }
        Response::ok();

    default:
        throw new HttpException('Неизвестное действие', 404);
}
