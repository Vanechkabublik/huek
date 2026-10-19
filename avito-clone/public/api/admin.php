<?php
declare(strict_types=1);

/**
 * Эндпоинт админ-панели (все действия — только для role = admin).
 *
 *  GET  dashboard | stats
 *  GET  users (q, filter, page) · user_detail (id)
 *  POST user_ban (id, reason, duration, ban_ip) · user_unban (id, lift_ip) · user_password (id, password) · user_delete · user_role (id, role)
 *  GET  ip_bans · security (event, ip, user, page) · legal · POST ip_ban_add (ip, reason, scope, duration) · ip_ban_delete (id)
 *       legal_save (slug, title, body) · settings_save (site_name, operator, contact_email)
 *  GET  ads (status, user, q, category, sort, page) · ad_detail (id)
 *  POST ad_moderate (id, status, reason) · ad_delete (id) · ads_bulk (ids[], action, reason)
 *  GET  categories · attributes (category_id)
 *  POST category_create · category_update · category_delete · attribute_save · attribute_delete
 *  GET  reviews (page) · audit (filter, page)
 *  POST review_delete (id)
 *  GET  export_users · export_ads (status, user, q, category) — CSV
 */

require __DIR__ . '/../../src/bootstrap.php';

use App\Admin;
use App\Attribute;
use App\Audit;
use App\Auth;
use App\Ban;
use App\Category;
use App\Client;
use App\HttpException;
use App\Legal;
use App\Request;
use App\Response;
use App\Security;
use App\Settings;
use App\Review;

Response::handleExceptions();
$admin = Auth::requireAdmin(json: true);

/** Отдаёт CSV (разделитель «;» и BOM — чтобы Excel открыл кириллицу без «крякозябр»). */
function csvOut(string $filename, array $header, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    // защита от CSV-инъекций: ячейки, начинающиеся с = + - @, экранируем апострофом
    $safe = static fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    fputcsv($out, array_map($safe, $header), ';', '"', '');
    foreach ($rows as $r) {
        fputcsv($out, array_map($safe, $r), ';', '"', '');
    }
    exit;
}

