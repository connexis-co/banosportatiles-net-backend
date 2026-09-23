<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Admin;

use BanosPortatiles\Headless\Content\PostTypes;
use BanosPortatiles\Headless\Contracts\Hookable;
use BanosPortatiles\Headless\Leads\LeadRepository;

/**
 * Leads admin: useful list columns, a read-only data box and a sales status (nuevo → cerrado).
 */
final class LeadAdmin implements Hookable
{
    private const NONCE = 'bp_lead_status';

    private const FIELDS = [
        'nombre' => 'Nombre', 'telefono' => 'Teléfono', 'email' => 'Email', 'ciudad' => 'Ciudad',
        'servicio' => 'Servicio', 'fecha_evento' => 'Fecha del evento', 'cantidad' => 'Cantidad',
        'mensaje' => 'Mensaje', 'pagina' => 'Página de origen', 'utm' => 'UTM', 'consentimiento' => 'Consentimiento (fecha)',
        'consentimiento_comercial' => 'Acepta comunicaciones comerciales (1 = sí)',
        'ref' => 'Referencia', 'status' => 'Estado del email', 'email_error' => 'Error del email',
        'email_attempts' => 'Intentos de envío', 'webhook' => 'Webhook',
    ];

    public function register(): void
    {
        add_filter('manage_'.PostTypes::LEAD.'_posts_columns', [$this, 'columns']);
        add_action('manage_'.PostTypes::LEAD.'_posts_custom_column', [$this, 'renderColumn'], 10, 2);
        add_action('add_meta_boxes_'.PostTypes::LEAD, [$this, 'metaBoxes']);
        add_action('save_post_'.PostTypes::LEAD, [$this, 'saveStatus'], 10, 1);
    }

    /**
     * @param  array<string, string>  $columns
     * @return array<string, string>
     */
    public function columns(array $columns): array
    {
        return [
            'cb' => $columns['cb'] ?? '',
            'title' => 'Lead',
            'bp_phone' => 'Teléfono',
            'bp_email' => 'Email',
            'bp_service' => 'Servicio',
            'bp_status' => 'Estado',
            'bp_email_status' => 'Aviso',
            'date' => $columns['date'] ?? 'Fecha',
        ] + $columns;
    }

    public function renderColumn(string $column, int $postId): void
    {
        $value = match ($column) {
            'bp_phone' => LeadRepository::meta($postId, 'telefono'),
            'bp_email' => LeadRepository::meta($postId, 'email'),
            'bp_service' => LeadRepository::meta($postId, 'servicio'),
            'bp_status' => LeadRepository::STATES[LeadRepository::meta($postId, 'estado')] ?? 'Nuevo',
            'bp_email_status' => match (LeadRepository::meta($postId, 'status')) {
                'email_sent' => 'Enviado',
                'email_failed' => 'Falló (wp bp leads resend '.$postId.')',
                'email_disabled' => 'Desactivado',
                'email_skipped' => 'Sin destinatario',
                default => '—',
            },
            default => null,
        };
        if ($value === null) {
            return;
        }
        if ($column === 'bp_phone' && $value !== '') {
            printf('<a href="https://wa.me/%s" target="_blank" rel="noopener">%s</a>', esc_attr(ltrim($value, '+')), esc_html($value));

            return;
        }
        echo esc_html($value !== '' ? $value : '—');
    }

    public function metaBoxes(): void
    {
        add_meta_box('bp_lead_data', 'Datos del lead', [$this, 'renderData'], PostTypes::LEAD, 'normal', 'high');
        add_meta_box('bp_lead_status', 'Estado comercial', [$this, 'renderStatus'], PostTypes::LEAD, 'side', 'high');
    }

    public function renderData(\WP_Post $post): void
    {
        echo '<table class="widefat striped"><tbody>';
        foreach (self::FIELDS as $key => $label) {
            $value = LeadRepository::meta($post->ID, $key);
            printf('<tr><th style="width:30%%">%s</th><td>%s</td></tr>', esc_html($label), nl2br(esc_html($value !== '' ? $value : '—')));
        }
        echo '</tbody></table><p class="description">Datos personales tratados según la Ley 1581 de 2012. No los compartas fuera de los proveedores autorizados.</p>';
    }

    public function renderStatus(\WP_Post $post): void
    {
        wp_nonce_field(self::NONCE, self::NONCE.'_nonce');
        $current = LeadRepository::meta($post->ID, 'estado') ?: 'nuevo';
        echo '<select name="bp_lead_estado" style="width:100%">';
        foreach (LeadRepository::STATES as $value => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($current, $value, false), esc_html($label));
        }
        echo '</select>';
    }

    public function saveStatus(int $postId): void
    {
        $nonce = isset($_POST[self::NONCE.'_nonce']) && is_string($_POST[self::NONCE.'_nonce']) ? sanitize_text_field(wp_unslash($_POST[self::NONCE.'_nonce'])) : '';
        if ($nonce === '' || wp_verify_nonce($nonce, self::NONCE) === false || ! current_user_can('edit_post', $postId)) {
            return;
        }
        $status = isset($_POST['bp_lead_estado']) && is_string($_POST['bp_lead_estado']) ? sanitize_key($_POST['bp_lead_estado']) : '';
        if (isset(LeadRepository::STATES[$status])) {
            update_post_meta($postId, LeadRepository::META_PREFIX.'estado', $status);
        }
    }
}
