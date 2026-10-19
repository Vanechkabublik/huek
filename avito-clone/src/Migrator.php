<?php
declare(strict_types=1);

namespace App;

/**
 * Простые SQL-миграции: файлы database/migrations/*.sql применяются по порядку имён,
 * применённые записываются в таблицу schema_migrations. Каждая миграция — в своей транзакции.
 *
 * Установки, созданные до появления миграций (есть таблица users, но нет schema_migrations),
 * получают 001_base.sql как «уже применённую» — и дальше накатываются только новые файлы.
 */
final class Migrator
{
    private const LOCK_ID = 727301;

    /**
     * @param callable(string):void $log
     * @return int сколько миграций применено
     */
    public static function migrate(string $dir, callable $log): int
    {
        $pdo = Database::pdo();
        // Advisory-блокировка: два контейнера, стартующих одновременно, не будут гнать миграции параллельно
        $pdo->query('SELECT pg_advisory_lock(' . self::LOCK_ID . ')')->fetchAll();
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
                name       VARCHAR(100) PRIMARY KEY,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )');

            $applied = array_column(Database::all('SELECT name FROM schema_migrations'), 'name');
            if (!$applied && Database::value("SELECT to_regclass('public.users')") !== null) {
                Database::run('INSERT INTO schema_migrations (name) VALUES (?)', ['001_base.sql']);
                $applied[] = '001_base.sql';
                $log('Найдена БД без журнала миграций — 001_base.sql отмечена как применённая.');
            }

            $files = glob(rtrim($dir, '/') . '/*.sql') ?: [];
            sort($files);
            $count = 0;
            foreach ($files as $file) {
                $name = basename($file);
                if (in_array($name, $applied, true)) {
                    continue;
                }
                $log("Применяю миграцию $name …");
                $pdo->beginTransaction();
                try {
                    $pdo->exec((string) file_get_contents($file));
                    Database::run('INSERT INTO schema_migrations (name) VALUES (?)', [$name]);
                    $pdo->commit();
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw new \RuntimeException("Миграция $name не применена: " . $e->getMessage(), 0, $e);
                }
                $count++;
            }
            return $count;
        } finally {
            $pdo->query('SELECT pg_advisory_unlock(' . self::LOCK_ID . ')')->fetchAll();
        }
    }

    /** Заливает демо-данные, только если в БД ещё нет ни одного пользователя. */
    public static function seedDemo(string $file, callable $log): bool
    {
        if ((int) Database::value('SELECT COUNT(*) FROM users') > 0) {
            return false;
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->exec((string) file_get_contents($file));
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new \RuntimeException('Демо-данные не загружены: ' . $e->getMessage(), 0, $e);
        }
        $log('Демо-данные загружены.');
        return true;
    }
}
