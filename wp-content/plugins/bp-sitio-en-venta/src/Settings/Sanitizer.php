<?php

declare(strict_types=1);

namespace BanosPortatiles\SitioEnVenta\Settings;

use BanosPortatiles\SitioEnVenta\Support\Contrast;
use BanosPortatiles\SitioEnVenta\Support\PathRules;
use BanosPortatiles\SitioEnVenta\Support\Phone;

/**
 * Sanitizes and validates the whole option (pure PHP). Invalid values are rejected (the current one is
 * kept and an error is reported); empty texts fall back to the defaults of the selected mode.
 *
 * $partial = false (admin form): a missing checkbox means false. $partial = true (API/seed): missing keys keep $current.
 */
final class Sanitizer
{
    public const HEADLINE_MAX = 90;

    public const MESSAGE_MAX = 220;

    public const WHATSAPP_MESSAGE_MAX = 500;

    public const LABEL_MAX = 40;

    /**
     * @param  array<array-key, mixed>  $input
     * @param  array<string, mixed>  $current
     */
    public function sanitize(array $input, array $current, bool $partial = false): SanitizeResult
    {
        $current = array_replace(Defaults::settings(is_string($current['modo'] ?? null) ? $current['modo'] : 'venta'), $current);
        $errors = [];
        $warnings = [];
        $has = static fn (string $key): bool => array_key_exists($key, $input);
        $bool = fn (string $key): bool => $partial && ! $has($key) ? (bool) $current[$key] : self::toBool($input[$key] ?? false);

        $modo = $has('modo') ? self::text($input['modo']) : (string) $current['modo'];
        if (! isset(Defaults::MODES[$modo])) {
            $errors['modo'] = 'Modo no válido: usa venta, alquiler o venta_o_alquiler.';
            $modo = (string) $current['modo'];
        }
        $texts = Defaults::texts($modo);

        $settings = ['enabled' => $bool('enabled'), 'modo' => $modo];

        foreach (['headline' => self::HEADLINE_MAX, 'message' => self::MESSAGE_MAX, 'cta_whatsapp_label' => self::LABEL_MAX, 'secondary_label' => self::LABEL_MAX] as $key => $max) {
            $value = $has($key) ? self::text($input[$key]) : ($partial ? (string) $current[$key] : '');
            if (mb_strlen($value) > $max) {
                $errors[$key] = sprintf('Máximo %d caracteres (tiene %d).', $max, mb_strlen($value));
                $value = (string) $current[$key];
            }
            $settings[$key] = $value !== '' ? $value : $texts[$key];
        }

        $number = $has('whatsapp_number') ? Phone::normalize(self::text($input['whatsapp_number'])) : (string) $current['whatsapp_number'];
        if ($number === null) {
            $errors['whatsapp_number'] = 'Número no válido: usa el formato internacional E.164, p. ej. +57 300 123 4567.';
            $number = (string) $current['whatsapp_number'];
        }
        $settings['whatsapp_number'] = $number;

        $message = $has('whatsapp_message') ? self::text($input['whatsapp_message'], true) : ($partial ? (string) $current['whatsapp_message'] : '');
        if (mb_strlen($message) > self::WHATSAPP_MESSAGE_MAX) {
            $errors['whatsapp_message'] = sprintf('Máximo %d caracteres.', self::WHATSAPP_MESSAGE_MAX);
            $message = (string) $current['whatsapp_message'];
        }
        $settings['whatsapp_message'] = $message !== '' ? $message : $texts['whatsapp_message'];

        $settings['show_whatsapp'] = $bool('show_whatsapp');
        if ($settings['show_whatsapp'] && $settings['whatsapp_number'] === '') {
            $settings['show_whatsapp'] = false;
            $warnings['show_whatsapp'] = 'Sin número de WhatsApp el botón queda oculto.';
        }

        $settings['show_secondary'] = $bool('show_secondary');
        $url = $has('secondary_url') ? self::text($input['secondary_url']) : ($partial ? (string) $current['secondary_url'] : '');
        if ($url !== '' && ! self::validUrl($url)) {
            $errors['secondary_url'] = 'Usa una ruta del sitio (/sitio-en-venta/) o una URL https://.';
            $url = (string) $current['secondary_url'];
        }
        $settings['secondary_url'] = $url !== '' ? $url : Defaults::SECONDARY_URL;

        $colors = [];
        $inputColors = is_array($input['colors'] ?? null) ? $input['colors'] : [];
        $currentColors = is_array($current['colors']) ? $current['colors'] : Defaults::COLORS;
        foreach (Defaults::COLORS as $key => $default) {
            $raw = $inputColors[$key] ?? null;
            $color = is_string($raw) ? Contrast::normalizeHex($raw) : null;
            if ($raw !== null && $color === null) {
                $errors['colors.'.$key] = 'Color no válido: usa hexadecimal, p. ej. #0f172a.';
            }
            $fallback = is_string($currentColors[$key] ?? null) ? (string) $currentColors[$key] : $default;
            $colors[$key] = $color ?? $fallback;
        }
        $settings['colors'] = $colors;
        foreach (Contrast::report($colors) as $check) {
            if (! $check['ok']) {
                $warnings['contrast.'.$check['pair']] = sprintf('%s: contraste %s:1, no cumple WCAG AA (mínimo %s:1).', $check['label'], self::number($check['ratio']), self::number($check['minimum']));
            }
        }

        $placements = $has('placements') ? $input['placements'] : ($partial ? $current['placements'] : []);
        $placements = is_string($placements) ? explode(',', $placements) : (is_array($placements) ? $placements : []);
        $placements = array_map(static fn (mixed $p): string => is_scalar($p) ? trim((string) $p) : '', $placements);
        $settings['placements'] = array_values(array_intersect(array_keys(Defaults::PLACEMENTS), $placements));

        $settings['dismissible'] = $bool('dismissible');
        $days = $has('dismiss_days') ? $input['dismiss_days'] : $current['dismiss_days'];
        if (! is_numeric($days)) {
            $errors['dismiss_days'] = 'Indica un número de días entre 1 y 30.';
            $days = $current['dismiss_days'];
        }
        $settings['dismiss_days'] = max(1, min(30, (int) $days));
        if ((int) $days !== $settings['dismiss_days']) {
            $warnings['dismiss_days'] = 'Los días para volver a mostrar el aviso deben estar entre 1 y 30; se ajustó a '.$settings['dismiss_days'].'.';
        }

        $paths = $has('exclude_paths') ? $input['exclude_paths'] : $current['exclude_paths'];
        $settings['exclude_paths'] = PathRules::normalizeList(is_string($paths) || is_array($paths) ? $paths : '');

        return new SanitizeResult($settings, $errors, $warnings);
    }

    public static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return is_scalar($value) && in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes', 'si', 'sí'], true);
    }

    private static function text(mixed $value, bool $multiline = false): string
    {
        if (! is_scalar($value)) {
            return '';
        }
        $text = strip_tags((string) $value);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = $multiline
            ? (string) preg_replace("/[ \t]+/u", ' ', str_replace("\r\n", "\n", $text))
            : (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    private static function validUrl(string $url): bool
    {
        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//') && preg_match('/\s/', $url) !== 1;
        }

        return preg_match('#^https?://#i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private static function number(float $value): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.'));
    }
}
