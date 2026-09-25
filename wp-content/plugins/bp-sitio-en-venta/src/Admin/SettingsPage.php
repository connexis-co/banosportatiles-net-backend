<?php

declare(strict_types=1);

namespace BanosPortatiles\SitioEnVenta\Admin;

use const BanosPortatiles\SitioEnVenta\PLUGIN_FILE;
use const BanosPortatiles\SitioEnVenta\VERSION;

use BanosPortatiles\SitioEnVenta\Settings\Defaults;
use BanosPortatiles\SitioEnVenta\Settings\Sanitizer;
use BanosPortatiles\SitioEnVenta\Settings\Store;
use BanosPortatiles\SitioEnVenta\Support\Contrast;
use BanosPortatiles\SitioEnVenta\Support\Phone;

/**
 * "Sitio en venta" admin screen: cards per section, help texts and a live preview (assets/admin.js).
 */
final class SettingsPage
{
    public const SLUG = 'bp-sitio-en-venta';

    public const ACTION = 'bp_sitio_en_venta_save';

    public const CAPABILITY = 'manage_options';

    private const NOTICE = 'bp_sitio_en_venta_notice_';

    private const BADGES = ['venta' => 'Sitio en venta', 'alquiler' => 'Disponible para alquiler', 'venta_o_alquiler' => 'En venta o alquiler'];

    private string $hook = '';

    public function __construct(private readonly Store $store) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_'.self::ACTION, [$this, 'save']);
        add_filter('plugin_action_links_'.plugin_basename(PLUGIN_FILE), [$this, 'actionLinks']);
    }

    public function menu(): void
    {
        $this->hook = (string) add_menu_page('Sitio en venta', 'Sitio en venta', self::CAPABILITY, self::SLUG, [$this, 'render'], 'dashicons-megaphone', 59);
    }

    /**
     * @param  array<array-key, string>  $links
     * @return array<array-key, string>
     */
    public function actionLinks(array $links): array
    {
        array_unshift($links, sprintf('<a href="%s">Configurar</a>', esc_url(admin_url('admin.php?page='.self::SLUG))));

        return $links;
    }

    public function assets(string $hook): void
    {
        if ($hook !== $this->hook) {
            return;
        }

        $base = plugins_url('assets/', PLUGIN_FILE);
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_style('bp-sitio-en-venta-banner', $base.'banner.css', [], VERSION);
        wp_enqueue_style('bp-sitio-en-venta-admin', $base.'admin.css', ['bp-sitio-en-venta-banner'], VERSION);
        wp_enqueue_script('bp-sitio-en-venta-admin', $base.'admin.js', ['wp-color-picker'], VERSION, ['in_footer' => true]);

        $defaults = [];
        foreach (array_keys(Defaults::MODES) as $modo) {
            $defaults[$modo] = Defaults::texts($modo);
        }
        wp_add_inline_script('bp-sitio-en-venta-admin', 'window.bpSitioEnVenta = '.wp_json_encode([
            'defaults' => $defaults,
            'badges' => self::BADGES,
            'colors' => Defaults::COLORS,
            'site' => ['name' => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES), 'url' => self::publicSiteUrl()],
            'aa' => ['text' => Contrast::AA_TEXT, 'ui' => Contrast::AA_UI],
        ]).';', 'before');
    }

    public function save(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die('No tienes permisos para cambiar este aviso.', 403);
        }
        check_admin_referer(self::ACTION);

        $input = isset($_POST['bpsev']) && is_array($_POST['bpsev']) ? wp_unslash($_POST['bpsev']) : [];
        $result = $this->store->save(is_array($input) ? $input : []);
        $publish = isset($_POST['publish']) && $_POST['publish'] === '1';

        set_transient(self::NOTICE.get_current_user_id(), [
            'errors' => $result->errors,
            'warnings' => $result->warnings,
            'published' => $publish ? self::requestDeploy() : null,
        ], 120);

        wp_safe_redirect(admin_url('admin.php?page='.self::SLUG.'&bpsev=1'));
        exit;
    }

    /**
     * Asks bp-headless (if active) to rebuild the public site. 'scheduled' | 'no-hook' | 'standalone'.
     */
    public static function requestDeploy(): string
    {
        if (! has_filter('bp_headless/request_deploy')) {
            return 'standalone';
        }

        return apply_filters('bp_headless/request_deploy', false, 'aviso «sitio en venta»') === true ? 'scheduled' : 'no-hook';
    }

    public function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }

        $s = $this->store->get();
        $canPublish = has_filter('bp_headless/request_deploy');
        ?>
