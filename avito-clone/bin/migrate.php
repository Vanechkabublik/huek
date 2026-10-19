<?php
declare(strict_types=1);

/**
 * Применяет миграции БД (database/migrations/*.sql) и, если APP_DEMO=1 и база пуста, заливает демо-данные.
 * Запускается автоматически при старте контейнера app; вручную:
 *   docker compose exec app php bin/migrate.php
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Migrator;

$log = static fn(string $m) => fwrite(STDOUT, "[migrate] $m\n");

try {
    $n = Migrator::migrate(BASE_PATH . '/database/migrations', $log);
    $log($n > 0 ? "Применено миграций: $n." : 'БД актуальна.');
    if (getenv('APP_DEMO') === '1') {
        Migrator::seedDemo(BASE_PATH . '/database/seed/demo.sql', $log);
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[migrate] ОШИБКА: ' . $e->getMessage() . "\n");
    exit(1);
}
