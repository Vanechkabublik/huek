<?php
declare(strict_types=1);

namespace App;

/**
 * Звонки (голос и видео) между покупателем и продавцом.
 *
 * Сами аудио и видео идут по WebRTC напрямую между браузерами (или через TURN-сервер, если прямое соединение
 * невозможно). Сервер сайта занят только «сигнализацией»: передаёт собеседнику предложение звонка, ответ и сетевые
 * адреса — это делает CallManager внутри WebSocket-сервера. Здесь — правила, настройки ICE и запись итога звонка в переписку.
 *
 * Переменные окружения:
 *   TURN_SECRET        общий секрет с coturn (use-auth-secret); пусто — используется только публичный STUN Google
 *   TURN_HOST          адрес TURN-сервера (по умолчанию — хост из APP_URL), TURN_PORT — порт (3478)
 *   CALLS_RELAY_ONLY   1 — всегда гнать звонок через TURN: собеседник не увидит IP-адрес, но задержка выше
 *   CALL_RING_SECONDS  сколько звонит вызов, по умолчанию 40
 */
final class Calls
{
    private const CRED_TTL = 86400;

    public static function ringSeconds(): int
    {
        $n = (int) getenv('CALL_RING_SECONDS');
        return $n >= 3 ? $n : 40;
    }

    /**
     * Настройки для RTCPeerConnection. Логин TURN временный: «срок:id пользователя», пароль — HMAC-SHA1 от логина
     * с общим секретом (REST API coturn), поэтому постоянных паролей в браузере нет.
     * @return array{iceServers:array,policy:string}
     */
    public static function iceConfig(int $userId): array
    {
        $secret = (string) getenv('TURN_SECRET');
        $host = (string) (getenv('TURN_HOST') ?: parse_url(OAuth::appUrl(), PHP_URL_HOST));
        $port = (int) (getenv('TURN_PORT') ?: 3478);
        if ($secret !== '' && $host !== '') {
            $user = (time() + self::CRED_TTL) . ':' . $userId;
            $servers = [
                ['urls' => ["stun:$host:$port"]],
                [
                    'urls'       => ["turn:$host:$port?transport=udp", "turn:$host:$port?transport=tcp"],
                    'username'   => $user,
                    'credential' => base64_encode(hash_hmac('sha1', $user, $secret, true)),
                ],
            ];
        } else {
            $servers = [['urls' => ['stun:stun.l.google.com:19302']]];
        }
        return ['iceServers' => $servers, 'policy' => getenv('CALLS_RELAY_ONLY') ? 'relay' : 'all'];
    }

    /** Можно ли звонить по объявлению: null — можно, иначе причина отказа. */
    public static function denyReason(array $ad): ?string
    {
        if (($ad['status'] ?? '') !== 'approved') {
            return 'Объявление недоступно';
        }
        if (($ad['contact_method'] ?? 'any') === 'chat') {
            return 'Автор принимает только сообщения';
        }
        return null;
    }

    /**
     * Проверяет, что пользователь может позвонить, и находит (при необходимости создаёт) диалог.
     * Звонок можно начать из чата (диалог уже есть) или со страницы объявления (диалога может ещё не быть).
     * @return array{conv:array,peer:array,ad:array}
     * @throws HttpException с понятным пользователю текстом
     */
    public static function prepare(int $userId, int $convId, int $adId): array
    {
        if ($convId > 0) {
            $conv = Chat::requireMember($convId, $userId);
            $adId = (int) $conv['ad_id'];
        }
        $ad = Database::one('SELECT id, user_id, title, status, contact_method FROM ads WHERE id = ?', [$adId]);
        if ($ad === null) {
            throw new HttpException('Объявление не найдено', 404);
        }
        if (($reason = self::denyReason($ad)) !== null) {
            throw new HttpException($reason, 403);
        }
        if ($convId <= 0) {
            if ((int) $ad['user_id'] === $userId) {
                throw new HttpException('Это ваше объявление', 403);
            }
            $existing = Chat::conversationIdForAd($adId, $userId);
            $conv = $existing !== null ? Chat::requireMember($existing, $userId) : [
                'id' => 0, 'ad_id' => $adId, 'buyer_id' => $userId, 'seller_id' => (int) $ad['user_id'],
            ];
        }
        $peerId = (int) $conv['buyer_id'] === $userId ? (int) $conv['seller_id'] : (int) $conv['buyer_id'];
        $peer = User::find($peerId);
        $me = User::find($userId);
        if ($peer === null || (int) $peer['is_blocked'] === 1) {
            throw new HttpException('Пользователь недоступен', 403);
        }
        if ($me === null || (int) $me['is_blocked'] === 1) {
            throw new HttpException('Аккаунт заблокирован', 403);
        }
        return ['conv' => $conv, 'peer' => $peer, 'ad' => $ad, 'me' => $me];
    }

    /** Диалог по объявлению: создаём, если ещё не было (для звонка со страницы объявления). */
    public static function ensureConversation(array $conv): int
    {
        if ((int) $conv['id'] > 0) {
            return (int) $conv['id'];
        }
        $existing = Chat::conversationIdForAd((int) $conv['ad_id'], (int) $conv['buyer_id']);
        return $existing ?? Database::insert(
            'INSERT INTO conversations (ad_id, buyer_id, seller_id) VALUES (?, ?, ?)',
            [(int) $conv['ad_id'], (int) $conv['buyer_id'], (int) $conv['seller_id']]
        );
    }

    /** Краткие данные о человеке для окна звонка. */
    public static function person(array $u): array
    {
        return [
            'id'      => (int) $u['id'],
            'name'    => $u['name'],
            'initial' => mb_strtoupper(mb_substr($u['name'], 0, 1)),
            'avatar'  => User::avatarUrl($u),
        ];
    }

    public static function duration(int $sec): string
    {
        return sprintf('%d:%02d', intdiv($sec, 60), $sec % 60);
    }

    /**
     * Записывает итог звонка в переписку (появляется у обоих участников сразу, по WebSocket).
     * @param string $state ended | declined | missed | cancelled | failed
     */
    public static function log(int $convId, int $callerId, string $state, int $duration, bool $video, ?string $reason = null): void
    {
        $body = match ($state) {
            'ended'    => ($video ? 'Видеозвонок' : 'Звонок') . ', ' . self::duration($duration),
            'declined' => 'Звонок отклонён',
            'failed'   => 'Не удалось соединиться',
            default    => 'Пропущенный звонок',
        };
        $meta = ['state' => $state, 'duration' => $duration, 'video' => $video] + ($reason !== null ? ['reason' => $reason] : []);
        // Пропущенные остаются «непрочитанными» у получателя, остальные — сразу прочитаны, чтобы не раздувать счётчик
        $read = in_array($state, ['ended', 'declined', 'failed'], true) ? 'NOW()' : 'NULL';
        $msgId = Database::insert(
            "INSERT INTO chat_messages (conversation_id, sender_id, body, kind, meta, read_at) VALUES (?, ?, ?, 'call', ?::jsonb, $read)",
            [$convId, $callerId, $body, json_encode($meta, JSON_UNESCAPED_UNICODE)]
        );
        Database::run('UPDATE conversations SET last_message_at = NOW() WHERE id = ?', [$convId]);
        $conv = Chat::participants($convId);
        if ($conv !== null) {
            $peer = (int) $conv['buyer_id'] === $callerId ? (int) $conv['seller_id'] : (int) $conv['buyer_id'];
            Realtime::publish('message', ['conv' => $convId, 'msg' => $msgId, 'from' => $callerId, 'to' => $peer]);
        }
    }
}
