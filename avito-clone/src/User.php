<?php
declare(strict_types=1);

namespace App;

/**
 * Пользователи: поиск, регистрация, профиль, привязка VK, публичная карточка продавца.
 */
final class User
{
    /** Колонки без password_hash — их безопасно отдавать в шаблоны. */
    private const SAFE_COLUMNS = 'id, email, name, phone, role, is_blocked, created_at, avatar_url, about, vk_id, last_login_at,
                                  (password_hash IS NOT NULL) AS has_password';

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

    public static function findByVk(string $vkId): ?array
    {
        return Database::one('SELECT ' . self::SAFE_COLUMNS . ' FROM users WHERE vk_id = ?', [$vkId]);
    }

    public static function emailExists(string $email, ?int $exceptId = null): bool
    {
        return Database::value(
            'SELECT 1 FROM users WHERE email = ? AND id <> ?',
            [mb_strtolower(trim($email)), $exceptId ?? 0]
        ) !== null;
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
        return Database::insert(
            'INSERT INTO users (email, password_hash, name, phone, role) VALUES (?, ?, ?, ?, ?)',
            [
                mb_strtolower(trim($data['email'])),
                password_hash($data['password'], PASSWORD_DEFAULT),
                trim($data['name']),
                ($data['phone'] ?? '') !== '' ? Format::phone($data['phone']) : null,
                'user',
            ]
        );
    }

    /** Создаёт пользователя, пришедшего через VK (без пароля; email может отсутствовать). */
    public static function createFromVk(string $vkId, string $name, ?string $email, ?string $avatar, ?string $phone): int
    {
        $email = $email !== null && $email !== '' && !self::emailExists($email) ? mb_strtolower(trim($email)) : null;
        $name = trim($name) !== '' ? mb_substr(trim($name), 0, 100) : 'Пользователь VK';
        return Database::insert(
            "INSERT INTO users (email, password_hash, name, phone, role, vk_id, avatar_url, last_login_at)
             VALUES (?, NULL, ?, ?, 'user', ?, ?, NOW())",
            [$email, $name, $phone ? Format::phone($phone) : null, $vkId, $avatar ? mb_substr($avatar, 0, 500) : null]
        );
    }

    public static function setPassword(int $id, string $password): void
    {
        Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
    }

    public static function touchLogin(int $id): void
    {
        Database::run('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$id]);
    }

    public static function linkVk(int $userId, string $vkId, ?string $avatar): void
    {
        $other = Database::value('SELECT id FROM users WHERE vk_id = ? AND id <> ?', [$vkId, $userId]);
        if ($other !== null) {
            throw new HttpException('Этот аккаунт VK уже привязан к другому профилю', 409);
        }
        Database::run(
            'UPDATE users SET vk_id = ?, avatar_url = COALESCE(avatar_url, ?) WHERE id = ?',
            [$vkId, $avatar, $userId]
        );
    }

    /** Отвязка VK возможна только если есть другой способ войти — пароль. */
    public static function unlinkVk(int $userId): void
    {
        $u = self::find($userId);
        if ($u === null || $u['vk_id'] === null) {
            return;
        }
        if (!$u['has_password'] || empty($u['email'])) {
            throw new HttpException('Сначала задайте email и пароль, иначе вы не сможете войти в аккаунт', 422);
        }
        Database::run('UPDATE users SET vk_id = NULL WHERE id = ?', [$userId]);
    }

    /**
     * Обновляет профиль.
     * @param array<string,mixed> $in name, phone, about, [email]
     */
    public static function updateProfile(int $id, array $in): void
    {
        $v = Validator::make($in)
            ->required('name', 'Введите имя')
            ->length('name', 2, 100, 'Имя')
            ->phone('phone')
            ->length('about', 0, 500, 'О себе');
        if ($v->value('email') !== '') {
            $v->email('email');
        }
        $errors = $v->errors();
        $email = mb_strtolower($v->value('email'));
        if (!isset($errors['email']) && $email !== '' && self::emailExists($email, $id)) {
            $errors['email'] = 'Этот email уже занят';
        }
        if ($errors) {
            throw new HttpException('Проверьте правильность заполнения полей', 422, $errors);
        }
        $cur = self::find($id);
        // Email можно задать, если его не было (VK-аккаунт), и сменить; удалить — нельзя, если нет VK
        $newEmail = $email !== '' ? $email : ($cur['email'] ?? null);
        Database::run(
            'UPDATE users SET name = ?, phone = ?, about = ?, email = ? WHERE id = ?',
            [
                $v->value('name'),
                $v->value('phone') !== '' ? Format::phone($v->value('phone')) : null,
                $v->value('about') !== '' ? $v->value('about') : null,
                $newEmail,
                $id,
            ]
        );
    }

    public static function setAvatar(int $id, ?string $url): ?string
    {
        $old = Database::value('SELECT avatar_url FROM users WHERE id = ?', [$id]);
        Database::run('UPDATE users SET avatar_url = ? WHERE id = ?', [$url, $id]);
        return is_string($old) ? $old : null;
    }

    /** URL аватара или null (тогда показываем букву). */
    public static function avatarUrl(?array $u): ?string
    {
        return !empty($u['avatar_url']) ? (string) $u['avatar_url'] : null;
    }

    /**
     * Публичные данные продавца для карточки и страницы профиля.
     * @return array<string,mixed>|null
     */
    public static function sellerProfile(int $id): ?array
    {
        $u = self::find($id);
        if ($u === null) {
            return null;
        }
        $rating = Review::summary($id);
        return [
            'id'           => (int) $u['id'],
            'name'         => $u['name'],
            'initial'      => mb_strtoupper(mb_substr($u['name'], 0, 1)),
            'avatar'       => self::avatarUrl($u),
            'about'        => $u['about'],
            'member_since' => Format::memberSince($u['created_at']),
            'ads_count'    => (int) Database::value(
                "SELECT COUNT(*) FROM ads WHERE user_id = ? AND status = 'approved'",
                [$id]
            ),
            'has_phone'    => !empty($u['phone']),
            'vk_verified'  => $u['vk_id'] !== null,
            'is_blocked'   => (bool) $u['is_blocked'],
            'rating'       => $rating['avg'],
            'reviews'      => $rating['count'],
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
            'avatar'  => self::avatarUrl($u),
        ];
    }
}
