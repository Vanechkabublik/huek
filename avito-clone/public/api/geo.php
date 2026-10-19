<?php
declare(strict_types=1);

/**
 * Город посетителя.
 *   POST ?action=resolve  {lat, lon}   — определить город по координатам из браузера (город не сохраняется)
 *   POST ?action=set      {city}       — запомнить выбранный город ("all" — все города)
 */

require __DIR__ . '/../../src/bootstrap.php';

use App\Auth;
use App\Geo;
use App\HttpException;
use App\Request;
use App\Response;

Response::handleExceptions();
Request::requirePost();

switch (Request::action()) {
    case 'resolve':
        $in = Request::input();
        if (!isset($in['lat'], $in['lon']) || !is_numeric($in['lat']) || !is_numeric($in['lon'])) {
            throw new HttpException('Не переданы координаты', 422);
        }
        $found = Geo::resolve((float) $in['lat'], (float) $in['lon']);
        if ($found === null) {
            throw new HttpException('Не удалось определить город. Выберите его из списка.', 404);
        }
        Response::ok($found);

    case 'set':
        $city = Geo::remember(Request::str('city'), Auth::id());
        Response::ok(['city' => $city]);

    default:
        throw new HttpException('Неизвестное действие', 404);
}
