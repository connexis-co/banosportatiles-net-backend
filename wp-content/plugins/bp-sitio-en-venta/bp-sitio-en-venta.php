<?php

/**
 * Plugin Name:       Sitio en venta
 * Description:       Aviso configurable de «sitio en venta / alquiler» (barras, tarjeta lateral, bloque) con WhatsApp, vista previa en vivo y API REST bp-venta/v1. Independiente y portable: no requiere otros plugins.
 * Version:           1.0.4
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Author:            Connexis
 * Author URI:        https://connexis.co
 * License:           GPL-2.0-or-later
 * Text Domain:       bp-sitio-en-venta
 */

declare(strict_types=1);

namespace BanosPortatiles\SitioEnVenta;

if (! defined('ABSPATH')) {
    exit;
}

const VERSION = '1.0.4';
const PLUGIN_FILE = __FILE__;

if (PHP_VERSION_ID < 80300) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>«Sitio en venta» requiere PHP 8.3 o superior.</p></div>';
    });

    return;
}

spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__.'\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

add_action('plugins_loaded', [Plugin::class, 'boot']);
