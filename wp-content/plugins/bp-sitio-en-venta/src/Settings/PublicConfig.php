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
        'cta_whatsapp_label', 'show_secondary', 'secondary_label', 'secondary_url', 'colors', 'placements',
        'dismissible', 'dismiss_days', 'exclude_paths',
    ];

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function from(array $settings): array
    {
        $config = [];
        foreach (self::KEYS as $key) {
            $config[$key] = $settings[$key] ?? Defaults::settings()[$key];
        }
        // Invariant for every consumer: no number, no WhatsApp button (also before the first save).
        $config['show_whatsapp'] = (bool) $config['show_whatsapp'] && is_string($config['whatsapp_number']) && $config['whatsapp_number'] !== '';
        // Placements are stored as a list; consumers get an explicit object {placement: bool} (same shape as the seed).
        $enabled = is_array($config['placements']) ? $config['placements'] : [];
        $config['placements'] = [];
        foreach (array_keys(Defaults::PLACEMENTS) as $placement) {
            $config['placements'][$placement] = in_array($placement, $enabled, true);
        }
        $config['version'] = substr(md5((string) json_encode($config)), 0, 12);

        return $config;
    }
}
