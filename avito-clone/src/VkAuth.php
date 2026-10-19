<?php
declare(strict_types=1);

namespace App;

/**
 * Вход через VK ID (OAuth 2.1, authorization code + PKCE).
 *
 *  1. start()          — формирует ссылку авторизации и запоминает state/code_verifier в сессии;
 *  2. VK возвращает пользователя на /vk-callback.php?code=…&state=…&device_id=…;
 *  3. handleCallback() — проверяет state, меняет code на токен (POST id.vk.ru/oauth2/auth),
 *                        запрашивает профиль (POST id.vk.ru/oauth2/user_info), находит/создаёт пользователя.
 *
 * Настройки (переменные окружения): VK_CLIENT_ID (ID приложения), APP_URL (https://ваш-домен).
 * Необязательно: VK_SCOPE (по умолчанию vkid.personal_info; «vkid.personal_info email» — ещё и email),
 * VK_ID_BASE — адрес VK ID (по умолчанию https://id.vk.ru; в тестах — заглушка).
 */
final class VkAuth
{
    private const SESSION_TTL = 900;   // 15 минут на прохождение авторизации

    public static function enabled(): bool
    {
        return (string) getenv('VK_CLIENT_ID') !== '';
    }

    private static function base(): string
    {
        return rtrim((string) (getenv('VK_ID_BASE') ?: 'https://id.vk.ru'), '/');
    }

    /** Публичный адрес сайта без слэша на конце: APP_URL или собирается из запроса (с учётом прокси). */
    public static function appUrl(): string
    {
        $url = (string) getenv('APP_URL');
        if ($url !== '') {
            return rtrim($url, '/');
        }
        $https = !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    /** Этот адрес нужно указать в кабинете VK как доверенный Redirect URL. */
    public static function redirectUri(): string
    {
        return self::appUrl() . '/vk-callback.php';
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Ссылка на страницу авторизации VK.
     * @param bool $link true — привязать VK к уже вошедшему пользователю, а не входить
     */
    public static function start(string $next = '/index.php', bool $link = false): string
    {
        if (!self::enabled()) {
            throw new HttpException('Вход через VK не настроен', 404);
        }
        $state    = bin2hex(random_bytes(16));
        $verifier = self::b64url(random_bytes(48));
        $_SESSION['vk'] = [
            'state'    => $state,
            'verifier' => $verifier,
            'next'     => $next,
            'link'     => $link,
            'uid'      => $link ? Auth::id() : null,
            't'        => time(),
        ];
        return self::base() . '/authorize?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => getenv('VK_CLIENT_ID'),
            'redirect_uri'          => self::redirectUri(),
            'state'                 => $state,
            'code_challenge'        => self::b64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'scope'                 => getenv('VK_SCOPE') ?: 'vkid.personal_info',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Обрабатывает возврат из VK.
     * @param array<string,mixed> $query $_GET страницы /vk-callback.php
     * @return array{user_id:int,linked:bool,created:bool,next:string}
     * @throws HttpException
     */
    public static function handleCallback(array $query): array
    {
        $ctx = $_SESSION['vk'] ?? null;
        unset($_SESSION['vk']);                                   // одноразово

        // По документации VK параметры возврата могут прийти одним JSON в ?payload=… — разворачиваем и этот вариант
        if (isset($query['payload']) && is_string($query['payload'])) {
            $payload = json_decode($query['payload'], true);
            if (is_array($payload)) {
                $query = $payload + $query;
            }
        }

        if (!empty($query['error'])) {
            throw new HttpException('Вход через VK отменён', 400);
        }
        if (!is_array($ctx) || (time() - (int) $ctx['t']) > self::SESSION_TTL) {
            throw new HttpException('Сессия входа через VK истекла. Попробуйте ещё раз.', 400);
        }
        $state = (string) ($query['state'] ?? '');
        $code  = (string) ($query['code'] ?? '');
        $device = (string) ($query['device_id'] ?? '');
        if ($state === '' || !hash_equals((string) $ctx['state'], $state) || $code === '' || $device === '') {
            throw new HttpException('Не удалось проверить ответ VK. Попробуйте ещё раз.', 400);
        }

        try {
            $token = Http::postForm(self::base() . '/oauth2/auth', [
                'grant_type'    => 'authorization_code',
                'code_verifier' => $ctx['verifier'],
                'redirect_uri'  => self::redirectUri(),
                'code'          => $code,
                'client_id'     => (string) getenv('VK_CLIENT_ID'),
                'device_id'     => $device,
                'state'         => $state,
            ]);
            if (empty($token['access_token'])) {
                error_log('VK token error: ' . json_encode($token, JSON_UNESCAPED_UNICODE));
                throw new HttpException('VK не выдал токен: ' . ($token['error_description'] ?? $token['error'] ?? 'неизвестная ошибка'), 502);
            }
            $info = Http::postForm(self::base() . '/oauth2/user_info', [
                'client_id'    => (string) getenv('VK_CLIENT_ID'),
                'access_token' => $token['access_token'],
            ]);
        } catch (\RuntimeException $e) {
            error_log('VK request failed: ' . $e->getMessage());
            throw new HttpException('Не удалось связаться с VK. Попробуйте позже.', 502);
        }

        $vk = $info['user'] ?? null;
        $vkId = is_array($vk) ? (string) ($vk['user_id'] ?? '') : '';
        if ($vkId === '' || !preg_match('/^\d{1,20}$/', $vkId)) {
            error_log('VK user_info error: ' . json_encode($info, JSON_UNESCAPED_UNICODE));
            throw new HttpException('VK не вернул данные профиля', 502);
        }
        $name   = trim(($vk['first_name'] ?? '') . ' ' . ($vk['last_name'] ?? ''));
        $avatar = isset($vk['avatar']) && is_string($vk['avatar']) && str_starts_with($vk['avatar'], 'https://') ? $vk['avatar'] : null;
        $email  = isset($vk['email']) && is_string($vk['email']) && filter_var($vk['email'], FILTER_VALIDATE_EMAIL) ? $vk['email'] : null;
        $phone  = isset($vk['phone']) && is_scalar($vk['phone']) ? (string) $vk['phone'] : null;

        $next = (string) ($ctx['next'] ?? '/index.php');
        $next = str_starts_with($next, '/') && !str_starts_with($next, '//') ? $next : '/index.php';

        // Режим «привязать VK к текущему аккаунту»
        if (!empty($ctx['link'])) {
            $me = Auth::id();
            if ($me === null || $me !== (int) $ctx['uid']) {
                throw new HttpException('Войдите в аккаунт, чтобы привязать VK', 401);
            }
            User::linkVk($me, $vkId, $avatar);
            return ['user_id' => $me, 'linked' => true, 'created' => false, 'next' => '/profile.php'];
        }

        $existing = User::findByVk($vkId);
        if ($existing !== null) {
            if ((int) $existing['is_blocked'] === 1) {
                throw new HttpException('Ваш аккаунт заблокирован администратором', 403);
            }
            return ['user_id' => (int) $existing['id'], 'linked' => false, 'created' => false, 'next' => $next];
        }

        $id = User::createFromVk($vkId, $name, $email, $avatar, $phone);
        return ['user_id' => $id, 'linked' => false, 'created' => true, 'next' => $next];
    }
}
