<?php
declare(strict_types=1);

/**
 * Эндпоинт объявлений (файл назван listings.php, а не ads.php: блокировщики рекламы режут URL со словом «ads»).
 *   GET  ?action=list    q, category, a_<код>..., city, price_min, price_max, sort, user, exclude, page   — публичная выдача
 *   GET  ?action=meta                                                                       — категории и города для фильтров
 *   GET  ?action=mine    status                                                             — мои объявления (нужен вход)
 *   GET  ?action=stats   id                                                                 — статистика объявления (автор/админ)
 *   POST ?action=phone   id                                                                 — телефон продавца
 *   POST ?action=create  title, category_id, price, city, description, images[]
 *   POST ?action=update  id + те же поля
 *   POST ?action=delete  id
 */

require __DIR__ . '/../../src/bootstrap.php';

use App\Ad;
use App\Attribute;
use App\Auth;
use App\Category;
use App\HttpException;
use App\Request;
use App\Response;
use App\Stats;

Response::handleExceptions();

switch (Request::action('list')) {
    case 'list':
        // a_<код>, a_<код>_min, a_<код>_max — фильтры по характеристикам категории
        $attrFilters = array_filter(
            Request::input(),
            static fn($k) => is_string($k) && str_starts_with($k, 'a_'),
            ARRAY_FILTER_USE_KEY
        );
        Response::ok(Ad::search([
            'q'         => Request::str('q'),
            'category'  => Request::int('category'),
            'city'      => Request::str('city'),
            'price_min' => Request::str('price_min'),
            'price_max' => Request::str('price_max'),
            'sort'      => Request::str('sort', 'date'),
            'user'      => Request::int('user'),
            'exclude'   => Request::int('exclude'),
        ] + $attrFilters, max(1, Request::int('page', 1)), Auth::id()));

    case 'meta':
        $attrs = Attribute::all();
        $cats = array_map(static fn(array $c) => $c + ['attributes' => $attrs[$c['id']] ?? []], Category::all());
        Response::ok(['categories' => $cats, 'cities' => Ad::cities()]);

    case 'mine':
        $user = Auth::requireLogin(json: true);
        Response::ok(Ad::byUser((int) $user['id'], Request::str('status')));

    case 'phone':
        Request::requirePost();
        $phone = Ad::sellerPhone(Request::int('id'));
        Stats::trackPhone(Request::int('id'), Auth::user());
        Response::ok(['phone' => $phone, 'tel' => $phone ? preg_replace('/[^\d+]/', '', $phone) : null]);

    case 'stats':
        // Статистику видят автор объявления и администратор
        $user = Auth::requireLogin(json: true);
        $adId = Request::int('id');
        $owner = App\Database::value('SELECT user_id FROM ads WHERE id = ?', [$adId]);
        if ($owner === null) {
            throw new HttpException('Объявление не найдено', 404);
        }
        if ((int) $owner !== (int) $user['id'] && $user['role'] !== 'admin') {
            throw new HttpException('Недостаточно прав', 403);
        }
        Response::ok(Stats::forAd($adId));

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
