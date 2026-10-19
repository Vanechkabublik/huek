<?php
declare(strict_types=1);

namespace App;

/**
 * Доступ к входным данным: тело JSON (если пришло) + $_POST + $_GET.
 */
final class Request
{
    private static ?array $input = null;

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /** @return array<string,mixed> */
    public static function input(): array
    {
        if (self::$input === null) {
            $body = [];
            $type = $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($type, 'application/json') !== false) {
                $decoded = json_decode((string) file_get_contents('php://input'), true);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            }
            self::$input = $body + $_POST + $_GET;
        }
        return self::$input;
    }

    public static function str(string $key, string $default = ''): string
    {
        $v = self::input()[$key] ?? $default;
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::input()[$key] ?? null;
        return is_numeric($v) ? (int) $v : $default;
    }

    /** Название действия эндпоинта (?action=...). */
    public static function action(string $default = ''): string
    {
        return preg_replace('/[^a-z_]/', '', self::str('action', $default)) ?? $default;
    }

    /** Требует POST + верный CSRF-токен. */
    public static function requirePost(): void
    {
        if (self::method() !== 'POST') {
            throw new HttpException('Метод не поддерживается', 405);
        }
        Csrf::verify();
    }
}
