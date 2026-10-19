<?php
declare(strict_types=1);

namespace App;

/**
 * Объявления: поиск и выдача, создание/редактирование, статусы, просмотры.
 */
final class Ad
{
    public const PER_PAGE   = 12;
    public const MAX_IMAGES = 10;
    public const HIT_VIEWS  = 100;      // с такого числа просмотров объявление получает бейдж «Хит»

    public const STATUS_LABELS = [
        'pending'  => 'На модерации',
        'approved' => 'Одобрено',
        'rejected' => 'Отклонено',
        'blocked'  => 'Заблокировано',
    ];

    /** SELECT для списков: объявление + категория + автор + обложка. Описание не тянем. */
    public static function baseSelect(string $extraColumns = ''): string
    {
        return "SELECT a.id, a.user_id, a.category_id, a.title, a.price, a.city, a.status,
                       a.rejection_reason, a.views, a.created_at, a.attributes, a.contact_method, a.contact_hours, a.video $extraColumns,
                       c.name AS category_name, c.icon AS category_icon,
                       u.email AS user_email, u.name AS user_name, u.is_blocked AS user_blocked,
                       (SELECT filename FROM ad_images WHERE ad_id = a.id ORDER BY id LIMIT 1) AS cover,
                       (SELECT COUNT(*) FROM ad_images WHERE ad_id = a.id) AS images_count,
                       (SELECT COUNT(*) FROM favorites WHERE ad_id = a.id) AS fav_count
                FROM ads a
                JOIN users u ON u.id = a.user_id
                LEFT JOIN categories c ON c.id = a.category_id";
    }

    /**
     * Публичная выдача (только одобренные объявления незаблокированных авторов).
     *
     * @param array<string,mixed> $f q, category, city, price_min, price_max, sort, user, exclude и a_<код> / a_<код>_min / a_<код>_max
     *                               (фильтры по характеристикам выбранной категории)
     * @return array{items:array,total:int,page:int,pages:int,per_page:int,has_more:bool}
     */
    public static function search(array $f, int $page = 1, ?int $viewerId = null): array
    {
        $where  = ["a.status = 'approved'", 'u.is_blocked = 0'];
        $params = [];

        // Поиск по словам: каждое слово должно встретиться в заголовке или описании
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            foreach (array_slice(preg_split('/\s+/u', $q) ?: [], 0, 6) as $word) {
                $like = '%' . addcslashes($word, '%_\\') . '%';  // в PostgreSQL escape-символ LIKE по умолчанию — обратный слэш
                $where[] = "(a.title ILIKE ? OR a.description ILIKE ?)";
                array_push($params, $like, $like);
            }
        }
        if (!empty($f['category'])) {
            $where[] = 'a.category_id = ?';
            $params[] = (int) $f['category'];
        }
        $city = trim((string) ($f['city'] ?? ''));
        if ($city !== '') {
            $where[] = 'a.city = ?';
            $params[] = $city;
        }
        if (($f['price_min'] ?? '') !== '' && is_numeric($f['price_min'])) {
            $where[] = 'a.price >= ?';
            $params[] = max(0, (int) $f['price_min']);
        }
        if (($f['price_max'] ?? '') !== '' && is_numeric($f['price_max'])) {
            $where[] = 'a.price <= ?';
            $params[] = max(0, (int) $f['price_max']);
        }
        if (!empty($f['user'])) {
            $where[] = 'a.user_id = ?';
            $params[] = (int) $f['user'];
        }
        if (!empty($f['exclude'])) {
            $where[] = 'a.id <> ?';
            $params[] = (int) $f['exclude'];
        }
        if (!empty($f['category'])) {
            [$attrWhere, $attrParams] = Attribute::filterClauses((int) $f['category'], $f);
            array_push($where, ...$attrWhere);
            array_push($params, ...$attrParams);
        }

        $order = match ($f['sort'] ?? 'date') {
            'price_asc'  => 'a.price ASC, a.id DESC',
            'price_desc' => 'a.price DESC, a.id DESC',
            default      => 'a.created_at DESC, a.id DESC',
        };

