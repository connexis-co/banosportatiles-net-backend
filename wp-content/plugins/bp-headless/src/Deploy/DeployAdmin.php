<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Deploy;

use BanosPortatiles\Headless\Cache\ContentChangeListener;
use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * Publication state in the admin:
 *
 * - admin bar item (always visible for editors) with a colored dot and «Sitio publicado ✓ · hace 3 min»,
 *   «Publicación programada · en 45 s», «Publicando… · 1 min 20 s» (+ estimated progress bar), «Cambios sin
 *   publicar» or «Error al publicar», and the «Publicar ahora» button in its menu;
 * - dashboard widget with the same state and the details (hook, queue, last call, build.json, daily rebuild);
 * - «Guardado. El sitio público se actualiza en ~4 min.» after saving (admin notice; snackbar in the block editor).
 *
 * The page is rendered without waiting for build.json (cached value only); assets/publish-status.js asks
 * GET /bp/v1/status when that value is missing and every 15 s only while the state is scheduled or publishing.
 */
final class DeployAdmin implements Hookable
{
    public const ACTION = 'bp_headless_deploy_now';

    /** «Publicar ahora». */
    public const CAPABILITY = 'publish_pages';

    /** Who sees the state (editors and administrators). */
    public const VIEW_CAPABILITY = 'edit_pages';

    public const SAVED_TRANSIENT = 'bp_publish_saved_';

    public const POLL_SECONDS = 15;

    /** @var array{content: array{version: string, at: int}, scheduledFor: ?int, trigger: ?array{at: int, ok: bool, status: int, message: string, trigger: string, reason: string, user: string}, build: array{version: string, builtAt: ?int, commit: ?string}|null, fresh: bool, publish: array<string, mixed>}|null */
    private ?array $snapshot = null;

    public function __construct(
        private readonly Config $config,
        private readonly DeployScheduler $scheduler,
        private readonly PublishMonitor $monitor,
    ) {}

    public function register(): void
    {
        add_action('admin_bar_menu', [$this, 'adminBar'], 90);
        add_action('admin_post_'.self::ACTION, [$this, 'handle']);
        add_action('wp_dashboard_setup', [$this, 'dashboardWidget']);
        add_action('admin_notices', [$this, 'notice']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('enqueue_block_editor_assets', [$this, 'editorAssets']);
        add_action(ContentChangeListener::ACTION, [$this, 'rememberSave'], 20, 1);
    }

    public function adminBar(\WP_Admin_Bar $bar): void
    {
        if (! is_admin() || ! current_user_can(self::VIEW_CAPABILITY)) {
            return;
        }
        $bar->add_node([
            'id' => 'bp-publish',
            'title' => self::badge($this->snapshot()['publish'], true),
            'href' => admin_url('index.php#bp_headless_deploy'),
            'meta' => ['title' => 'Estado del sitio público ('.$this->config->frontendUrl().')', 'class' => 'bp-publish-node'],
        ]);
        if (current_user_can(self::CAPABILITY)) {
            $bar->add_node([
                'parent' => 'bp-publish',
                'id' => 'bp-publish-now',
                'title' => 'Publicar ahora',
                'href' => $this->actionUrl(),
                'meta' => ['title' => 'Reconstruye el sitio público ahora, sin esperar la cola'],
            ]);
        }
        $bar->add_node([
            'parent' => 'bp-publish',
            'id' => 'bp-publish-site',
            'title' => 'Ver el sitio público ↗',
            'href' => $this->config->frontendUrl(),
            'meta' => ['target' => '_blank', 'rel' => 'noopener'],
        ]);
    }

    public function handle(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die('No tienes permisos para publicar el sitio.', 403);
        }
        check_admin_referer(self::ACTION);

        $this->scheduler->runNow('manual', 'botón «Publicar ahora»');
        $last = DeployHook::last();
        $back = wp_get_referer();

        wp_safe_redirect(add_query_arg('bp_deploy', ($last['ok'] ?? false) ? 'ok' : 'error', $back !== false ? $back : admin_url()));
        exit;
    }

    /**
     * A content change saved from a classic admin screen: the notice shows on the next page. Not for the block
     * editor (REST; it shows a snackbar), its meta box save, AJAX or «Ajustes del sitio» (own message).
     */
    public function rememberSave(mixed $reason = ''): void
    {
        if (! is_admin() || wp_doing_ajax() || isset($_REQUEST['meta-box-loader']) || $reason === 'options') {
            return;
        }
        $user = get_current_user_id();
        if ($user > 0) {
            set_transient(self::SAVED_TRANSIENT.$user, 1, 120);
        }
    }

