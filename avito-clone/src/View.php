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
            'title'       => APP_NAME,
            'page'        => '',
            'categories'  => true,     // показывать полосу категорий под шапкой
            'category'    => 0,        // id активной категории
            'description' => 'Доска объявлений: покупайте и продавайте рядом с вами',
            'bodyClass'   => '',
        ];
        self::$opts = $o;
        $user = Auth::user();
        $unread = $user ? Message::unreadCount((int) $user['id']) : 0;
        $client = [
            'csrf' => Csrf::token(),
            'user' => User::forClient($user),
        ];
        $cats = $o['categories'] ? self::categories() : [];
        require BASE_PATH . '/templates/header.php';
    }

    /** Закрывает страницу: подвал, нижняя навигация для мобильных, скрипты. */
    public static function footer(): void
    {
        $user = Auth::user();
        $page = self::$opts['page'] ?? '';
        require BASE_PATH . '/templates/footer.php';
    }

    /** Страница-заглушка «не найдено» с корректным HTTP-статусом. */
    public static function notFound(string $message = 'Такой страницы не существует или она была удалена.'): never
    {
        http_response_code(404);
        self::header(['title' => 'Страница не найдена', 'page' => 'error']);
        echo '<main class="container"><div class="empty empty--page">',
            '<div class="empty__icon">🔍</div><h1>Ничего не найдено</h1><p>', e($message), '</p>',
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

    /** Безопасно встраивает данные в inline-<script> и атрибуты. */
    public static function json(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    }
}
