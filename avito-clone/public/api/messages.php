<?php
declare(strict_types=1);

/**
 * Эндпоинт сообщений.
 *   POST ?action=send   ad_id, body, to_user_id? (для ответа владельца)
 *   GET  ?action=inbox  box=in|sent
 */

require __DIR__ . '/../../src/bootstrap.php';

use App\Auth;
use App\HttpException;
use App\Message;
use App\Request;
use App\Response;

Response::handleExceptions();

switch (Request::action('inbox')) {
    case 'send':
        Request::requirePost();
        $user = Auth::requireLogin(json: true);
        $toUser = Request::int('to_user_id');
        Message::send(Request::int('ad_id'), $user, Request::str('body'), $toUser > 0 ? $toUser : null);
        Response::ok(['message' => 'Сообщение отправлено'], 201);

    case 'inbox':
        $user = Auth::requireLogin(json: true);
        Response::ok(['items' => Message::box((int) $user['id'], Request::str('box', 'in'))]);

    default:
        throw new HttpException('Неизвестное действие', 404);
}