        $whereSql = implode(' AND ', $where);
        $total = (int) Database::value(
            "SELECT COUNT(*) FROM ads a JOIN users u ON u.id = a.user_id WHERE $whereSql",
            $params
        );

        $page   = max(1, $page);
        $limit  = self::PER_PAGE;
        $offset = ($page - 1) * $limit;
        $rows = Database::all(self::baseSelect() . " WHERE $whereSql ORDER BY $order LIMIT $limit OFFSET $offset", $params);

        $items = array_map(static fn(array $r) => self::card($r), $rows);
        Favorite::mark($items, $viewerId);

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'pages'    => max(1, (int) ceil($total / $limit)),
            'per_page' => $limit,
            'has_more' => $offset + count($rows) < $total,
        ];
    }

    /**
     * Приводит строку БД к виду, который отдаём в JSON.
     * $private = true добавляет причину отказа и данные автора (для владельца и админа).
     * @return array<string,mixed>
     */
    public static function card(array $r, bool $private = false): array
    {
        $views = (int) $r['views'];
        $out = [
            'id'            => (int) $r['id'],
            'title'         => $r['title'],
            'price'         => (int) $r['price'],
            'price_text'    => Format::price((int) $r['price']),
            'city'          => $r['city'],
            'category_id'   => $r['category_id'] !== null ? (int) $r['category_id'] : null,
            'category_name' => $r['category_name'] ?? 'Без категории',
            'category_icon' => Category::iconKey((string) ($r['category_name'] ?? '')),
            'status'        => $r['status'],
            'status_label'  => self::STATUS_LABELS[$r['status']] ?? $r['status'],
            'date_text'     => Format::date($r['created_at']),
            'views'         => $views,
            'is_hit'        => $views >= self::HIT_VIEWS,
            'cover'         => !empty($r['cover']) ? Upload::thumbUrl($r['cover']) : null,
            'images_count'  => (int) $r['images_count'],
            'has_video'     => !empty($r['video']),
            'url'           => '/ad.php?id=' . (int) $r['id'],
            'specs'         => self::specs($r),
            'is_favorite'   => false,
        ];
        if ($private) {
            $out += [
                'fav_count'        => (int) ($r['fav_count'] ?? 0),
                'rejection_reason' => $r['rejection_reason'],
                'user_id'          => (int) $r['user_id'],
                'user_email'       => $r['user_email'],
                'user_name'        => $r['user_name'],
                'date_full'        => Format::dateFull($r['created_at']),
            ];
        }
        return $out;
    }

    /** @return array<string,mixed> характеристики объявления (JSONB → массив) */
    public static function decodeAttributes(mixed $raw): array
    {
        $a = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($a) ? $a : [];
    }

    /** Краткая строка характеристик для карточки: «2018 · 64 000 км · Автомат». */
    private static function specs(array $r): string
    {
        if ($r['category_id'] === null) {
            return '';
        }
        $parts = [];
        foreach (Attribute::display((int) $r['category_id'], self::decodeAttributes($r['attributes'] ?? null)) as $a) {
            $def = array_values(array_filter(Attribute::forCategory((int) $r['category_id']), static fn($d) => $d['code'] === $a['code']))[0] ?? null;
            if ($def && in_array($def['type'], ['number', 'select'], true)) {
                $parts[] = $a['value'];
            }
            if (count($parts) === 3) {
                break;
            }
        }
        return implode(' · ', $parts);
    }

    /**
     * Полное объявление для страницы просмотра. Возвращает null, если оно
     * недоступно зрителю (не одобрено и зритель — не владелец и не админ).
     * @return array<string,mixed>|null
     */
    public static function get(int $id, ?array $viewer): ?array
    {
        $row = Database::one(self::baseSelect(', a.description') . ' WHERE a.id = ?', [$id]);
        if ($row === null) {
            return null;
        }
        $isOwner = $viewer !== null && (int) $viewer['id'] === (int) $row['user_id'];
        $isAdmin = $viewer !== null && $viewer['role'] === 'admin';
        $public  = $row['status'] === 'approved' && (int) $row['user_blocked'] === 0;
        if (!$public && !$isOwner && !$isAdmin) {
            return null;
        }

        $ad = self::card($row, $isOwner || $isAdmin);
        $ad['description'] = $row['description'];
        $ad['user_id']     = (int) $row['user_id'];
        $ad['contact_method'] = $row['contact_method'];
        $ad['contact_hours']  = $row['contact_hours'];
        $ad['video_url']      = !empty($row['video']) ? Upload::url($row['video']) : null;
        $ad['video']          = $row['video'];
        $attrs = self::decodeAttributes($row['attributes']);
        $ad['attributes']      = $attrs;
        $ad['attribute_list']  = $row['category_id'] !== null ? Attribute::display((int) $row['category_id'], $attrs) : [];
        $ad['is_favorite']     = $viewer !== null && Favorite::has((int) $viewer['id'], $id);
        $ad['is_owner']    = $isOwner;
        $ad['can_edit']    = $isOwner && $row['status'] !== 'blocked';
        $ad['images']      = array_map(static fn(array $i) => [
            'id'       => (int) $i['id'],
            'filename' => $i['filename'],
            'url'      => Upload::url($i['filename']),
            'thumb'    => Upload::thumbUrl($i['filename']),
        ], Database::all('SELECT id, filename FROM ad_images WHERE ad_id = ? ORDER BY id', [$id]));
        $ad['seller']      = User::sellerProfile((int) $row['user_id']);
        return $ad;
    }

    /** Телефон продавца — только для публично видимого объявления. */
    public static function sellerPhone(int $id): ?string
    {
        $row = Database::one(
            "SELECT u.phone, a.contact_method FROM ads a JOIN users u ON u.id = a.user_id
             WHERE a.id = ? AND a.status = 'approved' AND u.is_blocked = 0",
            [$id]
        );
        if ($row === null) {
            throw new HttpException('Объявление не найдено', 404);
        }
        if ($row['contact_method'] === 'chat') {
            throw new HttpException('Автор общается только в чате на сайте — напишите ему сообщение', 403);
        }
        $phone = $row['phone'];
        return $phone !== '' ? Format::phone($phone) : null;
    }

    /**
     * Объявления пользователя (для «Мои объявления») + счётчики по статусам.
     * @return array{items:array,counts:array<string,int>}
     */
    public static function byUser(int $userId, string $status = ''): array
    {
        $params = [$userId];
        $where = 'a.user_id = ?';
        if (isset(self::STATUS_LABELS[$status])) {
            $where .= ' AND a.status = ?';
            $params[] = $status;
        }
        $rows = Database::all(self::baseSelect() . " WHERE $where ORDER BY a.created_at DESC, a.id DESC", $params);

        $counts = array_fill_keys(array_keys(self::STATUS_LABELS), 0);
        foreach (Database::all('SELECT status, COUNT(*) AS n FROM ads WHERE user_id = ? GROUP BY status', [$userId]) as $c) {
            $counts[$c['status']] = (int) $c['n'];
        }
        $counts['all'] = array_sum($counts);

        return [
            'items'  => array_map(static fn(array $r) => self::card($r, true), $rows),
            'counts' => $counts,
        ];
    }

    /** Города, в которых есть опубликованные объявления (для фильтра). @return string[] */
    public static function cities(): array
    {
        return array_column(Database::all(
            "SELECT a.city FROM ads a JOIN users u ON u.id = a.user_id
             WHERE a.status = 'approved' AND u.is_blocked = 0
             GROUP BY a.city ORDER BY a.city"
        ), 'city');
    }

    // ------------------------------------------------------------------ запись

    /**
     * Создаёт объявление (статус «на модерации») и привязывает загруженные фото.
     * @return int id нового объявления
     */
    public static function create(int $userId, array $input): int
    {
        $data = self::validate($input, [], null, !empty(User::find($userId)['phone']));

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $adId = Database::insert(
                "INSERT INTO ads (user_id, category_id, title, description, price, city, status, attributes, contact_method, contact_hours, video)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending', ?::jsonb, ?, ?, ?)",
                [$userId, $data['category_id'], $data['title'], $data['description'], $data['price'], $data['city'],
                 json_encode($data['attributes'], JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
                 $data['contact_method'], $data['contact_hours'], $data['video']]
            );
            foreach ($data['images'] as $filename) {
                Database::run('INSERT INTO ad_images (ad_id, filename) VALUES (?, ?)', [$adId, $filename]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        array_map([Upload::class, 'release'], array_filter([...$data['images'], $data['video']]));
        return $adId;
    }

    /**
     * Редактирование владельцем. Объявление всегда возвращается на модерацию.
     */
    public static function update(int $id, int $userId, array $input): void
    {
        $ad = Database::one('SELECT id, user_id, status, video FROM ads WHERE id = ?', [$id]);
        if ($ad === null || (int) $ad['user_id'] !== $userId) {
            throw new HttpException('Объявление не найдено', 404);
        }
        if ($ad['status'] === 'blocked') {
            throw new HttpException('Заблокированное объявление нельзя редактировать', 403);
        }

        $current = array_column(Database::all('SELECT filename FROM ad_images WHERE ad_id = ?', [$id]), 'filename');
        $data = self::validate($input, $current, $ad['video'] ?: null, !empty(User::find($userId)['phone']));

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            Database::run(
                "UPDATE ads SET category_id = ?, title = ?, description = ?, price = ?, city = ?, attributes = ?::jsonb,
                                contact_method = ?, contact_hours = ?, video = ?,
                                status = 'pending', rejection_reason = NULL, updated_at = NOW()
                 WHERE id = ?",
                [$data['category_id'], $data['title'], $data['description'], $data['price'], $data['city'],
                 json_encode($data['attributes'], JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
                 $data['contact_method'], $data['contact_hours'], $data['video'], $id]
            );
            $removed = array_diff($current, $data['images']);
            $added   = array_diff($data['images'], $current);
            foreach ($removed as $filename) {
                Database::run('DELETE FROM ad_images WHERE ad_id = ? AND filename = ?', [$id, $filename]);
            }
            foreach ($added as $filename) {
                Database::run('INSERT INTO ad_images (ad_id, filename) VALUES (?, ?)', [$id, $filename]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        array_map([Upload::class, 'remove'], $removed);
        array_map([Upload::class, 'release'], $added);
        // Видео заменили или убрали — старый файл удаляем; новое выпускаем из «ожидающих»
        if (!empty($ad['video']) && $ad['video'] !== $data['video']) {
            Upload::remove($ad['video']);
        }
        if ($data['video'] !== null) {
            Upload::release($data['video']);
        }
    }

    /** Удаление: владельцем или админом. Файлы фото удаляются с диска. */
    public static function delete(int $id, array $actor): void
    {
        $ad = Database::one('SELECT id, user_id, video FROM ads WHERE id = ?', [$id]);
        if ($ad === null) {
            throw new HttpException('Объявление не найдено', 404);
        }
        if ((int) $ad['user_id'] !== (int) $actor['id'] && $actor['role'] !== 'admin') {
            throw new HttpException('Недостаточно прав', 403);
        }
        $files = array_column(Database::all('SELECT filename FROM ad_images WHERE ad_id = ?', [$id]), 'filename');
        if (!empty($ad['video'])) {
            $files[] = $ad['video'];
        }
        Database::run('DELETE FROM ads WHERE id = ?', [$id]);    // ad_images и messages удалятся каскадом
        array_map([Upload::class, 'remove'], $files);
    }

    // -------------------------------------------------------------- валидация

    /**
     * Проверяет поля формы объявления.
     * @param string[] $attached файлы, уже привязанные к редактируемому объявлению
     * @param ?string $currentVideo видеофайл, уже привязанный к редактируемому объявлению
     * @param bool $hasPhone указан ли телефон в профиле автора (для способа связи «только звонок»)
     * @return array{title:string,description:string,price:int,city:string,category_id:int,attributes:array,images:string[],contact_method:string,contact_hours:?string,video:?string}
     */
    private static function validate(array $input, array $attached, ?string $currentVideo = null, bool $hasPhone = true): array
    {
        // «1 500 000» и «1500000 ₽» → «1500000»
        $input['price'] = preg_replace('/[\s\x{00A0}₽]/u', '', (string) ($input['price'] ?? ''));

        $v = Validator::make($input)
            ->required('title', 'Введите заголовок')
            ->length('title', 5, 100, 'Заголовок')
            ->required('category_id', 'Выберите категорию')
            ->custom('category_id', static fn(string $id) => ctype_digit($id) && Category::find((int) $id) !== null, 'Выберите категорию из списка')
            ->required('price', 'Укажите цену (0 — бесплатно)')
            ->intRange('price', 0, 999_999_999, 'Цена: целое число от 0 до 999 999 999')
            ->required('city', 'Укажите город')
            ->length('city', 2, 80, 'Город')
            ->required('description', 'Опишите товар или услугу')
            ->length('description', 20, 5000, 'Описание');

        // Фото: минимум 1, максимум 10; прикрепить можно только свои загруженные файлы
        $images = [];
        foreach ((array) ($input['images'] ?? []) as $name) {
            if (is_string($name) && !in_array($name, $images, true)) {
                $images[] = $name;
            }
        }
        $errors = $v->errors();

        // Характеристики зависят от категории (марка/год для авто, бренд/состояние для техники …)
        $attributes = [];
        if (!isset($errors['category_id'])) {
            $rawAttrs = is_array($input['attributes'] ?? null) ? $input['attributes'] : [];
            $attributes = Attribute::validate((int) $v->value('category_id'), $rawAttrs, $errors);
        }

        if (count($images) < 1) {
            $errors['images'] = 'Добавьте хотя бы одно фото';
        } elseif (count($images) > self::MAX_IMAGES) {
            $errors['images'] = 'Не больше ' . self::MAX_IMAGES . ' фото';
        } else {
            foreach ($images as $name) {
                $isMine = in_array($name, $attached, true) || (Upload::isManaged($name) && Upload::isPending($name));
                if (!$isMine) {
                    $errors['images'] = 'Одно из фото недоступно — загрузите его заново';
                    break;
                }
            }
        }

        // Способ связи: звонок и чат / только чат / только звонок (для звонка в профиле должен быть телефон)
        $method = (string) ($input['contact_method'] ?? 'any');
        if (!in_array($method, ['any', 'chat', 'phone'], true)) {
            $errors['contact_method'] = 'Выберите способ связи из списка';
        } elseif ($method === 'phone' && !$hasPhone) {
            $errors['contact_method'] = 'Чтобы принимать звонки, укажите телефон в профиле';
        }
        $hours = trim((string) ($input['contact_hours'] ?? ''));
        if (mb_strlen($hours) > 60) {
            $errors['contact_hours'] = 'Не длиннее 60 символов';
        }

        // Видеообзор (необязательно): только загруженный этим пользователем в сессии или уже привязанный к объявлению
        $video = trim((string) ($input['video'] ?? ''));
        if ($video !== '') {
            $ok = Upload::isVideoName($video) && ($video === $currentVideo || Upload::isPending($video));
            if (!$ok) {
                $errors['video'] = 'Видео недоступно — загрузите его заново';
            }
        }

        if ($errors) {
            throw new HttpException('Проверьте правильность заполнения полей', 422, $errors);
        }

        return [
            'title'       => $v->value('title'),
            'description' => $v->value('description'),
            'price'       => (int) $v->value('price'),
            'city'        => $v->value('city'),
            'category_id' => (int) $v->value('category_id'),
            'attributes'  => $attributes,
            'images'      => $images,
            'contact_method' => $method,
            'contact_hours'  => $hours !== '' ? $hours : null,
            'video'          => $video !== '' ? $video : null,
        ];
    }
}