<div class="wrap bpsev">
    <div class="bpsev-header">
        <h1><span class="dashicons dashicons-megaphone" aria-hidden="true"></span> Sitio en venta</h1>
        <span class="bpsev-status <?php echo $s['enabled'] ? 'is-on' : 'is-off'; ?>" data-bpsev-status><?php echo $s['enabled'] ? 'Aviso activo' : 'Aviso inactivo'; ?></span>
    </div>
    <p class="bpsev-intro">Configura el aviso que invita a comprar o alquilar este sitio. Aparece solo en las ubicaciones que elijas y, en el sitio público, cada visitante puede cerrarlo.</p>
        <?php $this->notices(); ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="bpsev-layout" id="bpsev-form">
        <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
        <?php wp_nonce_field(self::ACTION); ?>
        <div class="bpsev-main">
            <?php
            $this->card('Estado', 'Activa el aviso y elige qué ofreces. El modo cambia los textos sugeridos mientras no los hayas personalizado.', function () use ($s): void {
                $this->toggle('enabled', 'Mostrar el aviso en el sitio', (bool) $s['enabled']);
                $this->select('modo', 'Modo', Defaults::MODES, (string) $s['modo']);
            });
        $this->card('Mensaje', 'Titular corto y un mensaje claro: qué se vende o alquila y por qué le interesa a una empresa del sector.', function () use ($s): void {
            $this->text('headline', 'Titular', (string) $s['headline'], Sanitizer::HEADLINE_MAX);
            $this->textarea('message', 'Mensaje', (string) $s['message'], Sanitizer::MESSAGE_MAX, 3);
        });
        $this->card('WhatsApp', 'Único número de WhatsApp del sitio: solo aparece en este aviso y es SOLO para comprar o alquilar el sitio web (no para cotizar baños portátiles).', function () use ($s): void {
            $this->toggle('show_whatsapp', 'Mostrar el botón de WhatsApp', (bool) $s['show_whatsapp']);
            $this->text('whatsapp_number', 'Número (formato internacional E.164)', Phone::display((string) $s['whatsapp_number']), 20, '+57 300 123 4567', 'tel', 'Con indicativo de país: +57 3XX XXX XXXX. Un celular colombiano de 10 dígitos se completa con +57.');
            echo '<p class="bpsev-hint" data-bpsev-phone-hint aria-live="polite"></p>';
            $this->text('cta_whatsapp_label', 'Texto del botón', (string) $s['cta_whatsapp_label'], Sanitizer::LABEL_MAX);
            $this->text('cta_whatsapp_short', 'Texto corto del botón (barra en móvil)', (string) $s['cta_whatsapp_short'], Sanitizer::SHORT_LABEL_MAX);
            $this->text('whatsapp_note', 'Aviso junto al botón', (string) $s['whatsapp_note'], Sanitizer::NOTE_MAX, '', 'text', 'Deja claro que el WhatsApp no es para cotizar baños portátiles.');
            $this->textarea('whatsapp_message', 'Mensaje prellenado', (string) $s['whatsapp_message'], Sanitizer::WHATSAPP_MESSAGE_MAX, 3, 'Usa {sitio} para el nombre del sitio y {url} para la página desde la que escriben.');
            echo '<p class="bpsev-hint">Enlace resultante: <a href="#" target="_blank" rel="noopener" data-bpsev-wa-test>probar en WhatsApp</a></p>';
        });
        $this->card('Botón secundario', 'Enlace a una página con más detalles (métricas, condiciones, contacto).', function () use ($s): void {
            $this->toggle('show_secondary', 'Mostrar el botón secundario', (bool) $s['show_secondary']);
            $this->text('secondary_label', 'Texto del botón', (string) $s['secondary_label'], Sanitizer::LABEL_MAX);
            $this->text('secondary_url', 'Enlace', (string) $s['secondary_url'], 255, Defaults::SECONDARY_URL, 'text', 'Ruta del sitio (/sitio-en-venta/) o URL completa https://.');
        });
        $this->card('Colores', 'Se revisa el contraste con WCAG AA: 4,5:1 para textos y 3:1 para los botones sobre el fondo. El botón de WhatsApp usa por defecto los colores oficiales (#25D366 con texto blanco), que no llegan a 4,5:1.', function () use ($s): void {
            $colors = Defaults::colors($s['colors']);
            echo '<div class="bpsev-colors">';
            foreach (['bg' => 'Fondo', 'text' => 'Texto', 'accent' => 'Acento (etiqueta y foco)', 'accent_text' => 'Texto de la etiqueta', 'whatsapp_bg' => 'Botón de WhatsApp', 'whatsapp_text' => 'Texto del botón de WhatsApp'] as $key => $label) {
                printf(
                    '<div class="bpsev-field"><label for="bpsev-color-%1$s">%2$s</label><input type="text" id="bpsev-color-%1$s" name="bpsev[colors][%1$s]" value="%3$s" class="bpsev-color" data-bpsev-color="%1$s" data-default-color="%4$s"></div>',
                    esc_attr($key),
                    esc_html($label),
                    esc_attr(is_string($colors[$key] ?? null) ? (string) $colors[$key] : Defaults::COLORS[$key]),
                    esc_attr(Defaults::COLORS[$key])
                );
            }
            echo '</div><ul class="bpsev-contrast" data-bpsev-contrast aria-live="polite"></ul>';
        });
        $this->card('Ubicaciones', 'Dónde se muestra el aviso en el sitio público.', function () use ($s): void {
            $selected = is_array($s['placements']) ? $s['placements'] : [];
            echo '<fieldset class="bpsev-checks"><legend class="screen-reader-text">Ubicaciones</legend>';
            foreach (Defaults::PLACEMENTS as $key => $placement) {
                printf(
                    '<label class="bpsev-check"><input type="checkbox" name="bpsev[placements][]" value="%1$s"%2$s><span><strong>%3$s</strong><small>%4$s</small></span></label>',
                    esc_attr($key),
                    checked(in_array($key, $selected, true), true, false),
                    esc_html($placement['label']),
                    esc_html($placement['help'])
                );
            }
            echo '</fieldset>';
        });
        $this->card('Comportamiento', 'Cuándo vuelve a aparecer después de cerrarlo y en qué páginas nunca se muestra.', function () use ($s): void {
            $this->toggle('dismissible', 'El visitante puede cerrar el aviso', (bool) $s['dismissible']);
            printf(
                '<div class="bpsev-field" data-bpsev-days><label for="bpsev-dismiss_days">Volver a mostrarlo después de (días)</label><input type="number" id="bpsev-dismiss_days" name="bpsev[dismiss_days]" value="%d" min="1" max="30" step="1" class="small-text"></div>',
                (int) $s['dismiss_days']
            );
            $paths = is_array($s['exclude_paths']) ? implode("\n", array_filter($s['exclude_paths'], 'is_string')) : '';
            $this->textarea('exclude_paths', 'No mostrar en estas rutas', $paths, 2000, 4, 'Una ruta por línea, p. ej. /cotizar/. Usa /blog/* para una sección completa.', false);
        });
        ?>
        </div>
        <aside class="bpsev-side" aria-label="Vista previa">
            <div class="bpsev-card bpsev-preview">
                <h2>Vista previa</h2>
                <p class="description">Se actualiza mientras editas. El diseño final lo aplica el sitio público.</p>
                <?php $this->preview($s); ?>
            </div>
        </aside>
        <div class="bpsev-actions">
            <button type="submit" name="publish" value="0" class="button button-secondary button-large">Guardar cambios</button>
            <button type="submit" name="publish" value="1" class="button button-primary button-large"<?php echo $canPublish ? '' : ' title="No hay integración de despliegue: solo se guardará."'; ?>>Guardar y publicar en el sitio</button>
            <span class="description"><?php echo $canPublish ? 'Publicar reconstruye el sitio público en unos minutos.' : 'bp-headless no está activo: «publicar» solo guarda.'; ?></span>
        </div>
    </form>
