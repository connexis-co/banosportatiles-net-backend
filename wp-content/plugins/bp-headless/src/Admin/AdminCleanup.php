<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Admin;

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * Headless-friendly admin: no Customizer/theme noise, "Visit site" points to the public front and the
 * dashboard explains that this WordPress is only the CMS.
 */
final class AdminCleanup implements Hookable
{
    public function __construct(private readonly Config $config) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 999);
        add_action('admin_bar_menu', [$this, 'adminBar'], 999);
        add_action('admin_notices', [$this, 'headlessNotice']);
    }

    public function menu(): void
    {
        global $submenu;
        // The Customizer entry carries a dynamic "?return=" slug, so it is removed by prefix.
        foreach ($submenu['themes.php'] ?? [] as $index => $item) {
            if (is_array($item) && is_string($item[2] ?? null) && str_starts_with($item[2], 'customize.php')) {
                unset($submenu['themes.php'][$index]);
            }
        }
        remove_submenu_page('themes.php', 'theme-editor.php');
    }

    public function adminBar(\WP_Admin_Bar $bar): void
    {
        $front = $this->config->frontendUrl();
        foreach (['site-name', 'view-site'] as $node) {
            if ($bar->get_node($node) !== null) {
                $bar->add_node(['id' => $node, 'href' => $front.'/', 'meta' => ['target' => '_blank']]);
            }
        }
        $bar->remove_node('customize');
        $bar->remove_node('wp-logo');
    }

    public function headlessNotice(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen === null || $screen->id !== 'dashboard') {
            return;
        }

        $front = $this->config->frontendUrl();
        printf(
            '<div class="notice notice-info"><p><strong>WordPress headless.</strong> Este sitio es solo el CMS: el sitio público es <a href="%1$s" target="_blank" rel="noopener">%2$s</a> (Astro en Cloudflare). Al publicar o actualizar contenido, el sitio se reconstruye automáticamente en ~1–3 minutos.</p></div>',
            esc_url($front),
            esc_html($front)
        );
    }
}
