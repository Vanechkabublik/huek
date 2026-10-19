<?php
declare(strict_types=1);

namespace App;

/**
 * Операции администратора: статистика и графики, управление пользователями, модерация,
 * массовые действия, экспорт. Права проверяются в эндпоинте (Auth::requireAdmin) —
 * здесь только бизнес-правила. Каждое изменение пишется в журнал (Audit).
 */
final class Admin
{
    public const PER_PAGE = 20;

    private const AD_SORTS = [
        'date_desc'  => 'a.created_at DESC, a.id DESC',
        'date_asc'   => 'a.created_at ASC, a.id ASC',
        'price_asc'  => 'a.price ASC, a.id DESC',
        'price_desc' => 'a.price DESC, a.id DESC',
        'views_desc' => 'a.views DESC, a.id DESC',
    ];

    /** @return array<string,int> */
    public static function stats(): array
    {
        $byStatus = array_column(Database::all('SELECT status, COUNT(*) AS n FROM ads GROUP BY status'), 'n', 'status');
        return [
            'users'      => (int) Database::value('SELECT COUNT(*) FROM users'),
            'ads'        => (int) array_sum($byStatus),
            'pending'    => (int) ($byStatus['pending'] ?? 0),
            'approved'   => (int) ($byStatus['approved'] ?? 0),
            'rejected'   => (int) ($byStatus['rejected'] ?? 0),
            'blocked'    => (int) ($byStatus['blocked'] ?? 0),
            'categories' => (int) Database::value('SELECT COUNT(*) FROM categories'),
            'reviews'    => (int) Database::value('SELECT COUNT(*) FROM reviews'),
            'chats'      => (int) Database::value('SELECT COUNT(*) FROM conversations'),
            'messages'   => (int) Database::value('SELECT COUNT(*) FROM chat_messages'),
        ];
    }

    /**
     * Данные для главной страницы админки: итоги, динамика за 14 дней, топ категорий, очередь модерации.
     * @return array<string,mixed>
     */
    public static function dashboard(): array
    {
        $months = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
        $rows = Database::all(
            "SELECT d::date AS day,
                    (SELECT COUNT(*) FROM users u WHERE u.created_at::date = d::date) AS users,
                    (SELECT COUNT(*) FROM ads a WHERE a.created_at::date = d::date) AS ads,
                    (SELECT COUNT(*) FROM chat_messages m WHERE m.created_at::date = d::date) AS messages
             FROM generate_series(CURRENT_DATE - 13, CURRENT_DATE, INTERVAL '1 day') AS d ORDER BY d"
        );
        $days = array_map(static function (array $r) use ($months) {
            $ts = strtotime($r['day']);
            return [
                'label'    => (int) date('j', $ts) . ' ' . $months[(int) date('n', $ts) - 1],
                'users'    => (int) $r['users'],
                'ads'      => (int) $r['ads'],
                'messages' => (int) $r['messages'],
            ];
        }, $rows);

        $top = Database::all(
            "SELECT c.name, c.icon, COUNT(a.id) AS n FROM categories c
             LEFT JOIN ads a ON a.category_id = c.id AND a.status = 'approved'
             GROUP BY c.id, c.name, c.icon ORDER BY n DESC, c.id LIMIT 6"
        );
        $queue = Database::all(
            Ad::baseSelect() . " WHERE a.status = 'pending' ORDER BY a.created_at ASC, a.id ASC LIMIT 5"
        );

        return [
            'stats' => self::stats(),
            'days'  => $days,
            'top_categories' => array_map(static fn(array $r) => ['name' => $r['name'], 'icon' => $r['icon'], 'count' => (int) $r['n']], $top),
            'queue' => array_map(static fn(array $r) => Ad::card($r, true), $queue),
            'audit' => Audit::list('', 1, null, null, 6)['items'],
        ];
    }

    // --------------------------------------------------------------- пользователи

