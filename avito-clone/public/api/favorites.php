<?php
declare(strict_types=1);

/**
 * Эндпоинт избранного.
 *   POST ?action=toggle  ad_id   — добавить/убрать → {is_favorite, count}
 *   GET  ?action=list    page    — мои избранные объявления
 */

require __DIR__ . '/../../src/bootstrap.php';

use App\Auth;
use App\Favorite;
use App\HttpException;
use App\Request;
use App\Response;

Response::handleExceptions();

switch (Request::action('list')) {
    case 'toggle':
        Request::requirePost();
        $user = Auth::requireLogin(json: true);
        Response::ok(Favorite::toggle((int) $user['id'], Request::int('ad_id')));

    case 'list':
        $user = Auth::requireLogin(json: true);
        Response::ok(Favorite::listFor((int) $user['id'], max(1, Request::int('page', 1))));

    default:
        throw new HttpException('Неизвестное действие', 404);
}
