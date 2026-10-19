<?php
declare(strict_types=1);

namespace App;

/**
 * Характеристики объявлений, зависящие от категории: у авто — марка/год/пробег,
 * у техники — бренд/состояние/гарантия и т. д. Описание полей хранится в category_attributes
 * (его редактирует админ), значения — в ads.attributes (JSONB).
 */
final class Attribute
{
    /** @var array<int,array<int,array<string,mixed>>>|null кэш на запрос */
    private static ?array $cache = null;

    /** @return array<int,array<string,mixed>> определения полей для категории */
    public static function forCategory(int $categoryId): array
    {
        return self::all()[$categoryId] ?? [];
    }

    /** @return array<int,array<int,array<string,mixed>>> категория → список определений */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            $rows = Database::all(
                'SELECT id, category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order
                 FROM category_attributes ORDER BY category_id, sort_order, id'
            );
            foreach ($rows as $r) {
                self::$cache[(int) $r['category_id']][] = self::normalize($r);
            }
        }
        return self::$cache;
    }

    public static function resetCache(): void
    {
        self::$cache = null;
    }

    /** @return array<string,mixed> */
    private static function normalize(array $r): array
    {
        $options = is_string($r['options']) ? json_decode($r['options'], true) : $r['options'];
        return [
            'id'         => (int) $r['id'],
            'category_id'=> (int) $r['category_id'],
            'code'       => $r['code'],
            'label'      => $r['label'],
            'type'       => $r['type'],
            'unit'       => $r['unit'],
            'options'    => is_array($options) ? array_values($options) : [],
            'min'        => $r['min_value'] !== null ? $r['min_value'] + 0 : null,
            'max'        => $r['max_value'] !== null ? $r['max_value'] + 0 : null,
            'required'   => (int) $r['required'] === 1,
            'filterable' => (int) $r['filterable'] === 1,
            'sort_order' => (int) $r['sort_order'],
        ];
    }

    /**
     * Проверяет значения характеристик из формы и возвращает то, что пойдёт в ads.attributes.
     * Ошибки собираются по ключам attr_<code>.
     *
     * @param array<string,mixed> $input
     * @param array<string,string> $errors  сюда дописываются ошибки
     * @return array<string,string|int|float>
     */
    public static function validate(int $categoryId, array $input, array &$errors): array
    {
        $values = [];
        foreach (self::forCategory($categoryId) as $d) {
            $code = $d['code'];
            $raw = $input[$code] ?? '';
            $raw = is_scalar($raw) ? trim((string) $raw) : '';
            $field = 'attr_' . $code;

            if ($raw === '') {
                if ($d['required']) {
                    $errors[$field] = 'Заполните поле «' . $d['label'] . '»';
                }
                continue;
            }

            switch ($d['type']) {
                case 'number':
                    $num = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $raw);
                    if (!is_numeric($num)) {
                        $errors[$field] = '«' . $d['label'] . '» — число';
                        break;
                    }
                    $num = $num + 0;
                    if (($d['min'] !== null && $num < $d['min']) || ($d['max'] !== null && $num > $d['max'])) {
                        $range = ($d['min'] !== null ? 'от ' . $d['min'] . ' ' : '') . ($d['max'] !== null ? 'до ' . $d['max'] : '');
                        $errors[$field] = '«' . $d['label'] . '»: ' . trim($range);
                        break;
                    }
                    $values[$code] = $num;
                    break;
                case 'select':
                    if (!in_array($raw, $d['options'], true)) {
                        $errors[$field] = 'Выберите «' . $d['label'] . '» из списка';
                        break;
                    }
                    $values[$code] = $raw;
                    break;
                default:
                    if (mb_strlen($raw) > 100) {
                        $errors[$field] = '«' . $d['label'] . '»: максимум 100 символов';
                        break;
                    }
                    $values[$code] = $raw;
            }
        }
        return $values;
    }

    /**
     * SQL-условия для фильтров по характеристикам выбранной категории.
     * Параметры запроса: a_<code> (список/текст) и a_<code>_min / a_<code>_max (числа).
     *
     * @param array<string,mixed> $input
     * @return array{0:string[],1:array<int,mixed>}
     */
    public static function filterClauses(int $categoryId, array $input): array
    {
        $clauses = [];
        $params  = [];
        $pdo = Database::pdo();
        foreach (self::forCategory($categoryId) as $d) {
            if (!$d['filterable']) {
                continue;
            }
            $code = $d['code'];                       // [a-z0-9_]: безопасно, но всё равно квотируем
            $text = 'a.attributes->>' . $pdo->quote($code);
            if ($d['type'] === 'number') {
                $num = 'CASE WHEN jsonb_typeof(a.attributes->' . $pdo->quote($code) . ") = 'number' THEN ($text)::numeric END";
                foreach (['min' => '>=', 'max' => '<='] as $suffix => $op) {
                    $v = $input["a_{$code}_{$suffix}"] ?? '';
                    if (is_scalar($v) && $v !== '' && is_numeric($v)) {
                        $clauses[] = "$num $op ?";
                        $params[] = $v + 0;
                    }
                }
            } else {
                $v = $input["a_$code"] ?? '';
                if (is_scalar($v) && trim((string) $v) !== '') {
                    $clauses[] = "$text = ?";
                    $params[] = trim((string) $v);
                }
            }
        }
        return [$clauses, $params];
    }

    /**
     * Готовит значения характеристик объявления к показу: подпись, значение с единицей.
     * @param array<string,mixed> $values
     * @return array<int,array{code:string,label:string,value:string}>
     */
    public static function display(int $categoryId, array $values): array
    {
        $out = [];
        foreach (self::forCategory($categoryId) as $d) {
            if (!isset($values[$d['code']]) || $values[$d['code']] === '') {
                continue;
            }
            $v = $values[$d['code']];
            if ($d['type'] === 'number') {
                // Годы («2018») не разбиваем на разряды, а пробег и площадь — разбиваем («64 000 км»)
                $yearLike = $d['min'] !== null && $d['max'] !== null && $d['min'] >= 1000 && $d['max'] <= 3000;
                $v = $yearLike ? (string) (int) $v : number_format((float) $v, fmod((float) $v, 1) === 0.0 ? 0 : 2, ',', "\u{00A0}");
            }
            $out[] = [
                'code'  => $d['code'],
                'label' => $d['label'],
                'value' => $v . ($d['unit'] ? "\u{00A0}" . $d['unit'] : ''),
            ];
        }
        return $out;
    }

    // ----------------------------------------------------------------- админка

    /**
     * Создаёт или обновляет описание характеристики категории.
     * @param array<string,mixed> $d
     */
    public static function save(int $categoryId, array $d, ?int $id = null): int
    {
        if (Category::find($categoryId) === null) {
            throw new HttpException('Категория не найдена', 404);
        }
        $errors = [];
        $code  = strtolower(trim((string) ($d['code'] ?? '')));
        $label = trim((string) ($d['label'] ?? ''));
        $type  = (string) ($d['type'] ?? '');
        $unit  = trim((string) ($d['unit'] ?? ''));
        $sort  = (int) ($d['sort_order'] ?? 0);

        if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $code)) {
            $errors['code'] = 'Код: латиница, цифры и «_», начинается с буквы';
        }
        if (mb_strlen($label) < 2 || mb_strlen($label) > 80) {
            $errors['label'] = 'Название: от 2 до 80 символов';
        }
        if (!in_array($type, ['text', 'number', 'select'], true)) {
            $errors['type'] = 'Выберите тип';
        }
        if (mb_strlen($unit) > 16) {
            $errors['unit'] = 'Единица: до 16 символов';
        }

        $options = [];
        if ($type === 'select') {
            $raw = $d['options'] ?? [];
            if (is_string($raw)) {
                $raw = preg_split('/\R/u', $raw) ?: [];
            }
            foreach ((array) $raw as $o) {
                $o = trim((string) $o);
                if ($o !== '' && mb_strlen($o) <= 60 && !in_array($o, $options, true)) {
                    $options[] = $o;
                }
            }
            if (count($options) < 1) {
                $errors['options'] = 'Добавьте хотя бы один вариант (по одному в строке)';
            }
        }
        $min = ($d['min'] ?? '') !== '' && is_numeric($d['min']) ? $d['min'] + 0 : null;
        $max = ($d['max'] ?? '') !== '' && is_numeric($d['max']) ? $d['max'] + 0 : null;
        if ($type !== 'number') {
            $min = $max = null;
        } elseif ($min !== null && $max !== null && $min > $max) {
            $errors['min'] = 'Минимум больше максимума';
        }

        if (!$errors) {
            $dup = Database::value(
                'SELECT id FROM category_attributes WHERE category_id = ? AND code = ? AND id <> ?',
                [$categoryId, $code, $id ?? 0]
            );
            if ($dup !== null) {
                $errors['code'] = 'Поле с таким кодом уже есть в этой категории';
            }
        }
        if ($errors) {
            throw new HttpException('Проверьте правильность заполнения полей', 422, $errors);
        }

        $params = [
            $categoryId, $code, $label, $type, $unit !== '' ? $unit : null,
            json_encode($options, JSON_UNESCAPED_UNICODE), $min, $max,
            empty($d['required']) ? 0 : 1, empty($d['filterable']) ? 0 : 1, $sort,
        ];
        self::resetCache();
        if ($id === null) {
            return Database::insert(
                'INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?)',
                $params
            );
        }
        if (Database::value('SELECT 1 FROM category_attributes WHERE id = ? AND category_id = ?', [$id, $categoryId]) === null) {
            throw new HttpException('Поле не найдено', 404);
        }
        Database::run(
            'UPDATE category_attributes SET category_id = ?, code = ?, label = ?, type = ?, unit = ?, options = ?::jsonb,
                    min_value = ?, max_value = ?, required = ?, filterable = ?, sort_order = ? WHERE id = ?',
            [...$params, $id]
        );
        return $id;
    }

    public static function delete(int $id): void
    {
        $n = Database::run('DELETE FROM category_attributes WHERE id = ?', [$id])->rowCount();
        if ($n === 0) {
            throw new HttpException('Поле не найдено', 404);
        }
        self::resetCache();
    }
}
