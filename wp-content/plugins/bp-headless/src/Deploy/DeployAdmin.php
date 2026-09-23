<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Deploy;

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * Admin UI for deploys: "Publicar cambios en el sitio" in the admin bar, a dashboard widget with
 * the last trigger/result, and the admin-post handler behind both.
 */
final class DeployAdmin implements Hookable
{
    public const ACTION = 'bp_headless_deploy_now';

    public const CAPABILITY = 'publish_pages';

    public function __construct(
        private readonly Config $config,
        private readonly DeployScheduler $scheduler,
    ) {}

    public function register(): void
    {
        add_action('admin_bar_menu', [$this, 'adminBar'], 90);
        add_action('admin_post_'.self::ACTION, [$this, 'handle']);
        add_action('wp_dashboard_setup', [$this, 'dashboardWidget']);
        add_action('admin_notices', [$this, 'notice']);
    }

    public function adminBar(\WP_Admin_Bar $bar): void
    {
        if (! is_admin() || ! current_user_can(self::CAPABILITY)) {
            return;
        }

        $pending = $this->scheduler->nextRun() !== null;
        $bar->add_node([
            'id' => 'bp-deploy',
            'title' => '<span class="ab-icon dashicons dashicons-cloud-upload" style="top:2px"></span>'
                .esc_html($pending ? 'Publicación en cola…' : 'Publicar cambios en el sitio'),
            'href' => $this->actionUrl(),
            'meta' => ['title' => 'Reconstruye el sitio público ('.$this->config->frontendUrl().')'],
        ]);
    }

    public function handle(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die('No tienes permisos para publicar el sitio.', 403);
        }
        check_admin_referer(self::ACTION);

        $this->scheduler->runNow('manual', 'botón «Publicar cambios en el sitio»');
        $last = DeployHook::last();
        $back = wp_get_referer();

        wp_safe_redirect(add_query_arg('bp_deploy', ($last['ok'] ?? false) ? 'ok' : 'error', $back !== false ? $back : admin_url()));
        exit;
    }

    public function notice(): void
    {
        $status = isset($_GET['bp_deploy']) && is_string($_GET['bp_deploy']) ? sanitize_key($_GET['bp_deploy']) : '';
        if ($status === '' || ! current_user_can(self::CAPABILITY)) {
            return;
        }
        $last = DeployHook::last();
        $class = $status === 'ok' ? 'notice-success' : 'notice-error';
        printf(
            '<div class="notice %s is-dismissible"><p><strong>Despliegue:</strong> %s</p></div>',
            esc_attr($class),
            esc_html($last['message'] ?? '')
        );
    }

    public function dashboardWidget(): void
    {
        if (current_user_can(self::CAPABILITY)) {
            wp_add_dashboard_widget('bp_headless_deploy', 'Sitio público y despliegues', [$this, 'renderWidget']);
        }
    }

    public function renderWidget(): void
    {
        $front = $this->config->frontendUrl();
        $source = match ($this->config->deployHookSource()) {
            'wp-config' => 'configurado en wp-config.php',
            'ajustes' => 'configurado en Ajustes del sitio',
            default => 'sin configurar',
        };
        $last = DeployHook::last();
        $next = $this->scheduler->nextRun();
        $format = get_option('date_format').' H:i';

        echo '<p>Sitio público: <a href="'.esc_url($front).'" target="_blank" rel="noopener">'.esc_html($front).'</a></p>';
        echo '<p>Deploy hook: <strong>'.esc_html($source).'</strong></p>';
        if ($next !== null) {
            echo '<p>⏳ Publicación en cola para las '.esc_html(wp_date('H:i:s', $next) ?: '').' (agrupa los cambios de ~1 minuto).</p>';
        }
        if ($last !== null) {
            printf(
                '<p>Último disparo: %s — %s (%s)<br><small>%s · %s</small></p>',
                esc_html(wp_date($format, $last['at']) ?: ''),
                $last['ok'] ? '✅ OK' : '❌ Error',
                esc_html($last['trigger'].($last['status'] > 0 ? ' · HTTP '.$last['status'] : '')),
                esc_html($last['message']),
                esc_html($last['reason'] !== '' ? $last['reason'] : $last['user'])
            );
        } else {
            echo '<p>Aún no se ha disparado ningún despliegue.</p>';
        }
        echo '<p><a class="button button-primary" href="'.esc_url($this->actionUrl()).'">Publicar cambios en el sitio</a></p>';
    }

    private function actionUrl(): string
    {
        return wp_nonce_url(admin_url('admin-post.php?action='.self::ACTION), self::ACTION);
    }
}
