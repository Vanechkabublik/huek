<?php
declare(strict_types=1);

/**
 * Точка входа для всех страниц и эндпоинтов: константы, автозагрузка классов,
 * часовой пояс и запуск сессии.
 */

define('BASE_PATH', dirname(__DIR__));
define('UPLOAD_DIR', BASE_PATH . '/public/uploads');
define('UPLOAD_URL', '/uploads');
define('APP_NAME', 'Авито-клон');
define('APP_DEBUG', getenv('APP_DEBUG') === '1');

// PSR-4-подобная автозагрузка: App\Ad → src/Ad.php
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

mb_internal_encoding('UTF-8');
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Europe/Moscow');
header_remove('X-Powered-By');

/** Экранирование вывода в HTML-шаблонах. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// В консольных скриптах (bin/*.php) сессия не нужна
if (PHP_SAPI !== 'cli') {
    \App\Auth::start();
}
