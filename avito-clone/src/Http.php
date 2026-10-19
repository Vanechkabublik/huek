<?php
declare(strict_types=1);

namespace App;

/**
 * Минимальный HTTP-клиент на curl для обращений к внешним API (VK ID).
 */
final class Http
{
    /**
     * POST application/x-www-form-urlencoded → декодированный JSON.
     * @param array<string,scalar> $fields
     * @return array<string,mixed>
     * @throws \RuntimeException при сетевой ошибке или невалидном ответе
     */
    public static function postForm(string $url, array $fields, int $timeout = 10): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("Сетевая ошибка при запросе $url: $err");
        }
        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            throw new \RuntimeException("Ответ $url (HTTP $code) не является JSON");
        }
        $json['_http'] = $code;
        return $json;
    }
}
