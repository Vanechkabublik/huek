<?php
declare(strict_types=1);

namespace App;

/**
 * CSRF-защита: токен лежит в сессии, отдаётся странице через <meta name="csrf-token">
 * и возвращается JS-кодом в заголовке X-CSRF-Token (либо в поле _csrf).
 */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function rotate(): void
    {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public static function verify(): void
    {
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
        if (!is_string($sent) || $sent === '' || !hash_equals(self::token(), $sent)) {
            throw new HttpException('Сессия устарела. Обновите страницу и повторите действие.', 419);
        }
    }
}
