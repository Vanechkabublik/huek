<?php
declare(strict_types=1);

namespace App;

/**
 * Операции администратора: статистика, управление пользователями, модерация объявлений.
 * Права проверяются в эндпоинте (Auth::requireAdmin) — здесь только бизнес-правила.
 */
final class Admin
{
    public const PER_PAGE = 20;

    /** @return array{users:int,ads:int,pending:int,blocked:int,approved:int,rejected:int,categories:int} */
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
        ];
    }

    // --------------------------------------------------------------- пользователи

    /**
     * Поиск пользователей по части email (мгновенный поиск в админке).
     * @return array{items:array,total:int,has_more:bool}
     */
    public static function users(string $emailQuery, int $page = 1): array
    {
        $where  = '1 = 1';
        $params = [];
        $emailQuery = trim($emailQuery);
        if ($emailQuery !== '') {
            $where = "u.email LIKE ? ESCAPE '\\\\'";
            $params[] = '%' . addcslashes(mb_strtolower($emailQuery), '%_\\') . '%';
        }

        $total  = (int) Database::value("SELECT COUNT(*) FROM users u WHERE $where", $params);
        $limit  = self::PER_PAGE;
        $offset = (max(1, $page) - 1) * $limit;
        $rows = Database::all(
            "SELECT u.id, u.email, u.name, u.phone, u.role, u.is_blocked, u.created_at,
                    (SELECT COUNT(*) FROM ads WHERE user_id = u.id) AS ads_count
             FROM users u WHERE $where ORDER BY u.id DESC LIMIT $limit OFFSET $offset",
            $params
        );

        return [
            'items' => array_map(static fn(array $r) => [
                'id'         => (int) $r['id'],
                'email'      => $r['email'],
                'name'       => $r['name'],
                'phone'      => $r['phone'],
                'role'       => $r['role'],
                'is_blocked' => (bool) $r['is_blocked'],
                'ads_count'  => (int) $r['ads_count'],
                'created_at' => Format::dateFull($r['created_at']),
            ], $rows),
            'total'    => $total,
            'has_more' => $offset + count($rows) < $total,
        ];
    }

    /** Обычного пользователя можно блокировать/удалять/менять пароль; админов и себя — нельзя. */
    private static function targetUser(int $id, array $actor): array
    {
        $user = User::find($id);
        if ($user === null) {
            throw new HttpException('Пользователь не найден', 404);
        }
        if ((int) $user['id'] === (int) $actor['id'] || $user['role'] === 'admin') {
            throw new HttpException('Это действие недоступно для администраторов', 403);
        }
        return $user;
    }

    public static function setBlocked(int $userId, bool $blocked, array $actor): void
    {
        self::targetUser($userId, $actor);
        Database::run('UPDATE users SET is_blocked = ? WHERE id = ?', [(int) $blocked, $userId]);
    }

    public static function changePassword(int $userId, string $password, array $actor): void
    {
        self::targetUser($userId, $actor);
        if (mb_strlen($password) < 6 || mb_strlen($password) > 72) {
            throw new HttpException('Пароль: от 6 до 72 символов', 422, ['password' => 'Пароль: от 6 до 72 символов']);
        }
        User::setPassword($userId, $password);
    }

    /** Удаляет пользователя вместе с объявлениями и файлами фото. */
    public static function deleteUser(int $userId, array $actor): void
    {
        self::targetUser($userId, $actor);
        $files = array_column(Database::all(
            'SELECT i.filename FROM ad_images i JOIN ads a ON a.id = i.ad_id WHERE a.user_id = ?',
            [$userId]
        ), 'filename');
        Database::run('DELETE FROM users WHERE id = ?', [$userId]);   // ads, ad_images, messages — каскадом
        array_map([Upload::class, 'remove'], $files);
    }

    // ----------------------------------------------------------------- объявления

    /**
     * Список объявлений для модерации.
     * @return array{items:array,total:int,counts:array<string,int>,has_more:bool}
     */
    public static function ads(string $status, int $userId, string $q, int $page = 1): array
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
        $q = trim($q);
        if ($q !== '') {
            $where[] = "(a.title LIKE ? ESCAPE '\\\\' OR u.email LIKE ? ESCAPE '\\\\')";
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like);
        }
        $whereSql = implode(' AND ', $where);

        $total  = (int) Database::value("SELECT COUNT(*) FROM ads a JOIN users u ON u.id = a.user_id WHERE $whereSql", $params);
        $limit  = self::PER_PAGE;
        $offset = (max(1, $page) - 1) * $limit;
        $rows = Database::all(
            Ad::baseSelect() . " WHERE $whereSql ORDER BY a.created_at DESC, a.id DESC LIMIT $limit OFFSET $offset",
            $params
        );

        // Счётчики для табов считаем без учёта выбранного статуса
        $cWhere = ['1 = 1'];
        $cParams = [];
        if ($userId > 0) {
            $cWhere[] = 'a.user_id = ?';
            $cParams[] = $userId;
        }
        $counts = array_fill_keys(array_keys(Ad::STATUS_LABELS), 0);
        foreach (Database::all('SELECT a.status, COUNT(*) AS n FROM ads a WHERE ' . implode(' AND ', $cWhere) . ' GROUP BY a.status', $cParams) as $c) {
            $counts[$c['status']] = (int) $c['n'];
        }
        $counts['all'] = array_sum($counts);

        return [
            'items'    => array_map(static fn(array $r) => Ad::card($r, true) + ['user_blocked' => (bool) $r['user_blocked']], $rows),
            'total'    => $total,
            'counts'   => $counts,
            'has_more' => $offset + count($rows) < $total,
        ];
    }

    /**
     * Меняет статус объявления. Для отклонения и блокировки причина обязательна.
     */
    public static function moderate(int $adId, string $status, string $reason): void
    {
        if (!in_array($status, ['approved', 'rejected', 'blocked'], true)) {
            throw new HttpException('Недопустимый статус', 422);
        }
        if (Database::value('SELECT 1 FROM ads WHERE id = ?', [$adId]) === null) {
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

        Database::run('UPDATE ads SET status = ?, rejection_reason = ? WHERE id = ?', [$status, $reason, $adId]);
    }
}
