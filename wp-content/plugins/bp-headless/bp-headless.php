<?php

/**
 * Plugin Name:       BP Headless
 * Description:       Headless CMS layer for banosportatiles.net: content model (CPTs, taxonomies, SCF fields), REST API bp/v1, leads, ratings and reviews (Site Reviews), SEO (Rank Math), previews, deploy hook and hardening.
 * Version:           1.3.1
 * Requires at least: 7.0
 * Requires PHP:      8.3
 * Author:            Connexis
 * Author URI:        https://connexis.co
 * License:           GPL-2.0-or-later
 * Text Domain:       bp-headless
 */

declare(strict_types=1);

namespace BanosPortatiles\Headless;

if (! defined('ABSPATH')) {
    exit;
}

const VERSION = '1.3.1';
const PLUGIN_FILE = __FILE__;

if (PHP_VERSION_ID < 80300) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>BP Headless requiere PHP 8.3 o superior.</p></div>';
    });

    return;
}

require_once __DIR__.'/src/Autoloader.php';
Autoloader::register(__NAMESPACE__, __DIR__.'/src');

register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);

add_action('plugins_loaded', [Plugin::class, 'boot'], 5);
