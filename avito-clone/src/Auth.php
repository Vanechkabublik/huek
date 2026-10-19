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
    /** Текст блокировки, если сессия принадлежала заблокированному пользователю (для сообщения вместо «Войдите»). */
    private static ?string $banMessage = null;

    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        session_name('board_sid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            // Secure-cookie и за обратным прокси (Caddy/nginx передают X-Forwarded-Proto: https)
            'secure'   => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
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
                if ($user !== null && (int) $user['is_blocked'] === 1) {
                    Ban::sweep(true);                               // вдруг срок блокировки уже истёк
                    $user = User::find($id);
                }
                if ($user === null || (int) $user['is_blocked'] === 1) {
                    if ($user !== null) {
                        self::$banMessage = Ban::userMessage($user);
                        $_SESSION['flash_ban'] = self::$banMessage;   // покажем причину на странице входа
                    }
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
     * @throws HttpException 401 — неверные данные, 403 — аккаунт или IP заблокирован (с причиной)
     */
    public static function login(string $email, string $password): array
    {
        Ban::assertIpAllowed($email);
        $row = User::findForLogin($email);

        // password_verify вызываем всегда — чтобы время ответа не выдавало, существует ли email
        $hash = $row['password_hash'] ?? self::DUMMY_HASH;
        $valid = password_verify($password, $hash) && $row !== null && $row['password_hash'] !== null;

        if (!$valid) {
            Security::log('login_failed', $row !== null ? (int) $row['id'] : null, 'email', $email);
            throw new HttpException('Неверный email или пароль', 401, ['password' => 'Неверный email или пароль']);
        }
        self::assertNotBanned($row, 'email');
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            User::setPassword((int) $row['id'], $password);
        }

        self::open((int) $row['id'], 'email');
        return self::user() ?? [];
    }

    /**
     * Не пускает заблокированного пользователя и объясняет причину. Работает для любого способа входа
     * (пароль, Яндекс, Google): вызывается после того, как личность подтверждена.
     * @param array<string,mixed> $user запись пользователя (нужны is_blocked, ban_reason, banned_until)
     */
    public static function assertNotBanned(array $user, string $provider): void
    {
        if ((int) $user['is_blocked'] !== 1) {
            return;
        }
        Ban::sweep(true);
        $fresh = User::find((int) $user['id']);
        if ($fresh === null || (int) $fresh['is_blocked'] !== 1) {
            return;                       // срок истёк
        }
        Security::log('banned', (int) $fresh['id'], $provider);
        throw new HttpException(Ban::userMessage($fresh), 403);
    }

    /**
     * Открывает сессию для пользователя (после входа или регистрации) и пишет запись в журнал входов.
     * @param string $provider email | google | yandex
     */
    public static function open(int $userId, string $provider = 'email', bool $created = false): void
    {
        session_regenerate_id(true);          // защита от фиксации сессии
        $_SESSION['uid'] = $userId;
        if ($created) {
            User::recordSignup($userId);
        }
        User::touchLogin($userId);
        Security::log($created ? 'register' : 'login', $userId, $provider);
        Csrf::rotate();
        self::$loaded = false;
        self::$user = null;
        self::$banMessage = null;
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
            throw self::$banMessage !== null
                ? new HttpException(self::$banMessage, 403)
                : new HttpException('Войдите в аккаунт, чтобы продолжить', 401);
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