    /**
     * Поиск пользователей: по email, имени, телефону или «#id».
     * @param string $filter all | active | blocked | admin | vk
     * @return array{items:array,total:int,has_more:bool}
     */
    public static function users(string $query, string $filter = 'all', int $page = 1): array
    {
        $where  = ['1 = 1'];
        $params = [];
        $query = trim($query);
        if ($query !== '') {
            $like = '%' . addcslashes($query, '%_\\') . '%';
            $where[] = "(u.email ILIKE ? OR u.name ILIKE ? OR u.phone ILIKE ? OR ('#' || u.id) = ?)";
            array_push($params, $like, $like, $like, $query);
        }
        $where[] = match ($filter) {
            'active'  => "u.is_blocked = 0 AND u.role = 'user'",
            'blocked' => 'u.is_blocked = 1',
            'admin'   => "u.role = 'admin'",
            'vk'      => 'u.vk_id IS NOT NULL',
            default   => '1 = 1',
        };
        $w = implode(' AND ', $where);

        $total  = (int) Database::value("SELECT COUNT(*) FROM users u WHERE $w", $params);
        $limit  = self::PER_PAGE;
        $offset = (max(1, $page) - 1) * $limit;
        $rows = Database::all(
            "SELECT u.id, u.email, u.name, u.phone, u.role, u.is_blocked, u.created_at, u.last_login_at, u.vk_id, u.avatar_url,
                    (SELECT COUNT(*) FROM ads WHERE user_id = u.id) AS ads_count
             FROM users u WHERE $w ORDER BY u.id DESC LIMIT $limit OFFSET $offset",
            $params
        );

        return [
            'items'    => array_map([self::class, 'userRow'], $rows),
            'total'    => $total,
            'has_more' => $offset + count($rows) < $total,
        ];
    }

    /** @return array<string,mixed> */
    private static function userRow(array $r): array
    {
        return [
            'id'         => (int) $r['id'],
            'email'      => $r['email'],
            'name'       => $r['name'],
            'phone'      => $r['phone'],
            'role'       => $r['role'],
            'is_blocked' => (bool) $r['is_blocked'],
            'ads_count'  => (int) $r['ads_count'],
            'has_vk'     => $r['vk_id'] !== null,
            'avatar'     => $r['avatar_url'] ?: null,
            'created_at' => Format::dateFull($r['created_at']),
            'last_login' => $r['last_login_at'] ? Format::date($r['last_login_at']) : 'не входил',
        ];
    }

    /** Карточка пользователя: профиль, сводка, последние объявления. */
    public static function userDetail(int $id): array
    {
        $u = User::find($id);
        if ($u === null) {
            throw new HttpException('Пользователь не найден', 404);
        }
        $counts = array_fill_keys(array_keys(Ad::STATUS_LABELS), 0);
        foreach (Database::all('SELECT status, COUNT(*) AS n FROM ads WHERE user_id = ? GROUP BY status', [$id]) as $c) {
            $counts[$c['status']] = (int) $c['n'];
        }
        $recent = Database::all(Ad::baseSelect() . ' WHERE a.user_id = ? ORDER BY a.created_at DESC LIMIT 5', [$id]);
        $rating = Review::summary($id);
        return [
            'user' => [
                'id'         => (int) $u['id'],
                'email'      => $u['email'],
                'name'       => $u['name'],
                'phone'      => $u['phone'],
                'about'      => $u['about'],
                'role'       => $u['role'],
                'is_blocked' => (bool) $u['is_blocked'],
                'vk_id'      => $u['vk_id'],
                'avatar'     => User::avatarUrl($u),
                'created_at' => Format::dateFull($u['created_at']),
                'last_login' => $u['last_login_at'] ? Format::dateFull($u['last_login_at']) : 'не входил',
            ],
            'ads'       => $counts + ['all' => array_sum($counts)],
            'favorites' => (int) Database::value('SELECT COUNT(*) FROM favorites WHERE user_id = ?', [$id]),
            'chats'     => (int) Database::value('SELECT COUNT(*) FROM conversations WHERE buyer_id = ? OR seller_id = ?', [$id, $id]),
            'reviews'   => ['count' => $rating['count'], 'avg' => $rating['avg']],
            'recent'    => array_map(static fn(array $r) => Ad::card($r, true), $recent),
            'history'   => Audit::list('', 1, 'user', $id, 10)['items'],
        ];
    }

