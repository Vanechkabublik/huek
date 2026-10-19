<?php
declare(strict_types=1);

namespace App;

/**
 * Настройки сайта, которые админ меняет в панели (таблица settings): название, оператор данных, контактная почта.
 */
final class Settings
{
    /** Допустимые ключи → значение по умолчанию. */
    public const DEFAULTS = [
        'site_name'     => 'Авито-клон',
        'operator'      => '',
        'contact_email' => '',
    ];

    /** @var array<string,string>|null */
    private static ?array $cache = null;

    /** @return array<string,string> */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;
            try {
                foreach (Database::all('SELECT key, value FROM settings') as $r) {
                    if (array_key_exists($r['key'], self::DEFAULTS)) {
                        self::$cache[$r['key']] = (string) $r['value'];
                    }
                }
            } catch (\Throwable) {
                // БД недоступна или миграция ещё не применена — работаем со значениями по умолчанию
            }
        }
        return self::$cache;
    }

    public static function get(string $key): string
    {
        return self::all()[$key] ?? '';
    }

    public static function siteName(): string
    {
        return self::get('site_name') !== '' ? self::get('site_name') : self::DEFAULTS['site_name'];
    }

    /** @param array<string,mixed> $in */
    public static function save(array $in): void
    {
        $errors = [];
        $clean = [];
        foreach (self::DEFAULTS as $key => $_) {
            if (!array_key_exists($key, $in)) {
                continue;
            }
            $v = trim((string) $in[$key]);
            $clean[$key] = match ($key) {
                'site_name'     => mb_substr($v, 0, 60),
                'operator'      => mb_substr($v, 0, 200),
                'contact_email' => mb_substr($v, 0, 120),
            };
        }
        if (isset($clean['site_name']) && mb_strlen($clean['site_name']) < 2) {
            $errors['site_name'] = 'Название — от 2 до 60 символов';
        }
        if (($clean['contact_email'] ?? '') !== '' && !filter_var($clean['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['contact_email'] = 'Введите корректный email';
        }
        if ($errors) {
            throw new HttpException('Проверьте правильность заполнения полей', 422, $errors);
        }
        foreach ($clean as $key => $value) {
            Database::run(
                'INSERT INTO settings (key, value, updated_at) VALUES (?, ?, NOW())
                 ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()',
                [$key, $value]
            );
        }
        self::$cache = null;
    }
}
