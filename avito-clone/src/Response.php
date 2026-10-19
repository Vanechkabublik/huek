<?php
declare(strict_types=1);

namespace App;

/**
 * JSON-ответы эндпоинтов. Формат:
 *   успех:  {"ok": true, ...данные}
 *   ошибка: {"ok": false, "error": "текст", "errors": {"поле": "текст"}}
 */
final class Response
{
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }

    public static function ok(array $data = [], int $status = 200): never
    {
        self::json(['ok' => true] + $data, $status);
    }

    /** @param array<string,string> $errors */
    public static function error(string $message, int $status = 400, array $errors = []): never
    {
        $payload = ['ok' => false, 'error' => $message];
        if ($errors) {
            $payload['errors'] = $errors;
        }
        self::json($payload, $status);
    }

    public static function redirect(string $url): never
    {
        header('Location: ' . $url, true, 302);
        exit;
    }

    /**
     * Единый обработчик исключений для JSON-эндпоинтов:
     * HttpException → её статус, всё остальное → 500 (без утечки деталей в проде).
     */
    public static function handleExceptions(): void
    {
        set_exception_handler(static function (\Throwable $e): void {
            if ($e instanceof HttpException) {
                self::error($e->getMessage(), $e->status(), $e->errors());
            }
            error_log((string) $e);
            self::error(
                APP_DEBUG ? $e->getMessage() : 'Внутренняя ошибка сервера. Попробуйте позже.',
                500
            );
        });
    }
}
