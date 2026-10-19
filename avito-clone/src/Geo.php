<?php
declare(strict_types=1);

namespace App;

/**
 * Город посетителя («как на Авито»): выбирается вручную или определяется по геопозиции из браузера.
 *
 *  • браузер спрашивает разрешение (navigator.geolocation) и присылает координаты на /api/geo.php?action=resolve;
 *  • сервер превращает координаты в город через OpenStreetMap Nominatim (адрес сервиса меняется переменной GEOCODER_URL),
 *    результаты кэшируются по сетке ~5 км, точные координаты нигде не сохраняются;
 *  • выбранный город запоминается в cookie «city» (и в профиле, если человек вошёл). Значение «all» — «все города».
 */
final class Geo
{
    public const ALL = 'all';
    private const COOKIE = 'city';

    /** Город из cookie или профиля; 'all' — человек явно выбрал «все города»; null — ещё не выбирал. */
    public static function current(?array $user): ?string
    {
        $c = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($c) && $c !== '') {
            return $c === self::ALL ? self::ALL : self::cleanName($c);
        }
        if ($user !== null && !empty($user['city'])) {
            return (string) $user['city'];
        }
        return null;
    }

    /** Название населённого пункта: буквы, цифры, пробел, дефис, точка, скобки, апостроф; до 80 знаков. */
    public static function cleanName(string $name): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 80 || !preg_match('/^[\p{L}\p{N} .\'()\-]+$/u', $name)) {
            return null;
        }
        return $name;
    }

    /** Запоминает выбор. @param string $city название или Geo::ALL */
    public static function remember(string $city, ?int $userId): string
    {
        $value = $city === self::ALL ? self::ALL : self::cleanName($city);
        if ($value === null) {
            throw new HttpException('Некорректное название города', 422);
        }
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie(self::COOKIE, $value, [
                'expires'  => time() + 365 * 86400,
                'path'     => '/',
                'secure'   => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[self::COOKIE] = $value;
        if ($userId !== null) {
            Database::run('UPDATE users SET city = ? WHERE id = ?', [$value === self::ALL ? null : $value, $userId]);
        }
        return $value;
    }

    /**
     * Координаты → город.
     * @return array{city:string,region:?string}|null null — не удалось определить
     */
    public static function resolve(float $lat, float $lon): ?array
    {
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            throw new HttpException('Некорректные координаты', 422);
        }
        // Не чаще 20 определений в час с одной сессии: геокодер бесплатный и просит не злоупотреблять
        $now = time();
        $_SESSION['geo_calls'] = array_values(array_filter($_SESSION['geo_calls'] ?? [], static fn($t) => $t > $now - 3600));
        if (count($_SESSION['geo_calls']) >= 20) {
            throw new HttpException('Слишком много запросов. Выберите город вручную.', 429);
        }
        $_SESSION['geo_calls'][] = $now;

        $key = sprintf('%.2f:%.2f', round($lat / 0.05) * 0.05, round($lon / 0.05) * 0.05);
        $hit = Database::one('SELECT city, region FROM geo_cache WHERE key = ?', [$key]);
        if ($hit !== null) {
            return $hit['city'] ? ['city' => $hit['city'], 'region' => $hit['region']] : null;
        }

        $base = (string) (getenv('GEOCODER_URL') ?: 'https://nominatim.openstreetmap.org/reverse');
        $url = $base . (str_contains($base, '?') ? '&' : '?') . http_build_query([
            'format' => 'jsonv2', 'lat' => sprintf('%.5f', $lat), 'lon' => sprintf('%.5f', $lon),
            'zoom' => 10, 'addressdetails' => 1, 'accept-language' => 'ru',
        ]);
        try {
            $data = Http::getJson($url, ['User-Agent: ' . Settings::siteName() . '/1.0 (' . OAuth::appUrl() . ')', 'Referer: ' . OAuth::appUrl() . '/']);
        } catch (\RuntimeException $e) {
            error_log('Geo::resolve failed: ' . $e->getMessage());
            return null;
        }
        $a = is_array($data['address'] ?? null) ? $data['address'] : [];
        $city = null;
        foreach (['city', 'town', 'village', 'municipality', 'hamlet', 'city_district', 'county'] as $k) {
            if (!empty($a[$k]) && is_string($a[$k])) {
                $city = self::canonical(preg_replace('/^(город|городской округ|поселок|посёлок|село|деревня)\s+/iu', '', $a[$k]) ?? $a[$k]);
                break;
            }
        }
        $region = is_string($a['state'] ?? null) ? $a['state'] : null;
        // Кэшируем и «не нашли»: повторные запросы с тех же координат геокодер не нагружают
        Database::run(
            'INSERT INTO geo_cache (key, city, region) VALUES (?, ?, ?) ON CONFLICT (key) DO NOTHING',
            [$key, $city, $region]
        );
        return $city !== null ? ['city' => $city, 'region' => $region] : null;
    }

    /** Приводит написание к виду из нашего списка городов (регистр, «ё»), если город там есть. */
    private static function canonical(string $name): ?string
    {
        $norm = static fn(string $s): string => str_replace('ё', 'е', mb_strtolower(trim($s)));
        static $map = null;
        if ($map === null) {
            $map = [];
            $json = @file_get_contents(BASE_PATH . '/public/assets/data/cities.json');
            foreach ((array) json_decode((string) $json, true) as $c) {
                if (is_string($c)) {
                    $map[$norm($c)] = $c;
                }
            }
        }
        $clean = self::cleanName($name);
        return $clean === null ? null : ($map[$norm($clean)] ?? $clean);
    }
}
