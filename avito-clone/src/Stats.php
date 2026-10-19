<?php
declare(strict_types=1);

namespace App;

/**
 * Статистика объявлений. Главное правило — считаем ЛЮДЕЙ, а не загрузки страницы:
 *  • один человек = один viewer_key на объявление, обновление страницы счётчик не увеличивает;
 *  • вошедший пользователь — ключ «u<id>», гость — ключ «v<случайный id из cookie vid» (живёт год);
 *  • не считаем автора, администраторов, ботов/превью ссылок и prefetch-запросы.
 */
final class Stats
{
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|preview|facebookexternalhit|embedly|monitor|uptime|headless|lighthouse|curl|wget|python-requests|python-urllib|go-http|okhttp|java\/|libwww|httpclient|telegram|whatsapp|vkshare|yandex|mail\.ru|discord|skype|pinterest|google|bing|duckduck|baidu|semrush|ahrefs|mj12/i';

    /** Идентификатор «человека» для текущего запроса (создаёт cookie гостю при первом заходе). */
    public static function viewerKey(?array $viewer): string
    {
        $vid = $_COOKIE['vid'] ?? '';
        if (!is_string($vid) || !preg_match('/^[a-f0-9]{24}$/', $vid)) {
            $vid = bin2hex(random_bytes(12));
            if (PHP_SAPI !== 'cli' && !headers_sent()) {
                setcookie('vid', $vid, [
                    'expires'  => time() + 365 * 86400,
                    'path'     => '/',
                    'secure'   => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            $_COOKIE['vid'] = $vid;
        }
        return $viewer !== null ? 'u' . (int) $viewer['id'] : 'v' . $vid;
    }

    public static function isBot(): bool
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '' || preg_match(self::BOT_PATTERN, $ua)) {
            return true;
        }
        $purpose = strtolower(($_SERVER['HTTP_PURPOSE'] ?? '') . ' ' . ($_SERVER['HTTP_SEC_PURPOSE'] ?? '') . ' ' . ($_SERVER['HTTP_X_MOZ'] ?? ''));
        return str_contains($purpose, 'prefetch') || str_contains($purpose, 'prerender') || (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD');
    }

    /**
     * Учитывает просмотр объявления. Возвращает true, если это НОВЫЙ уникальный зритель.
     */
    public static function trackView(int $adId, int $ownerId, ?array $viewer): bool
    {
        if (($viewer !== null && ((int) $viewer['id'] === $ownerId || $viewer['role'] === 'admin')) || self::isBot()) {
            return false;
        }
        $key = self::viewerKey($viewer);

        // Если человек раньше смотрел как гость, а потом вошёл — не считаем его второй раз
        if ($viewer !== null) {
            $guestKey = 'v' . $_COOKIE['vid'];
            if (Database::value('SELECT 1 FROM ad_views WHERE ad_id = ? AND viewer_key = ?', [$adId, $guestKey]) !== null) {
                Database::run('UPDATE ad_views SET last_seen = NOW() WHERE ad_id = ? AND viewer_key = ?', [$adId, $guestKey]);
                return false;
            }
        }

        // xmax = 0 у вставленной строки: так отличаем INSERT от ON CONFLICT DO UPDATE
        $inserted = Database::value(
            'INSERT INTO ad_views (ad_id, viewer_key) VALUES (?, ?)
             ON CONFLICT (ad_id, viewer_key) DO UPDATE SET last_seen = NOW()
             RETURNING (xmax = 0)',
            [$adId, $key]
        );
        if ($inserted === true || $inserted === 't' || $inserted === 1 || $inserted === '1') {
            Database::run('UPDATE ads SET views = views + 1 WHERE id = ?', [$adId]);
            return true;
        }
        return false;
    }

    /** Показан телефон продавца (один раз на человека). */
    public static function trackPhone(int $adId, ?array $viewer): void
    {
        if (self::isBot()) {
            return;
        }
        $key = self::viewerKey($viewer);
        Database::run(
            "INSERT INTO ad_contacts (ad_id, viewer_key, type) VALUES (?, ?, 'phone') ON CONFLICT DO NOTHING",
            [$adId, $key]
        );
    }

    /**
     * Статистика объявления для автора: итоги и динамика за 14 дней.
     * @return array<string,mixed>
     */
    public static function forAd(int $adId): array
    {
        $ad = Database::one('SELECT id, views FROM ads WHERE id = ?', [$adId]);
        if ($ad === null) {
            throw new HttpException('Объявление не найдено', 404);
        }
        $views     = (int) $ad['views'];
        $favorites = Favorite::countForAd($adId);
        $phones    = (int) Database::value("SELECT COUNT(*) FROM ad_contacts WHERE ad_id = ? AND type = 'phone'", [$adId]);
        $chats     = (int) Database::value('SELECT COUNT(*) FROM conversations WHERE ad_id = ?', [$adId]);
        $contacts  = $phones + $chats;

        $rows = Database::all(
            "SELECT d::date AS day,
                    (SELECT COUNT(*) FROM ad_views v WHERE v.ad_id = ? AND v.first_seen::date = d::date) AS views,
                    (SELECT COUNT(*) FROM favorites f WHERE f.ad_id = ? AND f.created_at::date = d::date) AS favorites,
                    (SELECT COUNT(*) FROM ad_contacts c WHERE c.ad_id = ? AND c.created_at::date = d::date)
                  + (SELECT COUNT(*) FROM conversations k WHERE k.ad_id = ? AND k.created_at::date = d::date) AS contacts
             FROM generate_series(CURRENT_DATE - 13, CURRENT_DATE, INTERVAL '1 day') AS d
             ORDER BY d",
            [$adId, $adId, $adId, $adId]
        );
        $months = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
        $days = array_map(static function (array $r) use ($months) {
            $ts = strtotime($r['day']);
            return [
                'date'      => $r['day'],
                'label'     => (int) date('j', $ts) . ' ' . $months[(int) date('n', $ts) - 1],
                'views'     => (int) $r['views'],
                'favorites' => (int) $r['favorites'],
                'contacts'  => (int) $r['contacts'],
            ];
        }, $rows);

        return [
            'totals' => [
                'views'        => $views,
                'favorites'    => $favorites,
                'phones'       => $phones,
                'chats'        => $chats,
                'contacts'     => $contacts,
                'contact_rate' => $views > 0 ? round($contacts / $views * 100, 1) : 0.0,
            ],
            'days' => $days,
        ];
    }
}
