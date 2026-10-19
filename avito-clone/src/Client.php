<?php
declare(strict_types=1);

namespace App;

/**
 * Сведения о посетителе: реальный IP-адрес и User-Agent.
 *
 * За обратным прокси (nginx/Caddy) REMOTE_ADDR — это адрес самого прокси, а настоящий адрес приходит в X-Forwarded-For.
 * Этому заголовку доверяем только если запрос пришёл с доверенного прокси (по умолчанию — loopback и частные сети,
 * в том числе docker-сеть; свой список — переменная TRUSTED_PROXIES через запятую). Цепочку читаем справа налево
 * и берём первый адрес, не принадлежащий доверенному прокси: так клиент не может подделать свой IP, дописав заголовок сам.
 */
final class Client
{
    private const DEFAULT_TRUSTED = '127.0.0.0/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,::1/128,fc00::/7';

    private static ?string $ip = null;
    /** @var string[]|null */
    private static ?array $trusted = null;

    public static function ip(): string
    {
        if (self::$ip === null) {
            self::$ip = self::detectIp();
        }
        return self::$ip;
    }

    /** User-Agent без управляющих символов, не длиннее 255 знаков. */
    public static function userAgent(): string
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ua = preg_replace('/[\x00-\x1F\x7F]/u', '', $ua) ?? '';
        return mb_substr($ua, 0, 255);
    }

    private static function detectIp(): string
    {
        $remote = self::normalize((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remote === null) {
            return '0.0.0.0';
        }
        if (!self::isTrusted($remote)) {
            return $remote;
        }
        $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            foreach (array_reverse(array_map('trim', explode(',', $xff))) as $candidate) {
                $ip = self::normalize($candidate);
                if ($ip !== null && !self::isTrusted($ip)) {
                    return $ip;
                }
            }
        }
        $real = self::normalize((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        return $real ?? $remote;
    }

    /** Каноническая запись адреса или null, если это не IP. */
    public static function normalize(string $ip): ?string
    {
        $ip = trim($ip);
        // IPv4, присланный как ::ffff:1.2.3.4
        if (str_starts_with(strtolower($ip), '::ffff:') && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = substr($ip, 7);
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        $bin = @inet_pton($ip);
        return $bin === false ? null : (string) inet_ntop($bin);
    }

    private static function isTrusted(string $ip): bool
    {
        if (self::$trusted === null) {
            $list = (string) (getenv('TRUSTED_PROXIES') ?: self::DEFAULT_TRUSTED);
            self::$trusted = array_values(array_filter(array_map('trim', explode(',', $list))));
        }
        foreach (self::$trusted as $cidr) {
            if (self::inRange($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /** Входит ли адрес в диапазон: одиночный адрес или сеть CIDR (IPv4 и IPv6). */
    public static function inRange(string $ip, string $range): bool
    {
        [$net, $bits] = array_pad(explode('/', $range, 2), 2, null);
        $a = @inet_pton($ip);
        $b = @inet_pton((string) $net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $max = strlen($a) * 8;
        $bits = $bits === null ? $max : (int) $bits;
        if ($bits < 0 || $bits > $max) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }

    /** Проверка записи для бана: одиночный IP или CIDR (с корректной длиной маски). */
    public static function validRange(string $value): bool
    {
        [$net, $bits] = array_pad(explode('/', trim($value), 2), 2, null);
        if (self::normalize((string) $net) === null) {
            return false;
        }
        if ($bits === null) {
            return true;
        }
        $max = str_contains((string) $net, ':') ? 128 : 32;
        return ctype_digit($bits) && (int) $bits >= 0 && (int) $bits <= $max;
    }

    /**
     * Грубый разбор User-Agent для аналитики: браузер, ОС, тип устройства.
     * @return array{browser:string,os:string,device:string}
     */
    public static function parseUa(string $ua): array
    {
        $browser = match (true) {
            (bool) preg_match('~YaBrowser/~i', $ua)             => 'Яндекс Браузер',
            (bool) preg_match('~Edg(e|A|iOS)?/~i', $ua)        => 'Edge',
            (bool) preg_match('~OPR/|Opera~i', $ua)            => 'Opera',
            (bool) preg_match('~SamsungBrowser/~i', $ua)       => 'Samsung Internet',
            (bool) preg_match('~Firefox/|FxiOS/~i', $ua)       => 'Firefox',
            (bool) preg_match('~Chrome/|CriOS/~i', $ua)        => 'Chrome',
            (bool) preg_match('~Safari/~i', $ua)               => 'Safari',
            default                                            => 'Другой',
        };
        $os = match (true) {
            (bool) preg_match('~Windows~i', $ua)               => 'Windows',
            (bool) preg_match('~Android~i', $ua)               => 'Android',
            (bool) preg_match('~iPhone|iPad|iPod|iOS~i', $ua)  => 'iOS',
            (bool) preg_match('~Mac OS X|Macintosh~i', $ua)    => 'macOS',
            (bool) preg_match('~Linux|X11~i', $ua)             => 'Linux',
            default                                            => 'Другая',
        };
        $device = match (true) {
            (bool) preg_match('~iPad|Tablet~i', $ua)           => 'Планшет',
            (bool) preg_match('~Mobile|iPhone|Android~i', $ua) => 'Телефон',
            default                                            => 'Компьютер',
        };
        return ['browser' => $browser, 'os' => $os, 'device' => $device];
    }
}
