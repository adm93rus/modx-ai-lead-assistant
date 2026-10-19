<?php

namespace AiAssistant\Support;

class Text
{
    public static function lower($value)
    {
        $value = str_replace(array('Ё', 'ё'), array('Е', 'е'), (string) $value);

        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }

    public static function normalize($value)
    {
        $value = self::lower($value);
        $value = str_replace("\xc2\xa0", ' ', $value);
        $value = preg_replace('/\s+/u', ' ', (string) $value);

        return trim((string) $value);
    }

    public static function len($value)
    {
        $value = (string) $value;

        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    public static function sub($value, $start, $length = null)
    {
        $value = (string) $value;

        if (function_exists('mb_substr')) {
            return $length === null
                ? mb_substr($value, $start, null, 'UTF-8')
                : mb_substr($value, $start, $length, 'UTF-8');
        }

        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }

    public static function startsWith($value, $prefix)
    {
        $prefix = (string) $prefix;
        if ($prefix === '') {
            return true;
        }

        return self::sub($value, 0, self::len($prefix)) === $prefix;
    }

    public static function contains($haystack, $needle)
    {
        $needle = (string) $needle;
        if ($needle === '') {
            return false;
        }

        if (function_exists('mb_stripos')) {
            return mb_stripos((string) $haystack, $needle, 0, 'UTF-8') !== false;
        }

        return stripos((string) $haystack, $needle) !== false;
    }

    public static function truncate($value, $limit)
    {
        $value = trim((string) $value);
        $limit = (int) $limit;
        if ($limit < 2 || self::len($value) <= $limit) {
            return $value;
        }

        return rtrim(self::sub($value, 0, $limit - 1)) . '…';
    }

    public static function strip($value)
    {
        $value = html_entity_decode(strip_tags((string) $value), ENT_QUOTES, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', (string) $value);

        return trim((string) $value);
    }

    /**
     * @return string[]
     */
    public static function words($value)
    {
        $value = self::normalize($value);
        if ($value === '') {
            return array();
        }

        $parts = preg_split('/[^\p{L}\p{N}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? array_values($parts) : array();
    }

    public static function money($value)
    {
        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return 0.0;
        }

        $raw = str_replace(array("\xc2\xa0", ' '), '', $raw);
        $raw = preg_replace('/[^\d,.\-]/', '', (string) $raw);
        if ($raw === null || $raw === '' || $raw === '-' || $raw === '.' || $raw === ',') {
            return 0.0;
        }

        $hasComma = strpos($raw, ',') !== false;
        $hasDot = strpos($raw, '.') !== false;
        if ($hasComma && $hasDot) {
            if (strrpos($raw, ',') > strrpos($raw, '.')) {
                $raw = str_replace('.', '', $raw);
                $raw = str_replace(',', '.', $raw);
            } else {
                $raw = str_replace(',', '', $raw);
            }
        } elseif ($hasComma) {
            $raw = str_replace(',', '.', $raw);
        }

        if (!is_numeric($raw)) {
            return 0.0;
        }

        return round((float) $raw, 2);
    }

    public static function formatMoney($amount, $currency)
    {
        $amount = round((float) $amount, 2);
        $decimals = abs($amount - round($amount)) < 0.001 ? 0 : 2;
        $formatted = number_format($amount, $decimals, ',', ' ');
        $currency = trim((string) $currency);

        return $currency === '' ? $formatted : $formatted . ' ' . $currency;
    }

    public static function mostlyLatin($value)
    {
        $cyrillic = preg_match_all('/\p{Cyrillic}/u', (string) $value);
        $latin = preg_match_all('/[A-Za-z]/', (string) $value);

        return (int) $latin > (int) $cyrillic;
    }

    /**
     * Allows http(s) and site-relative paths. Rejects javascript: and other schemes.
     */
    public static function safeUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        if (isset($url[0]) && $url[0] === '/' && (!isset($url[1]) || $url[1] !== '/' || (isset($url[1]) && $url[1] === '/'))) {
            if (isset($url[1]) && $url[1] === '/') {
                return '';
            }

            return $url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme === 'http' || $scheme === 'https') {
            return $url;
        }

        return '';
    }

    public static function articleKey($article)
    {
        return self::lower(trim((string) $article));
    }
}
