<?php
declare(strict_types=1);

namespace App;

/**
 * Журнал входов: кто, когда, с какого IP и браузера. Нужен для безопасности (баны) и аналитики устройств.
 */
final class Security
{
    /**
     * @param string  $event    register | login | login_failed | banned | ip_banned
     * @param ?string $provider email | google | yandex
     */
    public static function log(string $event, ?int $userId = null, ?string $provider = null, ?string $login = null): void
    {
        try {
            Database::run(
                'INSERT INTO login_log (user_id, event, provider, login, ip, ua) VALUES (?, ?, ?, ?, ?, ?)',
                [$userId, $event, $provider, $login !== null ? mb_substr($login, 0, 255) : null, Client::ip(), Client::userAgent()]
            );
        } catch (\Throwable $e) {
            error_log('Security::log failed: ' . $e->getMessage());
        }
    }

    /**
     * Журнал с фильтрами.
     * @return array{items:array,total:int,has_more:bool}
     */
    public static function list(string $event = '', string $ip = '', int $userId = 0, int $page = 1, int $perPage = 30): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($event !== '') {
            $where[] = 'l.event = ?';
            $params[] = $event;
        }
        if ($ip !== '') {
            $where[] = 'l.ip = ?';
            $params[] = $ip;
        }
        if ($userId > 0) {
            $where[] = 'l.user_id = ?';
            $params[] = $userId;
        }
        $w = implode(' AND ', $where);
        $total = (int) Database::value("SELECT COUNT(*) FROM login_log l WHERE $w", $params);
        $offset = (max(1, $page) - 1) * $perPage;
        $rows = Database::all(
            "SELECT l.*, u.name AS user_name, u.email AS user_email
             FROM login_log l LEFT JOIN users u ON u.id = l.user_id
             WHERE $w ORDER BY l.id DESC LIMIT $perPage OFFSET $offset",
            $params
        );
        return [
            'items'    => array_map([self::class, 'row'], $rows),
            'total'    => $total,
            'has_more' => $offset + count($rows) < $total,
        ];
    }

    /** @return array<string,mixed> */
    private static function row(array $r): array
    {
        $ua = Client::parseUa((string) $r['ua']);
        return [
            'id'         => (int) $r['id'],
            'event'      => $r['event'],
            'provider'   => $r['provider'],
            'user_id'    => $r['user_id'] !== null ? (int) $r['user_id'] : null,
            'user'       => $r['user_name'],
            'email'      => $r['user_email'] ?? $r['login'],
            'ip'         => $r['ip'],
            'ua'         => $r['ua'],
            'browser'    => $ua['browser'],
            'os'         => $ua['os'],
            'device'     => $ua['device'],
            'created_at' => Format::dateFull($r['created_at']),
        ];
    }

    /**
     * Сводка устройств и подозрительных адресов за 30 дней.
     * @return array<string,mixed>
     */
    public static function summary(): array
    {
        $rows = Database::all(
            "SELECT ua, COUNT(*) AS n FROM login_log WHERE event IN ('login', 'register') AND created_at > NOW() - INTERVAL '30 days' GROUP BY ua"
        );
        $browsers = $systems = $devices = [];
        foreach ($rows as $r) {
            $p = Client::parseUa((string) $r['ua']);
            $n = (int) $r['n'];
            $browsers[$p['browser']] = ($browsers[$p['browser']] ?? 0) + $n;
            $systems[$p['os']] = ($systems[$p['os']] ?? 0) + $n;
            $devices[$p['device']] = ($devices[$p['device']] ?? 0) + $n;
        }
        $top = static function (array $a): array {
            arsort($a);
            $out = [];
            foreach (array_slice($a, 0, 6, true) as $name => $n) {
                $out[] = ['name' => $name, 'count' => $n];
            }
            return $out;
        };
        $suspicious = Database::all(
            "SELECT ip, COUNT(*) AS fails, COUNT(DISTINCT login) AS logins, MAX(created_at) AS last_at
             FROM login_log WHERE event = 'login_failed' AND created_at > NOW() - INTERVAL '24 hours'
             GROUP BY ip HAVING COUNT(*) >= 5 ORDER BY fails DESC LIMIT 10"
        );
        $shared = Database::all(
            "SELECT signup_ip AS ip, COUNT(*) AS users FROM users WHERE signup_ip IS NOT NULL
             GROUP BY signup_ip HAVING COUNT(*) >= 3 ORDER BY users DESC LIMIT 10"
        );
        return [
            'browsers'   => $top($browsers),
            'systems'    => $top($systems),
            'devices'    => $top($devices),
            'suspicious' => array_map(static fn(array $r) => ['ip' => $r['ip'], 'fails' => (int) $r['fails'], 'logins' => (int) $r['logins'], 'last_at' => Format::dateFull($r['last_at'])], $suspicious),
            'shared'     => array_map(static fn(array $r) => ['ip' => $r['ip'], 'users' => (int) $r['users']], $shared),
            'logins_30d' => (int) Database::value("SELECT COUNT(*) FROM login_log WHERE event IN ('login','register') AND created_at > NOW() - INTERVAL '30 days'"),
        ];
    }
}
