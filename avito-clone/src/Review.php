<?php
declare(strict_types=1);

namespace App;

/**
 * Отзывы о продавцах (оценка 1–5 + текст). Оставить отзыв может только тот, кто переписывался
 * с продавцом по его объявлению, — так нельзя накрутить рейтинг «с улицы». Один отзыв от одного автора.
 */
final class Review
{
    public const PER_PAGE = 10;

    /** @return array{avg:float,count:int,dist:array<int,int>} */
    public static function summary(int $sellerId): array
    {
        $dist = array_fill(1, 5, 0);
        $rows = Database::all('SELECT rating, COUNT(*) AS n FROM reviews WHERE seller_id = ? GROUP BY rating', [$sellerId]);
        $sum = 0;
        $count = 0;
        foreach ($rows as $r) {
            $dist[(int) $r['rating']] = (int) $r['n'];
            $sum += (int) $r['rating'] * (int) $r['n'];
            $count += (int) $r['n'];
        }
        return ['avg' => $count > 0 ? round($sum / $count, 1) : 0.0, 'count' => $count, 'dist' => $dist];
    }

    /**
     * @return array{items:array,total:int,has_more:bool}
     */
    public static function listFor(int $sellerId, int $page = 1): array
    {
        $limit  = self::PER_PAGE;
        $offset = (max(1, $page) - 1) * $limit;
        $total  = (int) Database::value('SELECT COUNT(*) FROM reviews WHERE seller_id = ?', [$sellerId]);
        $rows = Database::all(
            "SELECT r.id, r.rating, r.body, r.created_at, r.author_id, u.name AS author_name, u.avatar_url
             FROM reviews r JOIN users u ON u.id = r.author_id
             WHERE r.seller_id = ? ORDER BY r.created_at DESC, r.id DESC LIMIT $limit OFFSET $offset",
            [$sellerId]
        );
        return [
            'items'    => array_map(static fn(array $r) => self::format($r), $rows),
            'total'    => $total,
            'has_more' => $offset + count($rows) < $total,
        ];
    }

    /** @return array<string,mixed> */
    public static function format(array $r): array
    {
        return [
            'id'        => (int) $r['id'],
            'rating'    => (int) $r['rating'],
            'body'      => $r['body'],
            'date_text' => Format::date($r['created_at']),
            'author'    => [
                'id'      => (int) $r['author_id'],
                'name'    => $r['author_name'],
                'initial' => mb_strtoupper(mb_substr($r['author_name'], 0, 1)),
                'avatar'  => $r['avatar_url'] ?: null,
            ],
        ];
    }

    /**
     * Может ли пользователь оставить (или изменить) отзыв продавцу.
     * @return array{can:bool,reason:?string,mine:?array}
     */
    public static function eligibility(?int $authorId, int $sellerId): array
    {
        if ($authorId === null) {
            return ['can' => false, 'reason' => 'login', 'mine' => null];
        }
        if ($authorId === $sellerId) {
            return ['can' => false, 'reason' => 'self', 'mine' => null];
        }
        $mine = Database::one(
            'SELECT r.id, r.rating, r.body, r.created_at, r.author_id, u.name AS author_name, u.avatar_url
             FROM reviews r JOIN users u ON u.id = r.author_id WHERE r.seller_id = ? AND r.author_id = ?',
            [$sellerId, $authorId]
        );
        $talked = Database::value(
            'SELECT 1 FROM conversations WHERE seller_id = ? AND buyer_id = ? LIMIT 1',
            [$sellerId, $authorId]
        ) !== null;
        // Продавец, с которым уже общались, тоже может оценить покупателя (диалог в обратную сторону)
        $talked = $talked || Database::value(
            'SELECT 1 FROM conversations WHERE seller_id = ? AND buyer_id = ? LIMIT 1',
            [$authorId, $sellerId]
        ) !== null;
        if (!$talked && $mine === null) {
            return ['can' => false, 'reason' => 'no_chat', 'mine' => null];
        }
        return ['can' => true, 'reason' => null, 'mine' => $mine ? self::format($mine) : null];
    }

    /** Создаёт отзыв или обновляет прежний. */
    public static function save(int $authorId, int $sellerId, int $rating, string $body): void
    {
        $e = self::eligibility($authorId, $sellerId);
        if (!$e['can']) {
            throw new HttpException(match ($e['reason']) {
                'self'    => 'Нельзя оставить отзыв самому себе',
                'no_chat' => 'Отзыв можно оставить после общения с продавцом в чате',
                default   => 'Войдите в аккаунт, чтобы оставить отзыв',
            }, $e['reason'] === 'login' ? 401 : 403);
        }
        $body = trim($body);
        $errors = [];
        if ($rating < 1 || $rating > 5) {
            $errors['rating'] = 'Поставьте оценку от 1 до 5';
        }
        if (mb_strlen($body) < 5) {
            $errors['body'] = 'Напишите хотя бы пару слов (от 5 символов)';
        } elseif (mb_strlen($body) > 1000) {
            $errors['body'] = 'Максимум 1000 символов';
        }
        if ($errors) {
            throw new HttpException('Проверьте правильность заполнения полей', 422, $errors);
        }
        Database::run(
            'INSERT INTO reviews (seller_id, author_id, rating, body) VALUES (?, ?, ?, ?)
             ON CONFLICT (seller_id, author_id) DO UPDATE SET rating = EXCLUDED.rating, body = EXCLUDED.body, updated_at = NOW()',
            [$sellerId, $authorId, $rating, $body]
        );
    }

    /** Удаление: автором отзыва или администратором. */
    public static function delete(int $id, array $actor): ?array
    {
        $r = Database::one('SELECT id, seller_id, author_id FROM reviews WHERE id = ?', [$id]);
        if ($r === null) {
            throw new HttpException('Отзыв не найден', 404);
        }
        if ((int) $r['author_id'] !== (int) $actor['id'] && $actor['role'] !== 'admin') {
            throw new HttpException('Недостаточно прав', 403);
        }
        Database::run('DELETE FROM reviews WHERE id = ?', [$id]);
        return $r;
    }
}
