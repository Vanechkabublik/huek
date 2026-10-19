<?php
declare(strict_types=1);

namespace App;

/**
 * Ошибка с HTTP-статусом и (опционально) ошибками по полям формы.
 * Бросается из классов предметной области, а эндпоинты превращают её в JSON.
 */
final class HttpException extends \RuntimeException
{
    /** @param array<string,string> $errors ошибки по полям: ['email' => 'Неверный формат'] */
    public function __construct(
        string $message,
        private int $status = 400,
        private array $errors = []
    ) {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
