<?php

declare(strict_types=1);

namespace BanosPortatiles\SitioEnVenta;

use BanosPortatiles\SitioEnVenta\Admin\SettingsPage;
use BanosPortatiles\SitioEnVenta\Rest\ConfigController;
use BanosPortatiles\SitioEnVenta\Settings\Sanitizer;
use BanosPortatiles\SitioEnVenta\Settings\Store;

/**
 * Composition root. Integrations with other plugins are hook-based only, so this plugin works alone:
 *  - filter "bp_headless/sale_banner"      → bp-headless exposes this config in /bp/v1/site (single source);
 *  - filter "bp_sitio_en_venta/import"     → seed importers store site.sale_banner here;
 *  - filter "bp_headless/request_deploy"   → «Guardar y publicar» asks bp-headless for a rebuild;
 *  - action "bp_sitio_en_venta/updated"    → fired on every change (bp-headless flushes its cache).
 */
final class Plugin
{
    private static ?Store $store = null;

    public static function boot(): void
    {
        $store = self::store();
        $store->register();
        (new ConfigController($store))->register();

        if (is_admin()) {
            (new SettingsPage($store))->register();
        }

        add_filter('bp_headless/sale_banner', static fn (): array => $store->publicConfig());
        add_filter('bp_sitio_en_venta/import', static function (mixed $result, mixed $input) use ($store): mixed {
            if (! is_array($input)) {
                return $result;
            }
            $saved = $store->import($input);

            return ['errors' => $saved->errors, 'warnings' => $saved->warnings];
        }, 10, 2);
    }

    public static function store(): Store
    {
        return self::$store ??= new Store(new Sanitizer);
    }
}
