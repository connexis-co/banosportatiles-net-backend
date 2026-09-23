<?php

declare(strict_types=1);

namespace BanosPortatiles\SitioEnVenta\Support;

/**
 * E.164 phone numbers for WhatsApp. Colombian shortcuts: "300 123 4567" and "57 300…" become +57….
 */
final class Phone
{
    /** Returns the E.164 number, "" for empty input or null when it cannot be a valid number. */
    public static function normalize(string $input): ?string
    {
        $raw = trim($input);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/[^\d\s+().\-]/', $raw) === 1 || substr_count($raw, '+') > 1) {
            return null;
        }

        $digits = (string) preg_replace('/\D+/', '', $raw);
        $candidate = match (true) {
            str_starts_with($raw, '+') => '+'.$digits,
            str_starts_with($digits, '00') => '+'.substr($digits, 2),
            strlen($digits) === 10 && $digits[0] === '3' => '+57'.$digits,
            strlen($digits) === 12 && str_starts_with($digits, '57') => '+'.$digits,
            default => '',
        };

        return self::isE164($candidate) ? $candidate : null;
    }

    public static function isE164(string $value): bool
    {
        return preg_match('/^\+[1-9]\d{7,14}$/', $value) === 1;
    }

    /** Human format: Colombian mobiles as "+57 300 123 4567"; anything else unchanged. */
    public static function display(string $e164): string
    {
        return preg_match('/^\+57(3\d{2})(\d{3})(\d{4})$/', $e164, $m) === 1 ? "+57 {$m[1]} {$m[2]} {$m[3]}" : $e164;
    }

    /** https://wa.me/573001234567?text=… */
    public static function waLink(string $e164, string $text = ''): string
    {
        $url = 'https://wa.me/'.ltrim($e164, '+');

        return $text !== '' ? $url.'?text='.rawurlencode($text) : $url;
    }
}