    /** Обычного пользователя можно блокировать/удалять/менять ему пароль и роль; админов и себя — нельзя. */
    private static function targetUser(int $id, array $actor, bool $allowAdmin = false): array
    {
        $user = User::find($id);
        if ($user === null) {
            throw new HttpException('Пользователь не найден', 404);
        }
        if ((int) $user['id'] === (int) $actor['id'] || ($user['role'] === 'admin' && !$allowAdmin)) {
            throw new HttpException('Это действие недоступно для администраторов', 403);
        }
        return $user;
    }

    public static function setBlocked(int $userId, bool $blocked, array $actor): void
    {
        $u = self::targetUser($userId, $actor);
        Database::run('UPDATE users SET is_blocked = ? WHERE id = ?', [(int) $blocked, $userId]);
        Audit::log($actor, $blocked ? 'user.block' : 'user.unblock', 'user', $userId, ['name' => $u['name'], 'email' => $u['email']]);
    }

    public static function changePassword(int $userId, string $password, array $actor): void
    {
        $u = self::targetUser($userId, $actor);
        if (mb_strlen($password) < 6 || mb_strlen($password) > 72) {
            throw new HttpException('Пароль: от 6 до 72 символов', 422, ['password' => 'Пароль: от 6 до 72 символов']);
        }
        User::setPassword($userId, $password);
        Audit::log($actor, 'user.password', 'user', $userId, ['name' => $u['name']]);
    }

    /** Назначает или снимает роль администратора (себя менять нельзя — чтобы не остаться без админов). */
    public static function setRole(int $userId, string $role, array $actor): void
    {
        if (!in_array($role, ['user', 'admin'], true)) {
            throw new HttpException('Недопустимая роль', 422);
        }
        $u = self::targetUser($userId, $actor, true);
        if ($role === 'admin' && (int) $u['is_blocked'] === 1) {
            throw new HttpException('Сначала разблокируйте пользователя', 422);
        }
        Database::run('UPDATE users SET role = ? WHERE id = ?', [$role, $userId]);
        Audit::log($actor, 'user.role', 'user', $userId, ['name' => $u['name'], 'from' => $u['role'], 'to' => $role]);
    }

    /** Удаляет пользователя вместе с объявлениями, чатами и файлами фото/аватара. */
    public static function deleteUser(int $userId, array $actor): void
    {
        $u = self::targetUser($userId, $actor);
        $files = array_column(Database::all(
            'SELECT i.filename FROM ad_images i JOIN ads a ON a.id = i.ad_id WHERE a.user_id = ?',
            [$userId]
        ), 'filename');
        Database::run('DELETE FROM users WHERE id = ?', [$userId]);   // всё связанное удаляется каскадом
        array_map([Upload::class, 'remove'], $files);
        Upload::removeAvatarUrl($u['avatar_url'] ?? null);
        Audit::log($actor, 'user.delete', 'user', $userId, ['name' => $u['name'], 'email' => $u['email']]);
    }

    // ----------------------------------------------------------------- объявления

