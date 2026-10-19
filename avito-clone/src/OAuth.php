<?php
declare(strict_types=1);

namespace App;

/**
 * Вход через Google и Яндекс (OAuth 2.0 «authorization code» + PKCE).
 *
 *  1. start()          — формирует ссылку авторизации у провайдера, state и code_verifier кладёт в сессию;
 *  2. провайдер возвращает пользователя на /oauth-callback.php?code=…&state=…;
 *  3. handleCallback() — проверяет state, меняет code на токен, запрашивает профиль, находит или создаёт пользователя.
 *
 * Настройки (переменные окружения):
 *   GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET   — обязательны оба, иначе кнопка Google скрыта;
 *   YANDEX_CLIENT_ID [, YANDEX_CLIENT_SECRET] — секрет для Яндекса необязателен: с PKCE он не нужен;
 *   APP_URL — публичный адрес сайта (от него строится Redirect URI);
 *   OAUTH_MOCK_BASE — только для тестов: адреса провайдеров заменяются на заглушку.
 *
 * Правила привязки аккаунтов:
 *  • повторный вход находит пользователя по паре (провайдер, id у провайдера) — email не используется;
 *  • если email из Google/Яндекса уже занят обычной учётной записью, новая НЕ создаётся и старая НЕ захватывается
 *    (на сайте нет подтверждения email, иначе возможен захват аккаунта): нужно войти по паролю и привязать вход в профиле.
 */
final class OAuth
{
    private const SESSION_TTL = 900;   // 15 минут на прохождение авторизации

    /** Провайдеры в порядке показа на сайте. */
    public const PROVIDERS = ['yandex' => 'Яндекс', 'google' => 'Google'];

    /** @return array<string,mixed>|null настройки провайдера или null, если провайдера нет */
    private static function config(string $provider): ?array
    {
        $mock = rtrim((string) getenv('OAUTH_MOCK_BASE'), '/');
        $u = static fn(string $real, string $path) => $mock !== '' ? "$mock/$path" : $real;

        return match ($provider) {
            'google' => [
                'name'       => 'Google',
                'id'         => (string) getenv('GOOGLE_CLIENT_ID'),
                'secret'     => (string) getenv('GOOGLE_CLIENT_SECRET'),
                'needSecret' => true,
                'authUrl'    => $u('https://accounts.google.com/o/oauth2/v2/auth', 'google/authorize'),
                'tokenUrl'   => $u('https://oauth2.googleapis.com/token', 'google/token'),
                'infoUrl'    => $u('https://openidconnect.googleapis.com/v1/userinfo', 'google/userinfo'),
                'authExtra'  => ['scope' => 'openid email profile', 'prompt' => 'select_account'],
                'authHeader' => 'Bearer',
            ],
            'yandex' => [
                'name'       => 'Яндекс',
                'id'         => (string) getenv('YANDEX_CLIENT_ID'),
                'secret'     => (string) getenv('YANDEX_CLIENT_SECRET'),
                'needSecret' => false,
                'authUrl'    => $u('https://oauth.yandex.ru/authorize', 'yandex/authorize'),
                'tokenUrl'   => $u('https://oauth.yandex.ru/token', 'yandex/token'),
                'infoUrl'    => $u('https://login.yandex.ru/info', 'yandex/info') . '?format=json',
                // scope не передаём: права берутся из настроек приложения в кабинете Яндекса
                'authExtra'  => [],
                'authHeader' => 'OAuth',
            ],
            default => null,
        };
    }

    public static function enabled(string $provider): bool
    {
        $c = self::config($provider);
        return $c !== null && $c['id'] !== '' && (!$c['needSecret'] || $c['secret'] !== '');
    }

