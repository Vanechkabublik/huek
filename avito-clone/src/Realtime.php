<?php
declare(strict_types=1);

namespace App;

/**
 * Связка PHP-приложения с WebSocket-сервером (bin/ws-server.php):
 *  • publish() — после записи в БД шлёт событие через PostgreSQL NOTIFY; WS-сервер слушает канал и
 *    рассылает его подключённым клиентам;
 *  • ticket()/verifyTicket() — короткоживущий подписанный тикет, которым браузер авторизуется в WebSocket
 *    (cookie сессии в WS-контейнере недоступны, поэтому тикет подписывается общим секретом APP_SECRET).
 */
final class Realtime
{
    public const CHANNEL    = 'chat_events';
    private const TICKET_TTL = 90;

    /** @param array<string,mixed> $payload небольшой JSON (< 8000 байт): только идентификаторы */
    public static function publish(string $type, array $payload): void
    {
        try {
            Database::run('SELECT pg_notify(?, ?)', [self::CHANNEL, json_encode(['type' => $type] + $payload, JSON_UNESCAPED_UNICODE)]);
        } catch (\Throwable $e) {
            // Realtime — «приятное дополнение»: если NOTIFY не прошёл, сообщение всё равно сохранено
            error_log('Realtime publish failed: ' . $e->getMessage());
        }
    }

    private static function secret(): string
    {
        return (string) (getenv('APP_SECRET') ?: 'dev-secret-change-me');
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function ticket(int $userId): string
    {
        $payload = self::b64(json_encode(['u' => $userId, 'e' => time() + self::TICKET_TTL]));
        return $payload . '.' . self::b64(hash_hmac('sha256', $payload, self::secret(), true));
    }

    /** @return int|null id пользователя или null, если тикет неверный/просрочен */
    public static function verifyTicket(string $ticket): ?int
    {
        $parts = explode('.', $ticket);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload, $sig] = $parts;
        if (!hash_equals(self::b64(hash_hmac('sha256', $payload, self::secret(), true)), $sig)) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        if (!is_array($data) || ($data['e'] ?? 0) < time() || ($data['u'] ?? 0) < 1) {
            return null;
        }
        return (int) $data['u'];
    }

    /** Публичный адрес WebSocket для браузера ('' — JS возьмёт ws://<хост>:<порт WS>). */
    public static function publicUrl(): string
    {
        return (string) getenv('WS_PUBLIC_URL');
    }

    public static function publicPort(): int
    {
        return (int) (getenv('WS_PORT') ?: 8081);
    }
}
