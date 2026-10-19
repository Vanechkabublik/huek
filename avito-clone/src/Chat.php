<?php
declare(strict_types=1);

namespace App;

/**
 * Чат между покупателем и продавцом. Для каждой пары «объявление + покупатель» — один диалог
 * (цепочка сообщений). У каждого сообщения есть статус: read_at IS NULL — не прочитано.
 */
final class Chat
{
    public const MAX_BODY  = 2000;
    public const PER_PAGE  = 50;
    private const RATE_LIMIT = 20;      // сообщений в минуту от одного пользователя

    // ------------------------------------------------------------------ списки

    public static function unreadTotal(int $userId): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM chat_messages m JOIN conversations c ON c.id = m.conversation_id
             WHERE (c.buyer_id = $userId OR c.seller_id = $userId) AND m.sender_id <> $userId AND m.read_at IS NULL"
        );
    }

    private static function summarySql(int $me): string
    {
        return "SELECT c.id, c.ad_id, c.buyer_id, c.seller_id, c.last_message_at,
                       a.title AS ad_title, a.price AS ad_price, a.status AS ad_status,
                       (SELECT filename FROM ad_images WHERE ad_id = a.id ORDER BY id LIMIT 1) AS cover,
                       p.id AS peer_id, p.name AS peer_name, p.avatar_url AS peer_avatar,
                       lm.id AS last_id, lm.body AS last_body, lm.sender_id AS last_sender,
                       lm.created_at AS last_at, lm.read_at AS last_read,
                       (SELECT COUNT(*) FROM chat_messages m
                        WHERE m.conversation_id = c.id AND m.sender_id <> $me AND m.read_at IS NULL) AS unread
                FROM conversations c
                JOIN ads a ON a.id = c.ad_id
                JOIN users p ON p.id = CASE WHEN c.buyer_id = $me THEN c.seller_id ELSE c.buyer_id END
                LEFT JOIN LATERAL (
                    SELECT id, body, sender_id, created_at, read_at FROM chat_messages
                    WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1
                ) lm ON TRUE";
    }

    /** @return array<int,array<string,mixed>> диалоги пользователя, свежие сверху */
    public static function conversations(int $userId): array
    {
        $rows = Database::all(
            self::summarySql($userId) . " WHERE c.buyer_id = $userId OR c.seller_id = $userId ORDER BY c.last_message_at DESC, c.id DESC"
        );
        return array_map(static fn(array $r) => self::formatConversation($r, $userId), $rows);
    }

    /** Один диалог в виде строки списка (для push по WebSocket и после отправки). */
    public static function summary(int $convId, int $userId): ?array
    {
        $row = Database::one(
            self::summarySql($userId) . " WHERE c.id = ? AND (c.buyer_id = $userId OR c.seller_id = $userId)",
            [$convId]
        );
        return $row ? self::formatConversation($row, $userId) : null;
    }

    /** @return array<string,mixed> */
    private static function formatConversation(array $r, int $me): array
    {
        $last = null;
        if ($r['last_id'] !== null) {
            $last = [
                'id'        => (int) $r['last_id'],
                'body'      => mb_strlen($r['last_body']) > 140 ? mb_substr($r['last_body'], 0, 140) . '…' : $r['last_body'],
                'mine'      => (int) $r['last_sender'] === $me,
                'at'        => Format::iso($r['last_at']),
                'time_text' => Format::short($r['last_at']),
                'read'      => $r['last_read'] !== null,
            ];
        }
        return [
            'id'      => (int) $r['id'],
            'role'    => (int) $r['buyer_id'] === $me ? 'buyer' : 'seller',
            'ad'      => [
                'id'         => (int) $r['ad_id'],
                'title'      => $r['ad_title'],
                'price_text' => Format::price((int) $r['ad_price']),
                'cover'      => !empty($r['cover']) ? Upload::thumbUrl($r['cover']) : null,
                'status'     => $r['ad_status'],
                'url'        => '/ad.php?id=' . (int) $r['ad_id'],
            ],
            'peer'    => [
                'id'      => (int) $r['peer_id'],
                'name'    => $r['peer_name'],
                'initial' => mb_strtoupper(mb_substr($r['peer_name'], 0, 1)),
                'avatar'  => $r['peer_avatar'] ?: null,
                'url'     => '/seller.php?id=' . (int) $r['peer_id'],
            ],
            'last'    => $last,
            'unread'  => (int) $r['unread'],
            'updated' => Format::iso($r['last_message_at']),
        ];
    }

    // -------------------------------------------------------------- диалог

    /** Участники диалога или null. */
    public static function participants(int $convId): ?array
    {
        return Database::one('SELECT id, ad_id, buyer_id, seller_id FROM conversations WHERE id = ?', [$convId]);
    }

    /** Проверяет, что пользователь — участник диалога. @throws HttpException */
    public static function requireMember(int $convId, int $userId): array
    {
        $c = self::participants($convId);
        if ($c === null) {
            throw new HttpException('Диалог не найден', 404);
        }
        if ((int) $c['buyer_id'] !== $userId && (int) $c['seller_id'] !== $userId) {
            throw new HttpException('Недостаточно прав', 403);
        }
        return $c;
    }

    /** id диалога покупателя по объявлению (или null, если ещё не писал). */
    public static function conversationIdForAd(int $adId, int $buyerId): ?int
    {
        $id = Database::value('SELECT id FROM conversations WHERE ad_id = ? AND buyer_id = ?', [$adId, $buyerId]);
        return $id !== null ? (int) $id : null;
    }

    /**
     * Описание «ещё не начатого» диалога — для chat.php?ad=ID (покупатель открыл чат с продавцом, но ничего не написал).
     * @return array<string,mixed>
     */
    public static function draftForAd(int $adId, int $userId): array
    {
        $ad = Database::one(
            "SELECT a.id, a.user_id, a.title, a.price, a.status, a.contact_method,
                    (SELECT filename FROM ad_images WHERE ad_id = a.id ORDER BY id LIMIT 1) AS cover,
                    u.id AS peer_id, u.name AS peer_name, u.avatar_url AS peer_avatar
             FROM ads a JOIN users u ON u.id = a.user_id WHERE a.id = ?",
            [$adId]
        );
        if ($ad === null || $ad['status'] !== 'approved') {
            throw new HttpException('Объявление недоступно для переписки', 404);
        }
        if ((int) $ad['user_id'] === $userId) {
            throw new HttpException('Это ваше объявление', 422);
        }
        if ($ad['contact_method'] === 'phone') {
            throw new HttpException('Автор принимает только звонки — нажмите «Показать телефон» в объявлении', 403);
        }
        return [
            'id'     => null,
            'role'   => 'buyer',
            'ad'     => [
                'id'         => (int) $ad['id'],
                'title'      => $ad['title'],
                'price_text' => Format::price((int) $ad['price']),
                'cover'      => !empty($ad['cover']) ? Upload::thumbUrl($ad['cover']) : null,
                'status'     => $ad['status'],
                'url'        => '/ad.php?id=' . (int) $ad['id'],
            ],
            'peer'   => [
                'id'      => (int) $ad['peer_id'],
                'name'    => $ad['peer_name'],
                'initial' => mb_strtoupper(mb_substr($ad['peer_name'], 0, 1)),
                'avatar'  => $ad['peer_avatar'] ?: null,
                'url'     => '/seller.php?id=' . (int) $ad['peer_id'],
            ],
            'last'   => null,
            'unread' => 0,
        ];
    }

    /**
     * Сообщения диалога (старые → новые). $before — id сообщения, выше которого подгружаем историю.
     * @return array{messages:array,has_more:bool}
     */
    public static function messages(int $convId, int $userId, ?int $before = null, int $limit = self::PER_PAGE): array
    {
        self::requireMember($convId, $userId);
        $limit = max(1, min(100, $limit));
        $params = [$convId];
        $cond = '';
        if ($before !== null) {
            $cond = ' AND id < ?';
            $params[] = $before;
        }
        $rows = Database::all(
            "SELECT id, conversation_id, sender_id, body, created_at, read_at FROM chat_messages
             WHERE conversation_id = ?$cond ORDER BY id DESC LIMIT " . ($limit + 1),
            $params
        );
        $more = count($rows) > $limit;
        $rows = array_reverse(array_slice($rows, 0, $limit));
        return ['messages' => array_map([self::class, 'formatMessage'], $rows), 'has_more' => $more];
    }

    /** @return array<string,mixed> */
    public static function formatMessage(array $r): array
    {
        return [
            'id'              => (int) $r['id'],
            'conversation_id' => (int) $r['conversation_id'],
            'sender_id'       => (int) $r['sender_id'],
            'body'            => $r['body'],
            'created_at'      => Format::iso($r['created_at']),
            'read_at'         => Format::iso($r['read_at']),
            'read'            => $r['read_at'] !== null,
        ];
    }

    public static function messageById(int $id): ?array
    {
        $r = Database::one('SELECT id, conversation_id, sender_id, body, created_at, read_at FROM chat_messages WHERE id = ?', [$id]);
        return $r ? self::formatMessage($r) : null;
    }

    // ------------------------------------------------------------ отправка

    /**
     * Отправляет сообщение. Покупатель может начать диалог по объявлению ($adId);
     * продавец отвечает только в существующем ($convId).
     *
     * @return array{conversation:array,message:array,created:bool}
     */
    public static function send(int $userId, ?int $convId, ?int $adId, string $body): array
    {
        $body = trim(str_replace("\r\n", "\n", $body));
        if ($body === '') {
            throw new HttpException('Введите текст сообщения', 422, ['body' => 'Введите текст сообщения']);
        }
        if (mb_strlen($body) > self::MAX_BODY) {
            throw new HttpException('Сообщение слишком длинное', 422, ['body' => 'Максимум ' . self::MAX_BODY . ' символов']);
        }
        $recent = (int) Database::value(
            "SELECT COUNT(*) FROM chat_messages WHERE sender_id = ? AND created_at > NOW() - INTERVAL '1 minute'",
            [$userId]
        );
        if ($recent >= self::RATE_LIMIT) {
            throw new HttpException('Слишком много сообщений подряд. Подождите минуту.', 429);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $created = false;
            if ($convId !== null) {
                $conv = self::requireMember($convId, $userId);
            } else {
                $ad = Database::one('SELECT id, user_id, status, contact_method FROM ads WHERE id = ?', [(int) $adId]);
                if ($ad === null || $ad['status'] !== 'approved') {
                    throw new HttpException('Объявление недоступно для переписки', 404);
                }
                if ((int) $ad['user_id'] === $userId) {
                    throw new HttpException('Нельзя написать самому себе', 403);
                }
                $existing = self::conversationIdForAd((int) $ad['id'], $userId);
                if ($existing === null) {
                    if ($ad['contact_method'] === 'phone') {
                        throw new HttpException('Автор принимает только звонки', 403);
                    }
                    $created = true;
                    $existing = Database::insert(
                        'INSERT INTO conversations (ad_id, buyer_id, seller_id) VALUES (?, ?, ?)',
                        [(int) $ad['id'], $userId, (int) $ad['user_id']]
                    );
                }
                $conv = self::requireMember($existing, $userId);
            }

            $msgId = Database::insert(
                'INSERT INTO chat_messages (conversation_id, sender_id, body) VALUES (?, ?, ?)',
                [(int) $conv['id'], $userId, $body]
            );
            Database::run('UPDATE conversations SET last_message_at = NOW() WHERE id = ?', [(int) $conv['id']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $peer = (int) $conv['buyer_id'] === $userId ? (int) $conv['seller_id'] : (int) $conv['buyer_id'];
        Realtime::publish('message', ['conv' => (int) $conv['id'], 'msg' => $msgId, 'from' => $userId, 'to' => $peer]);

        return [
            'conversation' => self::summary((int) $conv['id'], $userId),
            'message'      => self::messageById($msgId),
            'created'      => $created,
        ];
    }

    /**
     * Помечает входящие сообщения диалога прочитанными. Автору сообщений прилетает статус «прочитано» по WebSocket.
     * @return int сколько сообщений стало прочитанными
     */
    public static function markRead(int $convId, int $userId): int
    {
        $conv = self::requireMember($convId, $userId);
        $rows = Database::all(
            'UPDATE chat_messages SET read_at = NOW()
             WHERE conversation_id = ? AND sender_id <> ? AND read_at IS NULL RETURNING id',
            [$convId, $userId]
        );
        if (!$rows) {
            return 0;
        }
        $peer = (int) $conv['buyer_id'] === $userId ? (int) $conv['seller_id'] : (int) $conv['buyer_id'];
        Realtime::publish('read', [
            'conv'   => $convId,
            'reader' => $userId,
            'peer'   => $peer,
            'upto'   => max(array_map('intval', array_column($rows, 'id'))),
        ]);
        return count($rows);
    }
}
