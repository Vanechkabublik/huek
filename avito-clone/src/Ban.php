<?php
declare(strict_types=1);

namespace App;

/**
 * Блокировки: аккаунта (с причиной и сроком) и IP-адресов (адрес или сеть CIDR).
 *
 * Бан по IP бывает двух видов: «auth» — закрыты вход и регистрация (мягкий, подходит для общих адресов вроде мобильных сетей),
 * «site» — закрыт весь сайт, кроме администраторов, у которых уже есть сессия.
 */
final class Ban
{
    /** Причина по умолчанию, если админ ничего не написал. */
    private const NO_REASON = 'нарушение правил сайта';

    /** @var array<string,array<string,mixed>|null> */
    private static array $ipCache = [];

    /** Снимает истёкшие блокировки и изредка чистит старый журнал входов. Дёшево: работает по частичному индексу. */
    public static function sweep(bool $force = false): void
    {
        // Не чаще раза в минуту (по метке во временной папке), если не просили принудительно
        $mark = sys_get_temp_dir() . '/board_ban_sweep';
        if (!$force && @filemtime($mark) > time() - 60) {
            return;
        }
        @touch($mark);
        try {
            Database::run(
                "UPDATE users SET is_blocked = 0, ban_reason = NULL, banned_at = NULL, banned_until = NULL, banned_by = NULL
                 WHERE banned_until IS NOT NULL AND banned_until <= NOW()"
            );
            Database::run('DELETE FROM ip_bans WHERE expires_at IS NOT NULL AND expires_at <= NOW()');
            if (random_int(1, 300) === 1) {
                Database::run("DELETE FROM login_log WHERE created_at < NOW() - INTERVAL '180 days'");
                Database::run("DELETE FROM geo_cache WHERE created_at < NOW() - INTERVAL '90 days'");
            }
        } catch (\Throwable $e) {
            error_log('Ban::sweep failed: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ IP

    /**
     * Действующий бан для адреса или null.
     * @param string[] $scopes какие виды банов учитывать
     * @return array<string,mixed>|null
     */
    public static function matchIp(string $ip, array $scopes = ['auth', 'site']): ?array
    {
        $key = $ip . '|' . implode(',', $scopes);
        if (array_key_exists($key, self::$ipCache)) {
            return self::$ipCache[$key];
        }
        $found = null;
        try {
            $rows = Database::all(
                'SELECT id, ip, reason, scope, expires_at FROM ip_bans WHERE (expires_at IS NULL OR expires_at > NOW()) AND scope = ANY(?::text[])',
                ['{' . implode(',', $scopes) . '}']
            );
            foreach ($rows as $r) {
                if (Client::inRange($ip, $r['ip'])) {
                    $found = $r;
                    break;
                }
            }
        } catch (\Throwable $e) {
            error_log('Ban::matchIp failed: ' . $e->getMessage());
        }
        return self::$ipCache[$key] = $found;
    }

    /** Сообщение для заблокированного адреса. */
    public static function ipMessage(array $ban): string
    {
        $reason = trim((string) $ban['reason']) !== '' ? trim((string) $ban['reason']) : self::NO_REASON;
        return 'Доступ с вашего IP-адреса ограничен. Причина: ' . $reason . self::untilText($ban['expires_at'] ?? null, 'ограничение действует');
    }

    /** Закрывает вход и регистрацию для забаненного адреса (любой вид бана). */
    public static function assertIpAllowed(?string $loginForLog = null): void
    {
        $ban = self::matchIp(Client::ip());
        if ($ban !== null) {
            Security::log('ip_banned', null, null, $loginForLog);
            throw new HttpException(self::ipMessage($ban), 403);
        }
    }

    /** Полная блокировка сайта для адреса (бан вида «site»); вызывается из bootstrap. */
    public static function enforceSite(): void
    {
        $ban = self::matchIp(Client::ip(), ['site']);
        if ($ban === null || Auth::isAdmin()) {
            return;
        }
        $message = self::ipMessage($ban);
        http_response_code(403);
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (str_starts_with($uri, '/api/')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<title>Доступ ограничен</title><body style="font-family:system-ui,sans-serif;max-width:560px;margin:15vh auto;padding:0 20px;color:#1f2937">'
                . '<h1 style="font-size:22px">Доступ ограничен</h1><p style="line-height:1.6">' . e($message) . '</p></body></html>';
        }
        exit;
    }

    // ------------------------------------------------------------------ аккаунт

    /** Текст для заблокированного пользователя: причина и срок. */
    public static function userMessage(array $user): string
    {
        $reason = trim((string) ($user['ban_reason'] ?? '')) !== '' ? trim((string) $user['ban_reason']) : self::NO_REASON;
        return 'Ваш аккаунт заблокирован. Причина: ' . $reason . self::untilText($user['banned_until'] ?? null, 'блокировка действует');
    }

    private static function untilText(?string $until, string $verb): string
    {
        if ($until === null || $until === '') {
            return '.';
        }
        return '. Срок: ' . $verb . ' до ' . date('d.m.Y H:i', (int) strtotime($until)) . '.';
    }

    /**
     * Блокирует пользователя.
     * @param ?string $until дата окончания или null — бессрочно
     * @return string[] IP-адреса, которые заблокированы вместе с аккаунтом
     */
    public static function banUser(int $userId, string $reason, ?string $until, array $actor, bool $alsoIps = false): array
    {
        $reason = mb_substr(trim($reason), 0, 500);
        Database::run(
            'UPDATE users SET is_blocked = 1, ban_reason = ?, banned_at = NOW(), banned_until = ?, banned_by = ? WHERE id = ?',
            [$reason !== '' ? $reason : null, $until, (int) $actor['id'], $userId]
        );
        $banned = [];
        if ($alsoIps) {
            $u = Database::one('SELECT signup_ip, last_ip FROM users WHERE id = ?', [$userId]) ?? [];
            foreach (array_unique(array_filter([$u['last_ip'] ?? null, $u['signup_ip'] ?? null])) as $ip) {
                if ($ip === Client::ip() || self::isStaffIp($ip)) {
                    continue;       // не баним адрес самого админа (и других админов) случайно
                }
                self::addIp($ip, $reason !== '' ? $reason : self::NO_REASON, 'auth', $until, $actor);
                $banned[] = $ip;
            }
        }
        return $banned;
    }

    public static function unbanUser(int $userId): void
    {
        Database::run(
            'UPDATE users SET is_blocked = 0, ban_reason = NULL, banned_at = NULL, banned_until = NULL, banned_by = NULL WHERE id = ?',
            [$userId]
        );
    }

    /** Пользователи с ролью admin входили с этого адреса — его трогать нельзя. */
    private static function isStaffIp(string $ip): bool
    {
        return Database::value("SELECT 1 FROM users WHERE role = 'admin' AND (last_ip = ? OR signup_ip = ?) LIMIT 1", [$ip, $ip]) !== null;
    }

    // ------------------------------------------------------------------ управление IP-банами

    /** @return array<int,array<string,mixed>> */
    public static function listIps(): array
    {
        $rows = Database::all(
            "SELECT b.id, b.ip, b.reason, b.scope, b.created_at, b.expires_at, u.name AS by_name
             FROM ip_bans b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.id DESC"
        );
        return array_map(static fn(array $r) => [
            'id'         => (int) $r['id'],
            'ip'         => $r['ip'],
            'reason'     => $r['reason'],
            'scope'      => $r['scope'],
            'by'         => $r['by_name'] ?? '—',
            'created_at' => Format::dateFull($r['created_at']),
            'expires_at' => $r['expires_at'] ? Format::dateFull($r['expires_at']) : null,
        ], $rows);
    }

    public static function addIp(string $range, string $reason, string $scope, ?string $until, array $actor): int
    {
        $range = trim($range);
        if (!Client::validRange($range)) {
            throw new HttpException('Введите IP-адрес (1.2.3.4) или сеть (1.2.3.0/24)', 422, ['ip' => 'Некорректный IP-адрес или сеть']);
        }
        if (!in_array($scope, ['auth', 'site'], true)) {
            throw new HttpException('Недопустимый вид блокировки', 422);
        }
        // Нельзя запереть самого себя
        if (Client::inRange(Client::ip(), $range)) {
            throw new HttpException('Нельзя заблокировать собственный адрес (' . Client::ip() . ')', 422, ['ip' => 'Это ваш текущий адрес']);
        }
        [$net, $bits] = array_pad(explode('/', $range, 2), 2, null);
        $range = (string) Client::normalize((string) $net) . ($bits !== null ? '/' . (int) $bits : '');
        $id = Database::insert(
            'INSERT INTO ip_bans (ip, reason, scope, created_by, expires_at) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (ip) DO UPDATE SET reason = EXCLUDED.reason, scope = EXCLUDED.scope, created_by = EXCLUDED.created_by,
                                            created_at = NOW(), expires_at = EXCLUDED.expires_at',
            [$range, mb_substr(trim($reason), 0, 500), $scope, (int) $actor['id'], $until]
        );
        self::$ipCache = [];
        return $id;
    }

    public static function removeIp(int $id): ?string
    {
        $ip = Database::value('SELECT ip FROM ip_bans WHERE id = ?', [$id]);
        Database::run('DELETE FROM ip_bans WHERE id = ?', [$id]);
        self::$ipCache = [];
        return $ip === null ? null : (string) $ip;
    }

    /**
     * Срок блокировки из формы админки: forever | 1d | 7d | 30d | 'YYYY-MM-DD'.
     */
    public static function until(string $duration): ?string
    {
        $map = ['1d' => '+1 day', '7d' => '+7 days', '30d' => '+30 days'];
        if ($duration === '' || $duration === 'forever') {
            return null;
        }
        if (isset($map[$duration])) {
            return date('Y-m-d H:i:s', strtotime($map[$duration]));
        }
        $t = strtotime($duration . ' 23:59:59');
        if ($t === false || $t <= time()) {
            throw new HttpException('Дата окончания блокировки должна быть в будущем', 422, ['duration' => 'Укажите дату в будущем']);
        }
        return date('Y-m-d H:i:s', $t);
    }
}
