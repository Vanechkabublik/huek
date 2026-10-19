<?php
declare(strict_types=1);

/**
 * Эндпоинт админ-панели (все действия — только для role = admin).
 *   GET  ?action=stats
 *   GET  ?action=users          q (email), page
 *   POST ?action=user_block     id            POST ?action=user_unblock  id
 *   POST ?action=user_password  id, password  POST ?action=user_delete   id
 *   GET  ?action=ads            status, user, q, page
 *   POST ?action=ad_moderate    id, status (approved|rejected|blocked), reason
 *   GET  ?action=categories
 *   POST ?action=category_create {name, icon}  category_update {id, name, icon}  category_delete {id}
 */

require __DIR__ . '/../../src/bootstrap.php';

use App\Admin;
use App\Auth;
use App\Category;
use App\HttpException;
use App\Request;
use App\Response;

Response::handleExceptions();
$admin = Auth::requireAdmin(json: true);

switch (Request::action('stats')) {
    case 'stats':
        Response::ok(['stats' => Admin::stats()]);

    // ----- пользователи
    case 'users':
        Response::ok(Admin::users(Request::str('q'), max(1, Request::int('page', 1))));

    case 'user_block':
    case 'user_unblock':
        Request::requirePost();
        Admin::setBlocked(Request::int('id'), Request::action() === 'user_block', $admin);
        Response::ok();

    case 'user_password':
        Request::requirePost();
        Admin::changePassword(Request::int('id'), (string) (Request::input()['password'] ?? ''), $admin);
        Response::ok(['message' => 'Пароль изменён']);

    case 'user_delete':
        Request::requirePost();
        Admin::deleteUser(Request::int('id'), $admin);
        Response::ok();

    // ----- объявления
    case 'ads':
        Response::ok(Admin::ads(
            Request::str('status'),
            Request::int('user'),
            Request::str('q'),
            max(1, Request::int('page', 1))
        ));

    case 'ad_moderate':
        Request::requirePost();
        Admin::moderate(Request::int('id'), Request::str('status'), Request::str('reason'));
        Response::ok();

    // ----- категории
    case 'categories':
        Response::ok(['items' => Category::all()]);

    case 'category_create':
        Request::requirePost();
        $id = Category::create(Request::str('name'), Request::str('icon'));
        Response::ok(['id' => $id], 201);

    case 'category_update':
        Request::requirePost();
        Category::update(Request::int('id'), Request::str('name'), Request::str('icon'));
        Response::ok();

    case 'category_delete':
        Request::requirePost();
        $orphaned = Category::delete(Request::int('id'));
        Response::ok(['orphaned_ads' => $orphaned]);

    default:
        throw new HttpException('Неизвестное действие', 404);
}
