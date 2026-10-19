<?php
declare(strict_types=1);

/**
 * Эндпоинт отзывов о продавцах.
 *   GET  ?action=list    seller, page  — отзывы + сводка рейтинга
 *   GET  ?action=can     seller        — может ли текущий пользователь оставить отзыв (+ его прежний отзыв)
 *   POST ?action=save    seller_id, rating, body
 *   POST ?action=delete  id
 */

require __DIR__ . '/../../src/bootstrap.php';

use App\Auth;
use App\HttpException;
use App\Request;
use App\Response;
use App\Review;
use App\User;

Response::handleExceptions();

switch (Request::action('list')) {
    case 'list':
        $seller = Request::int('seller');
        if (User::find($seller) === null) {
            throw new HttpException('Продавец не найден', 404);
        }
        Response::ok(Review::listFor($seller, max(1, Request::int('page', 1))) + ['summary' => Review::summary($seller)]);

    case 'can':
        Response::ok(Review::eligibility(Auth::id(), Request::int('seller')));

    case 'save':
        Request::requirePost();
        $me = Auth::requireLogin(json: true);
        Review::save((int) $me['id'], Request::int('seller_id'), Request::int('rating'), Request::str('body'));
        Response::ok(['message' => 'Спасибо за отзыв!', 'summary' => Review::summary(Request::int('seller_id'))]);

    case 'delete':
        Request::requirePost();
        $me = Auth::requireLogin(json: true);
        $r = Review::delete(Request::int('id'), $me);
        Response::ok(['summary' => Review::summary((int) $r['seller_id'])]);

    default:
        throw new HttpException('Неизвестное действие', 404);
}
