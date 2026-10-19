<?php
declare(strict_types=1);

namespace App;

/**
 * Лёгкий валидатор. Цепочка вызовов копит ошибки по полям;
 * для каждого поля сохраняется только первая ошибка.
 *
 *   $v = Validator::make($input)
 *       ->required('title', 'Введите заголовок')
 *       ->length('title', 5, 100, 'Заголовок');
 *   $v->validate();   // бросит HttpException(422) с ошибками по полям
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    private function __construct(private array $data)
    {
    }

    public static function make(array $data): self
    {
        return new self($data);
    }

    public function value(string $field): string
    {
        $v = $this->data[$field] ?? '';
        return is_scalar($v) ? trim((string) $v) : '';
    }

    private function has(string $field): bool
    {
        return isset($this->errors[$field]);
    }

    private function fail(string $field, string $message): self
    {
        $this->errors[$field] ??= $message;
        return $this;
    }

    public function required(string $field, string $message): self
    {
        if (!$this->has($field) && $this->value($field) === '') {
            $this->fail($field, $message);
        }
        return $this;
    }

    /** Длина строки в символах (UTF-8). Пустое значение пропускается — для него есть required(). */
    public function length(string $field, int $min, int $max, string $label): self
    {
        $v = $this->value($field);
        if ($this->has($field) || $v === '') {
            return $this;
        }
        $len = mb_strlen($v);
        if ($len < $min) {
            $this->fail($field, "$label: минимум $min " . self::plural($min, 'символ', 'символа', 'символов'));
        } elseif ($len > $max) {
            $this->fail($field, "$label: максимум $max " . self::plural($max, 'символ', 'символа', 'символов'));
        }
        return $this;
    }

    public function email(string $field): self
    {
        $v = $this->value($field);
        if (!$this->has($field) && $v !== '' && (!filter_var($v, FILTER_VALIDATE_EMAIL) || mb_strlen($v) > 190)) {
            $this->fail($field, 'Введите корректный email');
        }
        return $this;
    }

    /** Целое число в диапазоне. */
    public function intRange(string $field, int $min, int $max, string $message): self
    {
        $v = $this->value($field);
        if ($this->has($field) || $v === '') {
            return $this;
        }
        if (!preg_match('/^\d+$/', $v) || (int) $v < $min || (int) $v > $max) {
            $this->fail($field, $message);
        }
        return $this;
    }

    /** @param array<int,string> $allowed */
    public function in(string $field, array $allowed, string $message): self
    {
        if (!$this->has($field) && !in_array($this->value($field), $allowed, true)) {
            $this->fail($field, $message);
        }
        return $this;
    }

    public function matches(string $field, string $other, string $message): self
    {
        if (!$this->has($field) && $this->value($field) !== $this->value($other)) {
            $this->fail($field, $message);
        }
        return $this;
    }

    /** Необязательный телефон: 10–15 цифр, допустимы + пробелы скобки дефисы. */
    public function phone(string $field): self
    {
        $v = $this->value($field);
        if ($this->has($field) || $v === '') {
            return $this;
        }
        $digits = preg_replace('/\D/', '', $v);
        if (!preg_match('/^[\d\s()+\-]+$/', $v) || strlen($digits) < 10 || strlen($digits) > 15) {
            $this->fail($field, 'Введите телефон в формате +7 900 000-00-00');
        }
        return $this;
    }

    public function custom(string $field, callable $check, string $message): self
    {
        if (!$this->has($field) && !$check($this->value($field))) {
            $this->fail($field, $message);
        }
        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** Бросает HttpException(422), если есть ошибки. */
    public function validate(): void
    {
        if ($this->fails()) {
            throw new HttpException('Проверьте правильность заполнения полей', 422, $this->errors);
        }
    }

    public static function plural(int $n, string $one, string $few, string $many): string
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) {
            return $many;
        }
        if ($n1 > 1 && $n1 < 5) {
            return $few;
        }
        return $n1 === 1 ? $one : $many;
    }
}
