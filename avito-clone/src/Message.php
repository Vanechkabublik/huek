<?php
declare(strict_types=1);

namespace App;

/**
 * Сообщения «покупатель → продавец» с кнопки «Написать сообщение».
 */
final class Message
{
    /**
     * Отправляет сообщение. Покупатель пишет владельцу объявления;
     * владелец может ответить только тому, кто ему уже писал по этому объявлению.
     */
    public static function send(int $adId, array $from, string $body, ?int $toUserId = null): void
    {
        $body = trim($body);
        if (mb_strlen($body) < 2) {
            throw new HttpException('Введите текст сообщения', 422, ['body' => 'Введите текст сообщения']);
        }
        if (mb_strlen($body) > 2000) {
            throw new HttpException('Сообщение слишком длинное', 422, ['body' => 'Максимум 2000 символов']);
        }

        $ad = Database::one('SELECT id, user_id, status FROM ads WHERE id = ?', [$adId]);
        if ($ad === null) {
            throw new HttpException('Объявление не найдено', 404);
        }
        $fromId  = (int) $from['id'];
        $ownerId = (int) $ad['user_id'];

        if ($fromId === $ownerId) {
            // Владелец отвечает одному из своих собеседников
            $hasThread = $toUserId !== null && Database::value(
                'SELECT 1 FROM messages WHERE ad_id = ? AND from_user_id = ? AND to_user_id = ? LIMIT 1',
                [$adId, $toUserId, $ownerId]
            ) !== null;
            if (!$hasThread) {
                throw new HttpException('Нельзя написать самому себе', 403);
            }
            $recipient = $toUserId;
        } else {
            if ($ad['status'] !== 'approved') {
                throw new HttpException('Объявление недоступно', 404);
            }
            $recipient = $ownerId;
        }

        Database::run(
            'INSERT INTO messages (ad_id, from_user_id, to_user_id, body) VALUES (?, ?, ?, ?)',
            [$adId, $fromId, $recipient, $body]
        );
    }

    public static function unreadCount(int $userId): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM messages WHERE to_user_id = ? AND is_read = 0', [$userId]);
    }

    /**
     * Входящие или исходящие сообщения пользователя. Открытие «входящих» помечает их прочитанными.
     * @return array<int,array<string,mixed>>
     */
    public static function box(int $userId, string $box): array
    {
        $incoming = $box !== 'sent';
        $rows = Database::all(
            'SELECT m.id, m.ad_id, m.body, m.is_read, m.created_at, m.from_user_id, m.to_user_id,
                    a.title AS ad_title, a.user_id AS ad_owner_id,
                    uf.name AS from_name, ut.name AS to_name
             FROM messages m
             JOIN ads a ON a.id = m.ad_id
             JOIN users uf ON uf.id = m.from_user_id
             JOIN users ut ON ut.id = m.to_user_id
             WHERE ' . ($incoming ? 'm.to_user_id' : 'm.from_user_id') . ' = ?
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT 200',
            [$userId]
        );

        if ($incoming) {
            Database::run('UPDATE messages SET is_read = 1 WHERE to_user_id = ? AND is_read = 0', [$userId]);
        }

        return array_map(static fn(array $r) => [
            'id'         => (int) $r['id'],
            'ad_id'      => (int) $r['ad_id'],
            'ad_title'   => $r['ad_title'],
            'ad_url'     => '/ad.php?id=' . (int) $r['ad_id'],
            'body'       => $r['body'],
            'is_read'    => (bool) $r['is_read'],
            'date_text'  => Format::date($r['created_at']),
            'peer_id'    => (int) ($incoming ? $r['from_user_id'] : $r['to_user_id']),
            'peer_name'  => $incoming ? $r['from_name'] : $r['to_name'],
            'can_reply'  => $incoming,
        ], $rows);
    }
}
