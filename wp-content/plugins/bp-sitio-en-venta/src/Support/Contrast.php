<?php

declare(strict_types=1);

namespace BanosPortatiles\SitioEnVenta\Support;

/**
 * WCAG 2.x contrast (relative luminance). AA: 4.5:1 for normal text, 3:1 for UI components.
 */
final class Contrast
{
    public const AA_TEXT = 4.5;

    public const AA_UI = 3.0;

    public static function ratio(string $foreground, string $background): float
    {
        $a = self::luminance($foreground);
        $b = self::luminance($background);

        return round((max($a, $b) + 0.05) / (min($a, $b) + 0.05), 2);
    }

    public static function luminance(string $hex): float
    {
        $rgb = self::rgb($hex);
        $channels = array_map(static function (int $value): float {
            $c = $value / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * Pairs checked for the banner: text on background, button text on button, button on background.
     *
     * @param  array<string, string>  $colors  bg, text, accent, accent_text
     * @return list<array{pair: string, label: string, ratio: float, minimum: float, ok: bool}>
     */
    public static function report(array $colors): array
    {
        $pairs = [
            ['text', 'bg', 'Texto sobre el fondo', self::AA_TEXT],
            ['accent_text', 'accent', 'Texto del botón sobre el botón', self::AA_TEXT],
            ['accent', 'bg', 'Botón sobre el fondo', self::AA_UI],
        ];

        $report = [];
        foreach ($pairs as [$fg, $bg, $label, $minimum]) {
            $ratio = self::ratio($colors[$fg] ?? '#000000', $colors[$bg] ?? '#ffffff');
            $report[] = ['pair' => $fg.'/'.$bg, 'label' => $label, 'ratio' => $ratio, 'minimum' => $minimum, 'ok' => $ratio >= $minimum];
        }

        return $report;
    }

    /** "#abc" or "#aabbcc" (any case) → "#aabbcc"; null when invalid. */
    public static function normalizeHex(string $value): ?string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^#([0-9a-f]{3})$/', $value, $m) === 1) {
            return '#'.$m[1][0].$m[1][0].$m[1][1].$m[1][1].$m[1][2].$m[1][2];
        }

        return preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : null;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rgb(string $hex): array
    {
        $hex = self::normalizeHex($hex) ?? '#000000';

        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }
}
