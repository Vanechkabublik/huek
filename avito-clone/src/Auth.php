<?php
declare(strict_types=1);

namespace App;

/**
 * Аутентификация на PHP-сессиях.
 * Текущий пользователь каждый раз перечитывается из БД, поэтому блокировка
 * или удаление аккаунта действуют мгновенно — даже на уже открытых сессиях.
 */
final class Auth
{
    /** Хеш-пустышка: сравниваем с ним, когда email не найден (выравнивает время ответа). */
    private const DUMMY_HASH = '$2y$10$4jnPE0Rnf25DNIBJ6kJ32ODy39TvdE6TWh660SbuANqc1467mK70i';

    private static ?array $user = null;
    private static bool $loaded = false;

    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        session_name('board_sid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /** Текущий пользователь (без password_hash) или null. */
    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = (int) ($_SESSION['uid'] ?? 0);
            if ($id > 0) {
                $user = User::find($id);
                if ($user === null || (int) $user['is_blocked'] === 1) {
                    self::forget();
                } else {
                    self::$user = $user;
                }
            }
        }
        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function isAdmin(): bool
    {
        $u = self::user();
        return $u !== null && $u['role'] === 'admin';
    }

    /**
     * Проверяет email+пароль и открывает сессию.
     * @throws HttpException 401 — неверные данные, 403 — аккаунт заблокирован
     */
    public static function login(string $email, string $password): array
    {
        $row = User::findForLogin($email);

        // password_verify вызываем всегда — чтобы время ответа не выдавало, существует ли email
        $hash = $row['password_hash'] ?? self::DUMMY_HASH;
        $valid = password_verify($password, $hash);

        if ($row === null || !$valid) {
            throw new HttpException('Неверный email или пароль', 401, ['password' => 'Неверный email или пароль']);
        }
        if ((int) $row['is_blocked'] === 1) {
            throw new HttpException('Ваш аккаунт заблокирован администратором', 403);
        }
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            User::setPassword((int) $row['id'], $password);
        }

        self::open((int) $row['id']);
        return self::user() ?? [];
    }

    /** Открывает сессию для пользователя (после входа или регистрации). */
    public static function open(int $userId): void
    {
        session_regenerate_id(true);          // защита от фиксации сессии
        $_SESSION['uid'] = $userId;
        Csrf::rotate();
        self::$loaded = false;
        self::$user = null;
    }

    public static function logout(): void
    {
        self::forget();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
    }

    private static function forget(): void
    {
        unset($_SESSION['uid']);
        self::$user = null;
    }

    /**
     * Требует вход. Для страниц — редирект на login.php, для API — 401.
     */
    public static function requireLogin(bool $json = false): array
    {
        $user = self::user();
        if ($user !== null) {
            return $user;
        }
        if ($json) {
            throw new HttpException('Войдите в аккаунт, чтобы продолжить', 401);
        }
        Response::redirect('/login.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/'));
    }

    /** Требует роль admin. Для страниц — редирект, для API — 403. */
    public static function requireAdmin(bool $json = false): array
    {
        $user = self::user();
        if ($user !== null && $user['role'] === 'admin') {
            return $user;
        }
        if ($json) {
            throw new HttpException($user ? 'Недостаточно прав' : 'Войдите в аккаунт, чтобы продолжить', $user ? 403 : 401);
        }
        Response::redirect($user ? '/index.php' : '/login.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/admin.php'));
    }
}
