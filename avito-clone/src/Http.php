<?php
declare(strict_types=1);

namespace App;

/**
 * Минимальный HTTP-клиент на curl для обращений к внешним API (Google, Яндекс).
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
        return self::request($url, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
        ], ['Content-Type: application/x-www-form-urlencoded'], $timeout);
    }

    /**
     * GET с заголовками (например, Authorization) → декодированный JSON.
     * @param string[] $headers
     * @return array<string,mixed>
     * @throws \RuntimeException
     */
    public static function getJson(string $url, array $headers = [], int $timeout = 10): array
    {
        return self::request($url, [CURLOPT_HTTPGET => true], $headers, $timeout);
    }

    /**
     * Скачивает файл (по https) в $dest, не больше $maxBytes. Возвращает размер или null при любой неудаче.
     */
    public static function download(string $url, string $dest, int $maxBytes, int $timeout = 10): ?int
    {
        $fh = fopen($dest, 'wb');
        if ($fh === false) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOPROGRESS     => false,
            // прерываем, как только скачано больше лимита
            CURLOPT_PROGRESSFUNCTION => static fn($r, $dlTotal, $dlNow) => $dlNow > $maxBytes ? 1 : 0,
        ]);
        $ok   = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fh);
        $size = (int) @filesize($dest);
        return $ok !== false && $code === 200 && $size > 0 && $size <= $maxBytes ? $size : null;
    }

    /**
     * @param array<int,mixed> $options
     * @param string[] $headers
     * @return array<string,mixed>
     */
    private static function request(string $url, array $options, array $headers, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, $options + [
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
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