</div>
        <?php
    }

    /**
     * Live preview: the same markup is updated by admin.js through data-bpv-* attributes.
     *
     * @param  array<string, mixed>  $s
     */
    private function preview(array $s): void
    {
        $colors = Defaults::colors($s['colors']);
        $style = sprintf(
            '--bpv-bg:%s;--bpv-text:%s;--bpv-accent:%s;--bpv-accent-text:%s;--bpv-wa-bg:%s;--bpv-wa-text:%s',
            esc_attr($colors['bg']),
            esc_attr($colors['text']),
            esc_attr($colors['accent']),
            esc_attr($colors['accent_text']),
            esc_attr($colors['whatsapp_bg']),
            esc_attr($colors['whatsapp_text'])
        );
        $buttons = sprintf(
            '<a class="bpv-btn bpv-btn--wa" href="#" target="_blank" rel="noopener" data-bpv-show="show_whatsapp" data-bpv-link="whatsapp">%s<span data-bpv-text="cta_whatsapp_label">%s</span></a>'
            .'<a class="bpv-btn bpv-btn--ghost" href="%s" target="_blank" rel="noopener" data-bpv-show="show_secondary" data-bpv-link="secondary"><span data-bpv-text="secondary_label">%s</span></a>',
            '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.8 11.8 11.8 0 0 0 4.6 4c1.7.7 2.4.8 3.2.7a2.8 2.8 0 0 0 1.8-1.3 2.3 2.3 0 0 0 .2-1.3c-.1-.1-.3-.2-.5-.3Z"/></svg>',
            esc_html((string) $s['cta_whatsapp_label']),
            esc_url((string) $s['secondary_url']),
            esc_html((string) $s['secondary_label'])
        );
        $headline = esc_html((string) $s['headline']);
        $message = esc_html((string) $s['message']);
        $badge = esc_html(self::BADGES[(string) $s['modo']] ?? self::BADGES['venta']);
        $note = sprintf('<p class="bpv-note" data-bpv-show="show_whatsapp" data-bpv-text="whatsapp_note">%s</p>', esc_html((string) $s['whatsapp_note']));
        ?>
        <p class="bpsev-preview__label">Barra superior</p>
        <div class="bpv-bar" data-bpv-theme style="<?php echo $style; ?>">
            <p class="bpv-bar__text"><strong data-bpv-text="headline"><?php echo $headline; ?></strong> <span data-bpv-text="message"><?php echo $message; ?></span></p>
            <div class="bpv-actions"><?php echo $buttons; ?></div>
            <button type="button" class="bpv-close" tabindex="-1" aria-label="Cerrar aviso" data-bpv-show="dismissible">&times;</button>
        </div>
        <?php echo $note; ?>
        <p class="bpsev-preview__label">Barra en móvil</p>
        <div class="bpv-bar bpv-bar--mobile" data-bpv-theme style="<?php echo $style; ?>">
            <p class="bpv-bar__text"><strong data-bpv-text="headline"><?php echo $headline; ?></strong></p>
            <a class="bpv-btn bpv-btn--wa" href="#" data-bpv-show="show_whatsapp"><span data-bpv-text="cta_whatsapp_short"><?php echo esc_html((string) $s['cta_whatsapp_short']); ?></span></a>
        </div>
        <p class="bpsev-preview__label">Tarjeta lateral</p>
        <aside class="bpv-card" data-bpv-theme style="<?php echo $style; ?>">
            <span class="bpv-card__badge" data-bpv-text="badge"><?php echo $badge; ?></span>
            <p class="bpv-card__title" data-bpv-text="headline"><?php echo $headline; ?></p>
            <p class="bpv-card__text" data-bpv-text="message"><?php echo $message; ?></p>
            <div class="bpv-actions bpv-actions--stack"><?php echo $buttons; ?></div>
            <?php echo $note; ?>
        </aside>
        <?php
    }

    private function notices(): void
    {
        $key = self::NOTICE.get_current_user_id();
        $notice = get_transient($key);
        if (! is_array($notice)) {
            return;
        }
        delete_transient($key);

        $errors = is_array($notice['errors'] ?? null) ? $notice['errors'] : [];
        $warnings = is_array($notice['warnings'] ?? null) ? $notice['warnings'] : [];
        $published = $notice['published'] ?? null;
        $message = match ($published) {
            'scheduled' => 'Cambios guardados. El sitio público se reconstruirá en unos minutos.',
            'no-hook' => 'Cambios guardados, pero no hay deploy hook configurado: el sitio público no se reconstruyó (bp-headless → Ajustes del sitio → Despliegue).',
            'standalone' => 'Cambios guardados. No hay integración de despliegue activa (bp-headless).',
            default => 'Cambios guardados.',
        };
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $errors === [] ? 'success' : 'warning', esc_html($message));

        foreach (['error' => $errors, 'warning' => $warnings] as $type => $items) {
            if ($items === []) {
                continue;
            }
            echo '<div class="notice notice-'.esc_attr($type).'"><ul class="bpsev-notice-list">';
            foreach ($items as $item) {
                echo '<li>'.esc_html(is_scalar($item) ? (string) $item : '').'</li>';
            }
            echo '</ul>'.($type === 'error' ? '<p>Esos campos conservan su valor anterior.</p>' : '').'</div>';
        }
    }

    /**
     * @param  callable(): void  $body
     */
    private function card(string $title, string $help, callable $body): void
    {
        $id = 'bpsev-card-'.sanitize_title($title);
        printf('<section class="bpsev-card" aria-labelledby="%1$s"><header><h2 id="%1$s">%2$s</h2><p>%3$s</p></header><div class="bpsev-card__body">', esc_attr($id), esc_html($title), esc_html($help));
        $body();
        echo '</div></section>';
    }

    private function toggle(string $name, string $label, bool $checked): void
    {
        printf(
            '<div class="bpsev-field bpsev-field--toggle"><input type="hidden" name="bpsev[%1$s]" value="0"><label class="bpsev-switch"><input type="checkbox" id="bpsev-%1$s" name="bpsev[%1$s]" value="1"%2$s data-bpsev-toggle="%1$s"><span class="bpsev-switch__ui" aria-hidden="true"></span><span>%3$s</span></label></div>',
            esc_attr($name),
            checked($checked, true, false),
            esc_html($label)
        );
    }

    /**
     * @param  array<string, string>  $options
     */
    private function select(string $name, string $label, array $options, string $current): void
    {
        echo '<div class="bpsev-field"><label for="bpsev-'.esc_attr($name).'">'.esc_html($label).'</label><select id="bpsev-'.esc_attr($name).'" name="bpsev['.esc_attr($name).']">';
        foreach ($options as $value => $text) {
            printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($current, $value, false), esc_html($text));
        }
        echo '</select></div>';
    }

    private function text(string $name, string $label, string $value, int $max, string $placeholder = '', string $type = 'text', string $help = ''): void
    {
        printf(
            '<div class="bpsev-field"><label for="bpsev-%1$s">%2$s</label><input type="%3$s" id="bpsev-%1$s" name="bpsev[%1$s]" value="%4$s" maxlength="%5$d" placeholder="%6$s" class="regular-text"%7$s data-bpsev-input="%1$s">%8$s%9$s</div>',
            esc_attr($name),
            esc_html($label),
            esc_attr($type),
            esc_attr($value),
            $max,
            esc_attr($placeholder),
            $help !== '' ? ' aria-describedby="bpsev-'.esc_attr($name).'-help"' : '',
            $type === 'text' && $max <= Sanitizer::MESSAGE_MAX ? '<span class="bpsev-counter" id="bpsev-'.esc_attr($name).'-count" aria-live="polite"></span>' : '',
            $help !== '' ? '<p class="description" id="bpsev-'.esc_attr($name).'-help">'.esc_html($help).'</p>' : ''
        );
    }

    private function textarea(string $name, string $label, string $value, int $max, int $rows, string $help = '', bool $counter = true): void
    {
        printf(
            '<div class="bpsev-field"><label for="bpsev-%1$s">%2$s</label><textarea id="bpsev-%1$s" name="bpsev[%1$s]" rows="%3$d" maxlength="%4$d" class="large-text"%5$s data-bpsev-input="%1$s">%6$s</textarea>%7$s%8$s</div>',
            esc_attr($name),
            esc_html($label),
            $rows,
            $max,
            $help !== '' ? ' aria-describedby="bpsev-'.esc_attr($name).'-help"' : '',
            esc_textarea($value),
            $counter ? '<span class="bpsev-counter" id="bpsev-'.esc_attr($name).'-count" aria-live="polite"></span>' : '',
            $help !== '' ? '<p class="description" id="bpsev-'.esc_attr($name).'-help">'.esc_html($help).'</p>' : ''
        );
    }

    /** Public site used to resolve {url} in the preview: BP_FRONTEND_URL (headless) or this WordPress. */
    private static function publicSiteUrl(): string
    {
        $front = defined('BP_FRONTEND_URL') ? constant('BP_FRONTEND_URL') : getenv('BP_FRONTEND_URL');

        return is_string($front) && $front !== '' ? rtrim($front, '/').'/' : home_url('/');
    }
}