    public function notice(): void
    {
        if (! current_user_can(self::VIEW_CAPABILITY)) {
            return;
        }
        $user = get_current_user_id();
        if (get_transient(self::SAVED_TRANSIENT.$user) !== false) {
            delete_transient(self::SAVED_TRANSIENT.$user);
            printf('<div class="notice notice-info is-dismissible bp-publish-saved"><p>%s</p></div>', esc_html(self::savedMessage($this->config->deployHookUrl() !== '')));
        }

        $status = isset($_GET['bp_deploy']) && is_string($_GET['bp_deploy']) ? sanitize_key($_GET['bp_deploy']) : '';
        if ($status === '') {
            return;
        }
        $last = DeployHook::last();
        printf(
            '<div class="notice %s is-dismissible"><p><strong>Publicar ahora:</strong> %s</p></div>',
            esc_attr($status === 'ok' ? 'notice-success' : 'notice-error'),
            esc_html($status === 'ok'
                ? 'Reconstrucción solicitada. El sitio público se actualiza en ~'.PublishStatus::estimateMinutes(0).' min.'
                : ($last['message'] ?? 'No se pudo solicitar la reconstrucción.'))
        );
    }

    /** «Guardado. El sitio público se actualiza en ~4 min.» (or why it will not). */
    public static function savedMessage(bool $hook): string
    {
        return $hook
            ? 'Guardado. El sitio público se actualiza en ~'.PublishStatus::estimateMinutes().' min.'
            : 'Guardado. Falta configurar el deploy hook (BP_DEPLOY_HOOK_URL): el sitio público no se actualizará solo.';
    }

    public function assets(): void
    {
        if (! is_admin_bar_showing() || ! current_user_can(self::VIEW_CAPABILITY)) {
            return;
        }
        $base = plugins_url('assets/', \BanosPortatiles\Headless\PLUGIN_FILE);
        wp_enqueue_style('bp-publish-status', $base.'publish-status.css', [], \BanosPortatiles\Headless\VERSION);
        wp_enqueue_script('bp-publish-status', $base.'publish-status.js', [], \BanosPortatiles\Headless\VERSION, ['in_footer' => true]);
        $snapshot = $this->snapshot();
        wp_add_inline_script('bp-publish-status', 'window.bpPublishStatus = '.wp_json_encode([
            'endpoint' => rest_url('bp/v1/status'),
            'status' => PublishMonitor::publish($snapshot['publish']),
            'fresh' => $snapshot['fresh'],
            'pollSeconds' => self::POLL_SECONDS,
        ]).';', 'before');
    }

    public function editorAssets(): void
    {
        if (! current_user_can(self::VIEW_CAPABILITY)) {
            return;
        }
        $base = plugins_url('assets/', \BanosPortatiles\Headless\PLUGIN_FILE);
        wp_enqueue_script('bp-publish-editor', $base.'publish-editor.js', ['wp-data', 'wp-notices'], \BanosPortatiles\Headless\VERSION, ['in_footer' => true]);
        wp_add_inline_script('bp-publish-editor', 'window.bpPublishEditor = '.wp_json_encode([
            'saved' => self::savedMessage($this->config->deployHookUrl() !== ''),
        ]).';', 'before');
    }

    public function dashboardWidget(): void
    {
        if (current_user_can(self::VIEW_CAPABILITY)) {
            wp_add_dashboard_widget('bp_headless_deploy', 'Sitio público y publicación', [$this, 'renderWidget']);
        }
    }