    /**
     * Что настроено у провайдера (для админки). Сам секрет не отдаём — только факт, что он задан.
     * @return array{enabled:bool,client_id:string,secret_set:bool,secret_required:bool}
     */
    public static function status(string $provider): array
    {
        $c = self::config($provider) ?? ['id' => '', 'secret' => '', 'needSecret' => false];
        return [
            'enabled'         => self::enabled($provider),
            'client_id'       => $c['id'],
            'secret_set'      => $c['secret'] !== '',
            'secret_required' => $c['needSecret'],
        ];
    }

    /** @return string[] коды включённых провайдеров, например ['yandex', 'google'] */
    public static function enabledProviders(): array
    {
        return array_values(array_filter(array_keys(self::PROVIDERS), self::enabled(...)));
    }

    public static function name(string $provider): string
    {
        return self::PROVIDERS[$provider] ?? $provider;
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

    /** Этот адрес нужно указать у провайдера как Redirect URI (он один для Google и Яндекса). */
    public static function redirectUri(): string
    {
        return self::appUrl() . '/oauth-callback.php';
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Ссылка на страницу авторизации у провайдера.
     * @param bool $link true — привязать аккаунт к уже вошедшему пользователю, а не входить
     */
    public static function start(string $provider, string $next = '/index.php', bool $link = false): string
    {
        $c = self::config($provider);
        if ($c === null || !self::enabled($provider)) {
            throw new HttpException('Вход через ' . self::name($provider) . ' не настроен', 404);
        }
        $state    = bin2hex(random_bytes(16));
        $verifier = self::b64url(random_bytes(48));
        $_SESSION['oauth'] = [
            'provider' => $provider,
            'state'    => $state,
            'verifier' => $verifier,
            'next'     => $next,
            'link'     => $link,
            'uid'      => $link ? Auth::id() : null,
            't'        => time(),
        ];
        return $c['authUrl'] . (str_contains($c['authUrl'], '?') ? '&' : '?') . http_build_query([
            'response_type'         => 'code',
            'client_id'             => $c['id'],
            'redirect_uri'          => self::redirectUri(),
            'state'                 => $state,
            'code_challenge'        => self::b64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ] + $c['authExtra'], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Обрабатывает возврат от провайдера.
     * @param array<string,mixed> $query $_GET страницы /oauth-callback.php
     * @return array{user_id:int,provider:string,linked:bool,created:bool,next:string}
     * @throws HttpException
     */
    public static function handleCallback(array $query): array
    {
        $ctx = $_SESSION['oauth'] ?? null;
        unset($_SESSION['oauth']);                                // одноразово

        $provider = is_array($ctx) ? (string) ($ctx['provider'] ?? '') : '';
        $c = self::config($provider);
        $name = self::name($provider);

        if (!empty($query['error'])) {
            throw new HttpException("Вход через $name отменён", 400);
        }
        if (!is_array($ctx) || $c === null || (time() - (int) $ctx['t']) > self::SESSION_TTL) {
            throw new HttpException('Сессия входа истекла. Попробуйте ещё раз.', 400);
        }
        $state = (string) ($query['state'] ?? '');
        $code  = (string) ($query['code'] ?? '');
        if ($state === '' || !hash_equals((string) $ctx['state'], $state) || $code === '') {
            throw new HttpException("Не удалось проверить ответ $name. Попробуйте ещё раз.", 400);
        }

        try {
            $fields = [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => self::redirectUri(),
                'client_id'     => $c['id'],
                'code_verifier' => $ctx['verifier'],
            ];
            if ($c['secret'] !== '') {
                $fields['client_secret'] = $c['secret'];
            }
            $token = Http::postForm($c['tokenUrl'], $fields);
            if (empty($token['access_token']) || !is_string($token['access_token'])) {
                error_log("OAuth $provider token error: " . json_encode($token, JSON_UNESCAPED_UNICODE));
                throw new HttpException("$name не выдал токен: " . ($token['error_description'] ?? $token['error'] ?? 'неизвестная ошибка'), 502);
            }
            $info = Http::getJson($c['infoUrl'], ['Authorization: ' . $c['authHeader'] . ' ' . $token['access_token']]);
        } catch (\RuntimeException $e) {
            error_log("OAuth $provider request failed: " . $e->getMessage());
            throw new HttpException("Не удалось связаться с сервисом $name. Попробуйте позже.", 502);
        }

        $profile = self::normalize($provider, $info);
        if ($profile === null) {
            error_log("OAuth $provider userinfo error: " . json_encode($info, JSON_UNESCAPED_UNICODE));
            throw new HttpException("$name не вернул данные профиля", 502);
        }

        $next = (string) ($ctx['next'] ?? '/index.php');
        $next = str_starts_with($next, '/') && !str_starts_with($next, '//') ? $next : '/index.php';

        // Режим «привязать к текущему аккаунту»
        if (!empty($ctx['link'])) {
            $me = Auth::id();
            if ($me === null || $me !== (int) $ctx['uid']) {
                throw new HttpException("Войдите в аккаунт, чтобы привязать $name", 401);
            }
            User::linkIdentity($me, $provider, $profile['id'], $profile['email']);
            return ['user_id' => $me, 'provider' => $provider, 'linked' => true, 'created' => false, 'next' => '/profile.php'];
        }

        $existing = User::findByIdentity($provider, $profile['id']);
        if ($existing !== null) {
            if ((int) $existing['is_blocked'] === 1) {
                throw new HttpException('Ваш аккаунт заблокирован администратором', 403);
            }
            return ['user_id' => (int) $existing['id'], 'provider' => $provider, 'linked' => false, 'created' => false, 'next' => $next];
        }

        if ($profile['email'] !== null && User::emailExists($profile['email'])) {
            throw new HttpException(
                "Аккаунт с почтой {$profile['email']} уже есть на сайте. Войдите по email и паролю, затем привяжите $name в профиле.",
                409
            );
        }

        $avatar = $profile['avatar'] !== null ? Upload::storeAvatarFromUrl($profile['avatar']) : null;
        $id = User::createFromIdentity($provider, $profile['id'], $profile['name'], $profile['email'], $avatar);
        return ['user_id' => $id, 'provider' => $provider, 'linked' => false, 'created' => true, 'next' => $next];
    }

    /**
     * Приводит ответ провайдера к единому виду.
     * @param array<string,mixed> $i
     * @return array{id:string,name:string,email:?string,avatar:?string}|null
     */
    private static function normalize(string $provider, array $i): ?array
    {
        if ($provider === 'google') {
            $id     = (string) ($i['sub'] ?? '');
            $name   = trim((string) ($i['name'] ?? '')) ?: trim(($i['given_name'] ?? '') . ' ' . ($i['family_name'] ?? ''));
            // email берём только подтверждённый Google
            $verified = ($i['email_verified'] ?? false) === true || ($i['email_verified'] ?? '') === 'true';
            $email  = $verified ? ($i['email'] ?? null) : null;
            $avatar = $i['picture'] ?? null;
        } else {
            $id     = (string) ($i['id'] ?? '');
            $name   = trim((string) ($i['real_name'] ?? '')) ?: trim((string) ($i['display_name'] ?? '')) ?: trim(($i['first_name'] ?? '') . ' ' . ($i['last_name'] ?? ''));
            $name   = $name !== '' ? $name : (string) ($i['login'] ?? '');
            $email  = $i['default_email'] ?? null;
            $avatar = !empty($i['default_avatar_id']) && empty($i['is_avatar_empty'])
                ? 'https://avatars.yandex.net/get-yapic/' . rawurlencode((string) $i['default_avatar_id']) . '/islands-200'
                : null;
        }
        if (!preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $id)) {
            return null;
        }
        $email = is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_strtolower($email) : null;
        return [
            'id'     => $id,
            'name'   => $name !== '' ? $name : 'Пользователь',
            'email'  => $email,
            'avatar' => is_string($avatar) && $avatar !== '' ? $avatar : null,
        ];
    }
}
