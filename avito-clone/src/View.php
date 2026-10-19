<?php
declare(strict_types=1);

namespace App;

/**
 * Серверные шаблоны: общая шапка/подвал страницы и мелкие помощники для HTML.
 */
final class View
{
    private static ?array $categories = null;
    private static array $opts = [];

    /**
     * Открывает страницу: <head>, шапка с поиском, полоса категорий.
     * @param array{title?:string,page?:string,categories?:bool,category?:int,description?:string,bodyClass?:string} $o
     */
    public static function header(array $o = []): void
    {
        $o += [
            'title'       => Settings::siteName(),
            'page'        => '',
            'categories'  => true,     // показывать полосу категорий под шапкой
            'category'    => 0,        // id активной категории
            'description' => 'Доска объявлений: покупайте и продавайте рядом с вами',
            'bodyClass'   => '',
            'image'       => '',       // картинка для превью ссылки (Open Graph); по умолчанию — логотип
            'noindex'     => false,    // закрыть страницу от поисковиков
        ];
        self::$opts = $o;
        $user = Auth::user();
        $unread = $user ? Chat::unreadTotal((int) $user['id']) : 0;
        $client = self::clientData($user, $unread);
        $cats = $o['categories'] ? self::categories() : [];
        $site = Settings::siteName();
        $city = Geo::current($user);
        require BASE_PATH . '/templates/header.php';
    }

    /** Данные для window.__APP__ (CSRF, пользователь, настройки realtime). */
    public static function clientData(?array $user, int $unread = 0): array
    {
        return [
            'csrf'   => Csrf::token(),
            'user'   => User::forClient($user),
            'unread' => $unread,
            'ws'     => ['url' => Realtime::publicUrl(), 'port' => Realtime::publicPort()],
            'legal'  => ['version' => Legal::consentVersion()],
            'city'   => Geo::current($user),                // null — ещё не выбирал, 'all' — все города
            'flash'  => self::pullFlash(),
        ];
    }

    /** Одноразовые сообщения между редиректами (ошибка входа через Google/Яндекс, «профиль сохранён» и т. п.). */
    private static function pullFlash(): array
    {
        $flash = ['error' => $_SESSION['flash_error'] ?? null, 'ok' => $_SESSION['flash_ok'] ?? null];
        unset($_SESSION['flash_error'], $_SESSION['flash_ok']);
        return $flash;
    }

    /** Скрипт, выставляющий тему до отрисовки страницы (без «вспышки» светлой темы). */
    public static function themeScript(): string
    {
        return "<script>(function(){try{var t=localStorage.getItem('theme');if(!t){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}document.documentElement.setAttribute('data-theme',t)}catch(e){}})()</script>";
    }

    /** Аватар: фото или буква. */
    public static function avatar(?array $u, string $class = ''): string
    {
        $name = (string) ($u['name'] ?? '?');
        $url  = $u['avatar_url'] ?? ($u['avatar'] ?? null);
        $cls  = trim('avatar ' . $class);
        if (!empty($url)) {
            return '<span class="' . e($cls) . ' avatar--img"><img src="' . e((string) $url) . '" alt="" referrerpolicy="no-referrer"></span>';
        }
        return '<span class="' . e($cls) . '">' . e(mb_strtoupper(mb_substr($name, 0, 1))) . '</span>';
    }

    /**
     * Закрывает страницу: подвал, нижняя навигация для мобильных, скрипты.
     * @param string[] $scripts дополнительные скрипты страницы (после app.js), например ['/assets/js/chat.js']
     */
    public static function footer(array $scripts = []): void
    {
        $user = Auth::user();
        $unread = $user ? Chat::unreadTotal((int) $user['id']) : 0;
        $page = self::$opts['page'] ?? '';
        require BASE_PATH . '/templates/footer.php';
    }