    /**
     * @return array{where:string,params:array<int,mixed>}
     */
    private static function adFilters(string $status, int $userId, string $q, int $categoryId): array
    {
        $where  = ['1 = 1'];
        $params = [];
        if (isset(Ad::STATUS_LABELS[$status])) {
            $where[] = 'a.status = ?';
            $params[] = $status;
        }
        if ($userId > 0) {
            $where[] = 'a.user_id = ?';
            $params[] = $userId;
        }
        if ($categoryId > 0) {
            $where[] = 'a.category_id = ?';
            $params[] = $categoryId;
        } elseif ($categoryId === -1) {
            $where[] = 'a.category_id IS NULL';
        }
        $q = trim($q);
        if ($q !== '') {
            $where[] = "(a.title ILIKE ? OR u.email ILIKE ? OR ('#' || a.id) = ?)";
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $q);
        }
        return ['where' => implode(' AND ', $where), 'params' => $params];
    }

    /**
     * Список объявлений для модерации.
     * @return array{items:array,total:int,counts:array<string,int>,has_more:bool}
     */
    public static function ads(string $status, int $userId, string $q, int $page = 1, int $categoryId = 0, string $sort = 'date_desc'): array
    {
        $f = self::adFilters($status, $userId, $q, $categoryId);
        $total  = (int) Database::value("SELECT COUNT(*) FROM ads a JOIN users u ON u.id = a.user_id WHERE {$f['where']}", $f['params']);
        $limit  = self::PER_PAGE;
        $offset = (max(1, $page) - 1) * $limit;
        $order  = self::AD_SORTS[$sort] ?? self::AD_SORTS['date_desc'];
        $rows = Database::all(
            Ad::baseSelect() . " WHERE {$f['where']} ORDER BY $order LIMIT $limit OFFSET $offset",
            $f['params']
        );

        // Счётчики для табов считаем без учёта выбранного статуса
        $c = self::adFilters('', $userId, $q, $categoryId);
        $counts = array_fill_keys(array_keys(Ad::STATUS_LABELS), 0);
        foreach (Database::all("SELECT a.status, COUNT(*) AS n FROM ads a JOIN users u ON u.id = a.user_id WHERE {$c['where']} GROUP BY a.status", $c['params']) as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        $counts['all'] = array_sum($counts);

        return [
            'items'    => array_map(static fn(array $r) => Ad::card($r, true) + ['user_blocked' => (bool) $r['user_blocked']], $rows),
            'total'    => $total,
            'counts'   => $counts,
            'has_more' => $offset + count($rows) < $total,
        ];
    }

    /** Объявление целиком + история модерации + статистика (для модалки «Подробнее»). */
    public static function adDetail(int $id): array
    {
        $ad = Ad::get($id, ['id' => 0, 'role' => 'admin']);
        if ($ad === null) {
            throw new HttpException('Объявление не найдено', 404);
        }
        $ad['seller_email'] = Database::value('SELECT email FROM users WHERE id = ?', [(int) $ad['user_id']]);
        return [
            'ad'      => $ad,
            'history' => Audit::list('ad.', 1, 'ad', $id, 15)['items'],
            'stats'   => Stats::forAd($id)['totals'],
        ];
    }

    /**
     * Меняет статус объявления. Для отклонения и блокировки причина обязательна.
     */
    public static function moderate(int $adId, string $status, string $reason, array $actor): void
    {
        if (!in_array($status, ['approved', 'rejected', 'blocked'], true)) {
            throw new HttpException('Недопустимый статус', 422);
        }
        $ad = Database::one('SELECT id, title FROM ads WHERE id = ?', [$adId]);
        if ($ad === null) {
            throw new HttpException('Объявление не найдено', 404);
        }

        $reason = trim($reason);
        if ($status === 'approved') {
            $reason = null;
        } elseif (mb_strlen($reason) < 3) {
            throw new HttpException('Укажите причину (минимум 3 символа)', 422, ['reason' => 'Укажите причину (минимум 3 символа)']);
        } elseif (mb_strlen($reason) > 1000) {
            throw new HttpException('Причина слишком длинная', 422, ['reason' => 'Максимум 1000 символов']);
        }

        Database::run('UPDATE ads SET status = ?, rejection_reason = ?, updated_at = NOW() WHERE id = ?', [$status, $reason, $adId]);
        Audit::log($actor, ['approved' => 'ad.approve', 'rejected' => 'ad.reject', 'blocked' => 'ad.block'][$status], 'ad', $adId,
            ['title' => $ad['title']] + ($reason !== null ? ['reason' => $reason] : []));
    }

    public static function deleteAd(int $adId, array $actor): void
    {
        $ad = Database::one('SELECT id, title FROM ads WHERE id = ?', [$adId]);
        if ($ad === null) {
            throw new HttpException('Объявление не найдено', 404);
        }
        $files = array_column(Database::all('SELECT filename FROM ad_images WHERE ad_id = ?', [$adId]), 'filename');
        Database::run('DELETE FROM ads WHERE id = ?', [$adId]);
        array_map([Upload::class, 'remove'], $files);
        Audit::log($actor, 'ad.delete', 'ad', $adId, ['title' => $ad['title']]);
    }

    /**
     * Массовое действие над объявлениями (до 100 штук).
     * @param int[] $ids
     * @return array{done:int,failed:int}
     */
    public static function bulk(array $ids, string $action, string $reason, array $actor): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i) => $i > 0)));
        if (!$ids) {
            throw new HttpException('Выберите объявления', 422);
        }
        if (count($ids) > 100) {
            throw new HttpException('За один раз можно обработать не больше 100 объявлений', 422);
        }
        $status = ['approve' => 'approved', 'reject' => 'rejected', 'block' => 'blocked'][$action] ?? null;
        if ($status === null && $action !== 'delete') {
            throw new HttpException('Неизвестное действие', 422);
        }
        if (in_array($action, ['reject', 'block'], true) && mb_strlen(trim($reason)) < 3) {
            throw new HttpException('Укажите причину (минимум 3 символа)', 422, ['reason' => 'Укажите причину (минимум 3 символа)']);
        }

        $done = 0;
        $failed = 0;
        foreach ($ids as $id) {
            try {
                $action === 'delete' ? self::deleteAd($id, $actor) : self::moderate($id, $status, $reason, $actor);
                $done++;
            } catch (HttpException) {
                $failed++;
            }
        }
        Audit::log($actor, 'bulk', 'ad', null, ['action' => $action, 'done' => $done, 'failed' => $failed]);
        return ['done' => $done, 'failed' => $failed];
    }

    // --------------------------------------------------------------------- отзывы

    /** @return array{items:array,total:int,has_more:bool} */
    public static function reviews(int $page = 1): array
    {
        $limit  = self::PER_PAGE;
        $offset = (max(1, $page) - 1) * $limit;
        $total  = (int) Database::value('SELECT COUNT(*) FROM reviews');
        $rows = Database::all(
            "SELECT r.id, r.rating, r.body, r.created_at, r.seller_id, r.author_id,
                    s.name AS seller_name, a.name AS author_name
             FROM reviews r JOIN users s ON s.id = r.seller_id JOIN users a ON a.id = r.author_id
             ORDER BY r.created_at DESC, r.id DESC LIMIT $limit OFFSET $offset"
        );
        return [
            'items' => array_map(static fn(array $r) => [
                'id'          => (int) $r['id'],
                'rating'      => (int) $r['rating'],
                'body'        => $r['body'],
                'seller_id'   => (int) $r['seller_id'],
                'seller_name' => $r['seller_name'],
                'author_id'   => (int) $r['author_id'],
                'author_name' => $r['author_name'],
                'date_text'   => Format::date($r['created_at']),
            ], $rows),
            'total'    => $total,
            'has_more' => $offset + count($rows) < $total,
        ];
    }

    // --------------------------------------------------------------------- экспорт

    /** @return array{header:string[],rows:array<int,array<int,scalar|null>>} */
    public static function exportUsers(): array
    {
        $rows = Database::all(
            "SELECT u.id, u.email, u.name, u.phone, u.role, u.is_blocked, (u.vk_id IS NOT NULL) AS vk,
                    (SELECT COUNT(*) FROM ads WHERE user_id = u.id) AS ads_count, u.created_at, u.last_login_at
             FROM users u ORDER BY u.id"
        );
        return [
            'header' => ['ID', 'Email', 'Имя', 'Телефон', 'Роль', 'Заблокирован', 'VK', 'Объявлений', 'Регистрация', 'Последний вход'],
            'rows'   => array_map(static fn(array $r) => [
                $r['id'], $r['email'], $r['name'], $r['phone'], $r['role'], $r['is_blocked'] ? 'да' : 'нет',
                $r['vk'] ? 'да' : 'нет', $r['ads_count'], $r['created_at'], $r['last_login_at'],
            ], $rows),
        ];
    }

    /** @return array{header:string[],rows:array<int,array<int,scalar|null>>} */
    public static function exportAds(string $status, int $userId, string $q, int $categoryId): array
    {
        $f = self::adFilters($status, $userId, $q, $categoryId);
        $rows = Database::all(
            Ad::baseSelect() . " WHERE {$f['where']} ORDER BY a.id",
            $f['params']
        );
        return [
            'header' => ['ID', 'Заголовок', 'Категория', 'Цена, ₽', 'Город', 'Статус', 'Автор (email)', 'Просмотры', 'В избранном', 'Создано', 'Причина отказа'],
            'rows'   => array_map(static fn(array $r) => [
                $r['id'], $r['title'], $r['category_name'] ?? '', $r['price'], $r['city'], Ad::STATUS_LABELS[$r['status']] ?? $r['status'],
                $r['user_email'], $r['views'], $r['fav_count'], $r['created_at'], $r['rejection_reason'],
            ], $rows),
        ];
    }
}
