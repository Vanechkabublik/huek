<?php
declare(strict_types=1);

namespace App;

/**
 * Избранное: сердечко на карточке и странице объявления, страница «Избранное».
 */
final class Favorite
{
    /**
     * Добавляет/убирает объявление из избранного.
     * @return array{is_favorite:bool,count:int}
     */
    public static function toggle(int $userId, int $adId): array
    {
        $ad = Database::one('SELECT id, user_id, status FROM ads WHERE id = ?', [$adId]);
        if ($ad === null || ($ad['status'] !== 'approved' && !self::has($userId, $adId))) {
            throw new HttpException('Объявление не найдено', 404);
        }
        if ((int) $ad['user_id'] === $userId) {
            throw new HttpException('Нельзя добавить в избранное своё объявление', 422);
        }

        if (self::has($userId, $adId)) {
            Database::run('DELETE FROM favorites WHERE user_id = ? AND ad_id = ?', [$userId, $adId]);
            $now = false;
        } else {
            Database::run('INSERT INTO favorites (user_id, ad_id) VALUES (?, ?) ON CONFLICT DO NOTHING', [$userId, $adId]);
            $now = true;
        }
        return ['is_favorite' => $now, 'count' => self::countForAd($adId)];
    }

    public static function has(int $userId, int $adId): bool
    {
        return Database::value('SELECT 1 FROM favorites WHERE user_id = ? AND ad_id = ?', [$userId, $adId]) !== null;
    }

    public static function countForAd(int $adId): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM favorites WHERE ad_id = ?', [$adId]);
    }

    public static function countForUser(int $userId): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM favorites f JOIN ads a ON a.id = f.ad_id WHERE f.user_id = ? AND a.status = 'approved'",
            [$userId]
        );
    }

    /**
     * Проставляет is_favorite в карточках списка одним запросом.
     * @param array<int,array<string,mixed>> $items
     */
    public static function mark(array &$items, ?int $userId): void
    {
        foreach ($items as &$it) {
            $it['is_favorite'] = false;
        }
        unset($it);
        if ($userId === null || !$items) {
            return;
        }
        $ids = array_map(static fn(array $i) => (int) $i['id'], $items);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $fav = array_flip(array_map('intval', array_column(
            Database::all("SELECT ad_id FROM favorites WHERE user_id = ? AND ad_id IN ($in)", [$userId, ...$ids]),
            'ad_id'
        )));
        foreach ($items as &$it) {
            $it['is_favorite'] = isset($fav[$it['id']]);
        }
    }

    /**
     * Страница «Избранное»: опубликованные объявления, новые добавления — первыми.
     * @return array{items:array,total:int,has_more:bool}
     */
    public static function listFor(int $userId, int $page = 1): array
    {
        $limit  = Ad::PER_PAGE;
        $offset = (max(1, $page) - 1) * $limit;
        $total  = self::countForUser($userId);
        $rows = Database::all(
            Ad::baseSelect() . " JOIN favorites f ON f.ad_id = a.id AND f.user_id = ?
             WHERE a.status = 'approved' AND u.is_blocked = 0
             ORDER BY f.created_at DESC LIMIT $limit OFFSET $offset",
            [$userId]
        );
        $items = array_map(static fn(array $r) => Ad::card($r), $rows);
        foreach ($items as &$it) {
            $it['is_favorite'] = true;
        }
        return ['items' => $items, 'total' => $total, 'has_more' => $offset + count($items) < $total];
    }
}