    /** Страница-заглушка «не найдено» с корректным HTTP-статусом. */
    public static function notFound(string $message = 'Такой страницы не существует или она была удалена.'): never
    {
        http_response_code(404);
        self::header(['title' => 'Страница не найдена', 'page' => 'error']);
        echo '<main class="container"><div class="empty empty--page">',
            '<div class="empty__icon">', self::icon('search'), '</div><h1>Ничего не найдено</h1><p>', e($message), '</p>',
            '<a class="btn btn--primary btn--lg" href="/index.php">На главную</a></div></main>';
        self::footer();
        exit;
    }

    /** @return array<int,array<string,mixed>> */
    public static function categories(): array
    {
        return self::$categories ??= Category::all();
    }

    /** Путь к статике с меткой версии — браузер перекачивает файл только при изменении. */
    public static function asset(string $path): string
    {
        $file = BASE_PATH . '/public' . $path;
        return $path . '?v=' . (is_file($file) ? filemtime($file) : '0');
    }

    /** SVG-иконка из спрайта (templates/icons.php). */
    public static function icon(string $name, string $class = ''): string
    {
        return '<svg class="icon' . ($class !== '' ? ' ' . e($class) : '') . '" aria-hidden="true"><use href="#i-' . e($name) . '"/></svg>';
    }

    /** Логотип: знак + название сайта из настроек (первое слово жирным, остальное — акцентным цветом). */
    public static function logo(string $class = ''): string
    {
        $name = Settings::siteName();
        $split = preg_match('/^(.+?)([\s\-].*)$/u', $name, $m) ? [$m[1], $m[2]] : [$name, ''];
        return '<a class="logo' . ($class !== '' ? ' ' . e($class) : '') . '" href="/index.php" aria-label="' . e($name) . ' — на главную">'
            . '<img class="logo__mark" src="/assets/img/logo-mark.svg" width="36" height="36" alt="">'
            . '<span class="logo__text">' . e($split[0]) . ($split[1] !== '' ? '<b>' . e($split[1]) . '</b>' : '') . '</span></a>';
    }

    /** Имя иконки спрайта для категории (по названию): вместо эмодзи показываем строгие линейные значки. */
    public static function categoryIcon(string $name): string
    {
        return Category::iconKey($name);
    }

    /** Галочка согласия с политикой и соглашением для форм входа и регистрации. */
    public static function consentField(): string
    {
        return '<div class="field field--consent"><label class="checkbox consent">'
            . '<input class="check" type="checkbox" id="consent" name="consent" value="1">'
            . '<span>Я прочитал(а) и принимаю <a href="/privacy.php" target="_blank" rel="noopener">Политику обработки персональных данных</a>'
            . ' и <a href="/terms.php" target="_blank" rel="noopener">Пользовательское соглашение</a></span></label>'
            . '<p class="field__error" data-error-for="consent"></p></div>';
    }

    /** Фирменный значок провайдера входа: цветная «G» Google или красная «Я» Яндекса. */
    public static function oauthMark(string $provider): string
    {
        if ($provider === 'google') {
            return '<svg class="oauth-mark" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>';
        }
        return '<span class="oauth-mark oauth-mark--yandex" aria-hidden="true">Я</span>';
    }

    /** Кнопки «Войти через Яндекс / Google» для включённых провайдеров ('' — если ни один не настроен). */
    public static function oauthButtons(string $href, string $verb = 'Войти'): string
    {
        $out = '';
        foreach (OAuth::enabledProviders() as $p) {
            $out .= '<a class="btn btn--oauth btn--oauth-' . $p . ' btn--lg btn--block" href="'
                . e($href . (str_contains($href, '?') ? '&' : '?') . 'provider=' . $p) . '">'
                . self::oauthMark($p) . e($verb . ' через ' . OAuth::name($p)) . '</a>';
        }
        return $out;
    }

    /** Безопасно встраивает данные в inline-<script> и атрибуты. */
    public static function json(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    }
}
