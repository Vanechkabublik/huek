<?php
declare(strict_types=1);

namespace App;

/**
 * Форматирование значений для вывода: цены, даты, телефоны.
 */
final class Format
{
    private const MONTHS_SHORT = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
    private const MONTHS_GEN   = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

    /** 1850000 → «1 850 000 ₽», 0 → «Бесплатно». Разделитель — неразрывный пробел. */
    public static function price(int $price): string
    {
        if ($price <= 0) {
            return 'Бесплатно';
        }
        return number_format($price, 0, '', "\u{00A0}") . "\u{00A0}₽";
    }

    /** «Сегодня, 14:05» / «Вчера, 09:30» / «12 окт» / «12 окт 2025». */
    public static function date(string $datetime): string
    {
        $ts = strtotime($datetime);
        if ($ts === false) {
            return '';
        }
        $today = strtotime('today');
        $time = date('H:i', $ts);
        if ($ts >= $today) {
            return "Сегодня, $time";
        }
        if ($ts >= $today - 86400) {
            return "Вчера, $time";
        }
        $label = (int) date('j', $ts) . ' ' . self::MONTHS_SHORT[(int) date('n', $ts) - 1];
        return date('Y', $ts) === date('Y') ? $label : $label . ' ' . date('Y', $ts);
    }

    /** «27 октября 2025, 14:05» — для админских таблиц и полной даты. */
    public static function dateFull(string $datetime): string
    {
        $ts = strtotime($datetime);
        if ($ts === false) {
            return '';
        }
        $genitive = self::MONTHS_GEN[(int) date('n', $ts) - 1];
        return (int) date('j', $ts) . " $genitive " . date('Y, H:i', $ts);
    }

    /** «На сайте с октября 2025». */
    public static function memberSince(string $datetime): string
    {
        $ts = strtotime($datetime);
        if ($ts === false) {
            return '';
        }
        $genitive = self::MONTHS_GEN[(int) date('n', $ts) - 1];
        return "На сайте с $genitive " . date('Y', $ts);
    }

    /** Приводит телефон к единому виду: +7 900 123-45-67 (для российских номеров). */
    public static function phone(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';
        if (strlen($digits) === 11 && in_array($digits[0], ['7', '8'], true)) {
            return sprintf('+7 %s %s-%s-%s', substr($digits, 1, 3), substr($digits, 4, 3), substr($digits, 7, 2), substr($digits, 9, 2));
        }
        return $digits === '' ? '' : '+' . $digits;
    }
}
