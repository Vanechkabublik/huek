<?php
declare(strict_types=1);

/**
 * Эндпоинт объявлений.
 *   GET  ?action=list    q, category, city, price_min, price_max, sort, user, exclude, page   — публичная выдача
 *   GET  ?action=meta                                                                       — категории и города для фильтров
 *   GET  ?action=mine    status                                                             — мои объявления (нужен вход)
 *   POST ?action=phone   id                                                                 — телефон продавца
 *   POST ?action=create  title, category_id, price, city, description, images[]
 *   POST ?action=update  id + те же поля
 *   POST ?action=delete  id
 */

require __DIR__ . '/../../src/bootstrap.php';

use App\Ad;
use App\Auth;
use App\Category;
use App\HttpException;
use App\Request;
use App\Response;

Response::handleExceptions();

switch (Request::action('list')) {
    case 'list':
        Response::ok(Ad::search([
            'q'         => Request::str('q'),
            'category'  => Request::int('category'),
            'city'      => Request::str('city'),
            'price_min' => Request::str('price_min'),
            'price_max' => Request::str('price_max'),
            'sort'      => Request::str('sort', 'date'),
            'user'      => Request::int('user'),
            'exclude'   => Request::int('exclude'),
        ], max(1, Request::int('page', 1))));

    case 'meta':
        Response::ok(['categories' => Category::all(), 'cities' => Ad::cities()]);

    case 'mine':
        $user = Auth::requireLogin(json: true);
        Response::ok(Ad::byUser((int) $user['id'], Request::str('status')));

    case 'phone':
        Request::requirePost();
        $phone = Ad::sellerPhone(Request::int('id'));
        Response::ok(['phone' => $phone, 'tel' => $phone ? preg_replace('/[^\d+]/', '', $phone) : null]);

    case 'create':
        Request::requirePost();
        $user = Auth::requireLogin(json: true);
        $id = Ad::create((int) $user['id'], Request::input());
        Response::ok(['id' => $id, 'status' => 'pending', 'message' => 'Объявление отправлено на модерацию'], 201);

    case 'update':
        Request::requirePost();
        $user = Auth::requireLogin(json: true);
        Ad::update(Request::int('id'), (int) $user['id'], Request::input());
        Response::ok(['id' => Request::int('id'), 'status' => 'pending', 'message' => 'Изменения отправлены на повторную модерацию']);

    case 'delete':
        Request::requirePost();
        $user = Auth::requireLogin(json: true);
        Ad::delete(Request::int('id'), $user);
        Response::ok();

    default:
        throw new HttpException('Неизвестное действие', 404);
}
