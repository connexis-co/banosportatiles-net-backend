<?php

declare(strict_types=1);

namespace BanosPortatiles\SitioEnVenta\Settings;

use const BanosPortatiles\SitioEnVenta\VERSION;

/**
 * The single option "bp_sitio_en_venta" + a transient with its public representation.
 * Any change of the option (admin, WP-CLI, import) invalidates the cache and fires
 * "bp_sitio_en_venta/updated" (bp-headless listens to refresh its own API cache).
 */
final class Store
{
    public const CACHE_KEY = 'bp_sitio_en_venta_config';

    public const UPDATED_ACTION = 'bp_sitio_en_venta/updated';

    public function __construct(private readonly Sanitizer $sanitizer) {}

    public function register(): void
    {
        foreach (['add_option_'.Defaults::OPTION, 'update_option_'.Defaults::OPTION, 'delete_option_'.Defaults::OPTION] as $hook) {
            add_action($hook, [$this, 'changed'], 10, 0);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $stored = get_option(Defaults::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $modo = is_string($stored['modo'] ?? null) ? $stored['modo'] : 'venta';

        $settings = array_replace(Defaults::settings($modo), array_intersect_key($stored, Defaults::settings()));
        $settings['colors'] = Defaults::colors($settings['colors']);

        return $settings;
    }

    /**
     * Sanitizes $input against the current settings and stores the result.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function save(array $input, bool $partial = false): SanitizeResult
    {
        $result = $this->sanitizer->sanitize($input, $this->get(), $partial);
        update_option(Defaults::OPTION, $result->settings, true);

        return $result;
    }

    /**
     * Seed import: the given keys over the defaults (site.yaml → sale_banner).
     *
     * @param  array<array-key, mixed>  $input
     */
    public function import(array $input): SanitizeResult
    {
        $modo = is_string($input['modo'] ?? null) ? $input['modo'] : 'venta';
        $result = $this->sanitizer->sanitize($input, Defaults::settings($modo), true);
        update_option(Defaults::OPTION, $result->settings, true);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function publicConfig(): array
    {
        // The plugin version is part of the cached value: a code deploy never serves an outdated shape.
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached) && ($cached['plugin'] ?? null) === VERSION && is_array($cached['config'] ?? null)) {
            return $cached['config'];
        }

        $config = PublicConfig::from($this->get());
        set_transient(self::CACHE_KEY, ['plugin' => VERSION, 'config' => $config], DAY_IN_SECONDS);

        return $config;
    }

    public function changed(): void
    {
        delete_transient(self::CACHE_KEY);
        do_action(self::UPDATED_ACTION, $this->get());
    }
}
