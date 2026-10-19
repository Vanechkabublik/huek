<?php
declare(strict_types=1);

namespace App;

/**
 * Категории объявлений.
 */
final class Category
{
    /**
     * Все категории со счётчиками объявлений.
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array
    {
        $rows = Database::all(
            "SELECT c.id, c.name, c.icon,
                    COUNT(a.id) AS total_ads,
                    COUNT(a.id) FILTER (WHERE a.status = 'approved') AS approved_ads
             FROM categories c
             LEFT JOIN ads a ON a.category_id = c.id
             GROUP BY c.id, c.name, c.icon
             ORDER BY c.id"
        );
        return array_map(static fn(array $r) => [
            'id'           => (int) $r['id'],
            'name'         => $r['name'],
            'icon'         => $r['icon'],
            'total_ads'    => (int) $r['total_ads'],
            'approved_ads' => (int) $r['approved_ads'],
        ], $rows);
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT id, name, icon FROM categories WHERE id = ?', [$id]);
    }

    public static function create(string $name, string $icon): int
    {
        [$name, $icon] = self::validate($name, $icon);
        self::assertUnique($name, null);
        return Database::insert('INSERT INTO categories (name, icon) VALUES (?, ?)', [$name, $icon]);
    }

    public static function update(int $id, string $name, string $icon): void
    {
        if (self::find($id) === null) {
            throw new HttpException('Категория не найдена', 404);
        }
        [$name, $icon] = self::validate($name, $icon);
        self::assertUnique($name, $id);
        Database::run('UPDATE categories SET name = ?, icon = ? WHERE id = ?', [$name, $icon, $id]);
    }

    /**
     * Удаляет категорию. Объявления остаются, но без категории (FK ON DELETE SET NULL).
     * @return int сколько объявлений осталось без категории
     */
    public static function delete(int $id): int
    {
        if (self::find($id) === null) {
            throw new HttpException('Категория не найдена', 404);
        }
        $affected = (int) Database::value('SELECT COUNT(*) FROM ads WHERE category_id = ?', [$id]);
        Database::run('DELETE FROM categories WHERE id = ?', [$id]);
        return $affected;
    }

    /** @return array{0:string,1:string} */
    private static function validate(string $name, string $icon): array
    {
        $name = trim($name);
        $icon = trim($icon) !== '' ? trim($icon) : '📦';
        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            $errors['name'] = 'Название: от 2 до 80 символов';
        }
        if (mb_strlen($icon) > 8) {
            $errors['icon'] = 'Укажите один эмодзи';
        }
        if ($errors) {
            throw new HttpException('Проверьте правильность заполнения полей', 422, $errors);
        }
        return [$name, $icon];
    }

    private static function assertUnique(string $name, ?int $exceptId): void
    {
        $existing = Database::value('SELECT id FROM categories WHERE name = ? AND id <> ?', [$name, $exceptId ?? 0]);
        if ($existing !== null) {
            throw new HttpException('Проверьте правильность заполнения полей', 422, ['name' => 'Категория с таким названием уже есть']);
        }
    }
}
