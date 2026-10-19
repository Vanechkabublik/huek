<?php
declare(strict_types=1);

namespace App;

/**
 * Пользователи: поиск, регистрация, профиль, привязанные аккаунты Google/Яндекс, публичная карточка продавца.
 */
final class User
{
    /** Колонки без password_hash — их безопасно отдавать в шаблоны. */
    private const SAFE_COLUMNS = 'id, email, name, phone, role, is_blocked, created_at, avatar_url, about, last_login_at,
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

    /** Пользователь, который входит через этого провайдера под этим внешним id. */
    public static function findByIdentity(string $provider, string $externalId): ?array
    {
        return Database::one(
            'SELECT ' . self::SAFE_COLUMNS . ' FROM users WHERE id = (SELECT user_id FROM user_identities WHERE provider = ? AND external_id = ?)',
            [$provider, $externalId]
        );
    }

    /**
     * Привязанные аккаунты пользователя.
     * @return array<string,array{provider:string,email:?string,created_at:string}> ключ — код провайдера
     */
    public static function identities(int $userId): array
    {
        $out = [];
        foreach (Database::all('SELECT provider, email, created_at FROM user_identities WHERE user_id = ? ORDER BY id', [$userId]) as $r) {
            $out[$r['provider']] = $r;
        }
        return $out;
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

    /**
     * Создаёт пользователя, пришедшего через Google/Яндекс (без пароля; email может отсутствовать).
     * @param ?string $avatar URL уже сохранённого у нас аватара
     */
    public static function createFromIdentity(string $provider, string $externalId, string $name, ?string $email, ?string $avatar): int
    {
        $email = $email !== null && $email !== '' && !self::emailExists($email) ? mb_strtolower(trim($email)) : null;
        $name = trim($name) !== '' ? mb_substr(trim($name), 0, 100) : 'Пользователь';
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $id = Database::insert(
                "INSERT INTO users (email, password_hash, name, role, avatar_url, last_login_at) VALUES (?, NULL, ?, 'user', ?, NOW())",
                [$email, $name, $avatar]
            );
            Database::run(
                'INSERT INTO user_identities (user_id, provider, external_id, email) VALUES (?, ?, ?, ?)',
                [$id, $provider, $externalId, $email]
            );
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function setPassword(int $id, string $password): void
    {
        Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
    }

    public static function touchLogin(int $id): void
    {
        Database::run('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$id]);
    }

    public static function linkIdentity(int $userId, string $provider, string $externalId, ?string $email = null): void
    {
        $other = Database::value('SELECT user_id FROM user_identities WHERE provider = ? AND external_id = ?', [$provider, $externalId]);
        if ($other !== null && (int) $other !== $userId) {
            throw new HttpException('Этот аккаунт ' . OAuth::name($provider) . ' уже привязан к другому профилю', 409);
        }
        // «Заменить» аккаунт того же провайдера не даём молча: сначала отвяжите прежний
        $mine = Database::value('SELECT external_id FROM user_identities WHERE user_id = ? AND provider = ?', [$userId, $provider]);
        if ($mine !== null && $mine !== $externalId) {
            throw new HttpException('К профилю уже привязан другой аккаунт ' . OAuth::name($provider) . '. Сначала отвяжите его.', 409);
        }
        Database::run(
            'INSERT INTO user_identities (user_id, provider, external_id, email) VALUES (?, ?, ?, ?) ON CONFLICT DO NOTHING',
            [$userId, $provider, $externalId, $email]
        );
    }

    /** Отвязка возможна только если останется другой способ войти: пароль или другой аккаунт. */
    public static function unlinkIdentity(int $userId, string $provider): void
    {
        $u = self::find($userId);
        $ids = self::identities($userId);
        if ($u === null || !isset($ids[$provider])) {
            return;
        }
        $canPassword = $u['has_password'] && !empty($u['email']);
        if (!$canPassword && count($ids) < 2) {
            throw new HttpException('Сначала задайте email и пароль (или привяжите другой аккаунт) — иначе вы не сможете войти', 422);
        }
        Database::run('DELETE FROM user_identities WHERE user_id = ? AND provider = ?', [$userId, $provider]);
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
        // Email можно задать, если его не было (вход через Google/Яндекс), и сменить; стереть поле — значит оставить прежний
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
            'social'       => array_keys(self::identities((int) $u['id'])),
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