switch (Request::action('dashboard')) {
    case 'dashboard':
        Response::ok(Admin::dashboard());

    case 'stats':
        Response::ok(['stats' => Admin::stats()]);

    case 'integrations':
        Response::ok(Admin::integrations());

    // ----- пользователи
    case 'users':
        Response::ok(Admin::users(Request::str('q'), Request::str('filter', 'all'), max(1, Request::int('page', 1))));

    case 'user_detail':
        Response::ok(Admin::userDetail(Request::int('id')));

    case 'user_ban':
    case 'user_block':
        Request::requirePost();
        $ips = Admin::banUser(
            Request::int('id'),
            Request::str('reason'),
            Request::str('duration', 'forever'),
            !empty(Request::input()['ban_ip']),
            $admin
        );
        Response::ok(['banned_ips' => $ips]);

    case 'user_unban':
    case 'user_unblock':
        Request::requirePost();
        Admin::unbanUser(Request::int('id'), !empty(Request::input()['lift_ip']), $admin);
        Response::ok();

    // ----- безопасность: баны по IP, журнал входов
    case 'ip_bans':
        Response::ok(['items' => Ban::listIps(), 'my_ip' => Client::ip()]);

    case 'ip_ban_add':
        Request::requirePost();
        Admin::addIpBan(Request::str('ip'), Request::str('reason'), Request::str('scope', 'auth'), Request::str('duration', 'forever'), $admin);
        Response::ok();

    case 'ip_ban_delete':
        Request::requirePost();
        Admin::removeIpBan(Request::int('id'), $admin);
        Response::ok();

    case 'security':
        $log = Security::list(Request::str('event'), Request::str('ip'), Request::int('user'), max(1, Request::int('page', 1)));
        Response::ok($log + (Request::int('page', 1) <= 1 ? ['summary' => Security::summary()] : []));

    // ----- документы и настройки
    case 'legal':
        Response::ok(['documents' => array_values(Legal::all()), 'settings' => Settings::all(), 'consent_version' => Legal::consentVersion()]);

    case 'legal_save':
        Request::requirePost();
        Legal::save(Request::str('slug'), Request::str('title'), (string) (Request::input()['body'] ?? ''), $admin);
        Response::ok(['documents' => array_values(Legal::all()), 'consent_version' => Legal::consentVersion()]);

    case 'settings_save':
        Request::requirePost();
        Settings::save(Request::input());
        Audit::log($admin, 'settings.save', null, null, []);
        Response::ok(['settings' => Settings::all()]);

    case 'user_password':
        Request::requirePost();
        Admin::changePassword(Request::int('id'), (string) (Request::input()['password'] ?? ''), $admin);
        Response::ok(['message' => 'Пароль изменён']);

    case 'user_role':
        Request::requirePost();
        Admin::setRole(Request::int('id'), Request::str('role'), $admin);
        Response::ok();

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
            max(1, Request::int('page', 1)),
            Request::int('category'),
            Request::str('sort', 'date_desc')
        ));

    case 'ad_detail':
        Response::ok(Admin::adDetail(Request::int('id')));

    case 'ad_moderate':
        Request::requirePost();
        Admin::moderate(Request::int('id'), Request::str('status'), Request::str('reason'), $admin);
        Response::ok();

    case 'ad_delete':
        Request::requirePost();
        Admin::deleteAd(Request::int('id'), $admin);
        Response::ok();

    case 'ads_bulk':
        Request::requirePost();
        $ids = Request::input()['ids'] ?? [];
        Response::ok(Admin::bulk(is_array($ids) ? $ids : [], Request::str('action_type'), Request::str('reason'), $admin));

    // ----- категории и их характеристики
    case 'categories':
        Response::ok(['items' => Category::all()]);

    case 'category_create':
        Request::requirePost();
        $id = Category::create(Request::str('name'), Request::str('icon'));
        Audit::log($admin, 'category.create', 'category', $id, ['name' => Request::str('name')]);
        Response::ok(['id' => $id], 201);

    case 'category_update':
        Request::requirePost();
        Category::update(Request::int('id'), Request::str('name'), Request::str('icon'));
        Audit::log($admin, 'category.update', 'category', Request::int('id'), ['name' => Request::str('name')]);
        Response::ok();

    case 'category_delete':
        Request::requirePost();
        $cat = Category::find(Request::int('id'));
        $orphaned = Category::delete(Request::int('id'));
        Audit::log($admin, 'category.delete', 'category', Request::int('id'), ['name' => $cat['name'] ?? '', 'orphaned_ads' => $orphaned]);
        Response::ok(['orphaned_ads' => $orphaned]);

    case 'attributes':
        Attribute::resetCache();
        Response::ok(['items' => Attribute::forCategory(Request::int('category_id'))]);

    case 'attribute_save':
        Request::requirePost();
        $in = Request::input();
        $id = Attribute::save(Request::int('category_id'), $in, Request::int('id') ?: null);
        Audit::log($admin, 'attribute.save', 'category', Request::int('category_id'), ['code' => Request::str('code'), 'label' => Request::str('label')]);
        Response::ok(['id' => $id]);

    case 'attribute_delete':
        Request::requirePost();
        Attribute::delete(Request::int('id'));
        Audit::log($admin, 'attribute.delete', 'category', Request::int('category_id') ?: null, ['attribute_id' => Request::int('id')]);
        Response::ok();

    // ----- отзывы, журнал
    case 'reviews':
        Response::ok(Admin::reviews(max(1, Request::int('page', 1))));

    case 'review_delete':
        Request::requirePost();
        $r = Review::delete(Request::int('id'), $admin);
        Audit::log($admin, 'review.delete', 'user', (int) $r['seller_id'], ['review_id' => (int) $r['id'], 'author_id' => (int) $r['author_id']]);
        Response::ok();

    case 'audit':
        Response::ok(Audit::list(Request::str('filter'), max(1, Request::int('page', 1))));

    // ----- экспорт
    case 'export_users':
        $x = Admin::exportUsers();
        csvOut('users-' . date('Y-m-d') . '.csv', $x['header'], $x['rows']);

    case 'export_ads':
        $x = Admin::exportAds(Request::str('status'), Request::int('user'), Request::str('q'), Request::int('category'));
        csvOut('ads-' . date('Y-m-d') . '.csv', $x['header'], $x['rows']);

    default:
        throw new HttpException('Неизвестное действие', 404);
}
