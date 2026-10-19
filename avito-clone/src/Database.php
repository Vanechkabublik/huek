<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

/**
 * Подключение к MySQL: PDO-синглтон с настройками из переменных окружения.
 */
final class Database
{
    private static ?PDO $pdo = null;

    private function __construct()
    {
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect();
        }
        return self::$pdo;
    }

    private static function connect(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST') ?: 'db',
            getenv('DB_NAME') ?: 'ads'
        );

        $lastError = null;
        // MySQL может подниматься чуть дольше приложения — пробуем несколько раз
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $pdo = new PDO($dsn, getenv('DB_USER') ?: 'ads_user', getenv('DB_PASS') ?: 'ads_pass', [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,   // настоящие prepared statements
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                ]);
                // Часовой пояс MySQL = часовому поясу PHP, иначе «Сегодня, 14:00» будет врать
                $pdo->exec("SET time_zone = '" . date('P') . "'");
                return $pdo;
            } catch (PDOException $e) {
                $lastError = $e;
                usleep(400_000);
            }
        }
        throw $lastError;
    }

    /** Выполняет подготовленный запрос и возвращает statement. */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }
}
