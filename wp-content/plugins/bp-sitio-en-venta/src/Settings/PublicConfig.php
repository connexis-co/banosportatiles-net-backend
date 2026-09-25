<?php

declare(strict_types=1);

namespace BanosPortatiles\SitioEnVenta\Settings;

/**
 * Public representation (GET /bp-venta/v1/config and bp/v1/site → sale_banner): the stored settings in
 * a stable key order plus "version", a short hash the front can use to reset dismissals after changes.
 */
final class PublicConfig
{
    public const KEYS = [
        'enabled', 'modo', 'headline', 'message', 'whatsapp_number', 'whatsapp_message', 'show_whatsapp',
        'cta_whatsapp_label', 'cta_whatsapp_short', 'whatsapp_note', 'show_secondary', 'secondary_label', 'secondary_url',
        'colors', 'placements', 'dismissible', 'dismiss_days', 'exclude_paths',
    ];

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function from(array $settings): array
    {
        $defaults = Defaults::settings();
        $value = static fn (string $key): mixed => $settings[$key] ?? $defaults[$key] ?? null;
        $config = [];
        foreach (self::KEYS as $key) {
            $config[$key] = $value($key);
        }
        $config['colors'] = Defaults::colors($value('colors'));
        // Invariant for every consumer: no number, no WhatsApp button (also before the first save).
        $number = $value('whatsapp_number');
        $config['show_whatsapp'] = (bool) $value('show_whatsapp') && is_string($number) && $number !== '';
        // Placements are stored as a list; consumers get an explicit object {placement: bool} (same shape as the seed).
        $enabled = $value('placements');
        $enabled = is_array($enabled) ? $enabled : [];
        $placements = [];
        foreach (array_keys(Defaults::PLACEMENTS) as $placement) {
            $placements[$placement] = in_array($placement, $enabled, true);
        }
        $config['placements'] = $placements;
        $config['version'] = substr(md5((string) json_encode($config)), 0, 12);

        return $config;
    }
}