    public function renderWidget(): void
    {
        $snapshot = $this->snapshot();
        $front = $this->config->frontendUrl();
        $format = get_option('date_format').' H:i';
        $source = match ($this->config->deployHookSource()) {
            'wp-config' => 'configurado en wp-config.php',
            'ajustes' => 'configurado en Ajustes del sitio',
            default => 'sin configurar',
        };

        echo '<div class="bp-publish-widget">'.self::badge($snapshot['publish'], false).'</div>';
        echo '<p>Sitio público: <a href="'.esc_url($front).'" target="_blank" rel="noopener">'.esc_html($front).'</a> · Deploy hook: <strong>'.esc_html($source).'</strong></p>';

        $content = $snapshot['content'];
        $build = $snapshot['build'];
        echo '<p><small>Versión del contenido: <code>'.esc_html($content['version']).'</code>, cambiada el '.esc_html(wp_date($format, $content['at']) ?: '').'<br>';
        if ($build !== null) {
            echo 'Versión en el sitio público: <code>'.esc_html($build['version']).'</code>'
                .($build['builtAt'] !== null ? ', compilada el '.esc_html(wp_date($format, $build['builtAt']) ?: '') : '')
                .($build['commit'] !== null ? ' · commit <code>'.esc_html(substr($build['commit'], 0, 7)).'</code>' : '');
        } else {
            echo esc_html($snapshot['fresh'] ? 'No se pudo leer build.json del sitio público.' : 'Consultando el sitio público…');
        }
        echo '</small></p>';

        $next = $snapshot['scheduledFor'];
        if ($next !== null) {
            echo '<p>⏳ Publicación en cola para las '.esc_html(wp_date('H:i:s', $next) ?: '').'. Agrupa los cambios: ~1 min para el contenido y hasta 15 min para las reseñas.</p>';
        }
        $last = $snapshot['trigger'];
        if ($last !== null) {
            printf(
                '<p>Último envío al deploy hook: %s — %s (%s)<br><small>%s · %s</small></p>',
                esc_html(wp_date($format, $last['at']) ?: ''),
                $last['ok'] ? '✅ OK' : '❌ Error',
                esc_html($last['trigger'].($last['status'] > 0 ? ' · HTTP '.$last['status'] : '')),
                esc_html($last['message']),
                esc_html($last['reason'] !== '' ? $last['reason'] : $last['user'])
            );
        } else {
            echo '<p>Aún no se ha enviado ninguna publicación.</p>';
        }
        $daily = $this->config->dailyRebuildTime();
        $dailyNext = wp_next_scheduled(DailyRebuild::HOOK);
        echo '<p>Rebuild diario: '.($daily === null
            ? 'desactivado'
            : '<strong>'.esc_html($daily).'</strong>'.(is_int($dailyNext) ? ' (próximo: '.esc_html(wp_date($format, $dailyNext) ?: '').')' : '')).'</p>';
        if (current_user_can(self::CAPABILITY)) {
            echo '<p><a class="button button-primary" href="'.esc_url($this->actionUrl()).'">Publicar ahora</a></p>';
        }
    }

    /**
     * Dot + «label · detail» (+ progress bar), updated in place by publish-status.js through [data-bp-publish].
     *
     * @param  array<string, mixed>  $publish
     */
    public static function badge(array $publish, bool $compact): string
    {
        $state = is_string($publish['state'] ?? null) ? $publish['state'] : 'unknown';
        $label = is_string($publish['label'] ?? null) ? $publish['label'] : '';
        $detail = is_string($publish['detail'] ?? null) ? $publish['detail'] : '';
        $color = is_string($publish['color'] ?? null) ? $publish['color'] : '#8c8f94';
        $progress = is_float($publish['progress'] ?? null) || is_int($publish['progress'] ?? null) ? (float) $publish['progress'] : 0.0;

        return sprintf(
            '<span class="bp-publish bp-publish--%1$s%2$s" data-bp-publish data-state="%1$s"><span class="bp-publish__dot" style="background:%3$s" aria-hidden="true"></span><span class="bp-publish__text">%4$s</span><span class="bp-publish__bar" aria-hidden="true"><span style="width:%5$d%%"></span></span></span>',
            esc_attr($state),
            $compact ? ' bp-publish--compact' : '',
            esc_attr($color),
            esc_html($label.($detail !== '' ? ' · '.$detail : '')),
            (int) round($progress * 100)
        );
    }

    /**
     * One snapshot per request, without waiting for build.json.
     *
     * @return array{content: array{version: string, at: int}, scheduledFor: ?int, trigger: ?array{at: int, ok: bool, status: int, message: string, trigger: string, reason: string, user: string}, build: array{version: string, builtAt: ?int, commit: ?string}|null, fresh: bool, publish: array<string, mixed>}
     */
    private function snapshot(): array
    {
        return $this->snapshot ??= $this->monitor->snapshot(false);
    }

    private function actionUrl(): string
    {
        return wp_nonce_url(admin_url('admin-post.php?action='.self::ACTION), self::ACTION);
    }
}
