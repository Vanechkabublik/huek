<?php
declare(strict_types=1);

namespace App;

/**
 * Пользователи: поиск, регистрация, публичный профиль продавца.
 */
final class User
{
    /** Колонки без password_hash — их безопасно отдавать в шаблоны. */
    private const SAFE_COLUMNS = 'id, email, name, phone, role, is_blocked, created_at';

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::one('SELECT ' . self::SAFE_COLUMNS . ' FROM users WHERE id = ?', [$id]);
    }

    /** Запись вместе с хешем пароля — только для проверки при входе. */
    public static function findForLogin(string $email): ?array
    {
        return Database::one('SELECT * FROM users WHERE email = ?', [mb_strtolower(trim($email))]);
    }

    public static function emailExists(string $email): bool
    {
        return Database::value('SELECT 1 FROM users WHERE email = ?', [mb_strtolower(trim($email))]) !== null;
    }

    /**
     * Создаёт обычного пользователя.
     * @param array{email:string,password:string,name:string,phone?:string} $data
     */
    public static function create(array $data): int
    {
        if (self::emailExists($data['email'])) {
            throw new HttpException('Проверьте правильность заполнения полей', 422, ['email' => 'Этот email уже зарегистрирован']);
        }
        Database::run(
            'INSERT INTO users (email, password_hash, name, phone, role) VALUES (?, ?, ?, ?, ?)',
            [
                mb_strtolower(trim($data['email'])),
                password_hash($data['password'], PASSWORD_DEFAULT),
                trim($data['name']),
                ($data['phone'] ?? '') !== '' ? Format::phone($data['phone']) : null,
                'user',
            ]
        );
        return (int) Database::pdo()->lastInsertId();
    }

    public static function setPassword(int $id, string $password): void
    {
        Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
    }

    /**
     * Публичные данные продавца для карточки на странице объявления.
     * @return array<string,mixed>|null
     */
    public static function sellerProfile(int $id): ?array
    {
        $u = self::find($id);
        if ($u === null) {
            return null;
        }
        return [
            'id'           => (int) $u['id'],
            'name'         => $u['name'],
            'initial'      => mb_strtoupper(mb_substr($u['name'], 0, 1)),
            'member_since' => Format::memberSince($u['created_at']),
            'ads_count'    => (int) Database::value(
                "SELECT COUNT(*) FROM ads WHERE user_id = ? AND status = 'approved'",
                [$id]
            ),
            'has_phone'    => !empty($u['phone']),
        ];
    }

    /** Данные текущего пользователя для JS (window.__APP__.user). */
    public static function forClient(?array $u): ?array
    {
        if ($u === null) {
            return null;
        }
        return [
            'id'      => (int) $u['id'],
            'name'    => $u['name'],
            'email'   => $u['email'],
            'role'    => $u['role'],
            'initial' => mb_strtoupper(mb_substr($u['name'], 0, 1)),
        ];
    }
}
