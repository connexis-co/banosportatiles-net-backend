<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Leads;

use BanosPortatiles\Headless\Content\PostTypes;
use BanosPortatiles\Headless\Content\Taxonomies;

/**
 * Persists leads as private "lead" posts with prefixed meta.
 */
final class LeadRepository
{
    public const META_PREFIX = '_bp_lead_';

    public const STATES = ['nuevo' => 'Nuevo', 'contactado' => 'Contactado', 'cotizado' => 'Cotizado', 'cerrado' => 'Cerrado', 'descartado' => 'Descartado'];

    /**
     * @param  array{ip_hash: string, user_agent: string}  $context
     * @return array{id: int, reference: string}|\WP_Error
     */
    public function create(LeadData $lead, array $context): array|\WP_Error
    {
        $title = implode(' · ', array_filter([$lead->nombre, $lead->servicio ?? 'Cotización', $lead->ciudad]));
        $id = wp_insert_post([
            'post_type' => PostTypes::LEAD,
            'post_status' => 'private',
            'post_title' => mb_substr($title, 0, 200),
            'post_author' => 0,
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        ], true);

        if ($id instanceof \WP_Error) {
            return $id;
        }

        $reference = wp_generate_uuid4();
        $meta = [
            'ref' => $reference,
            'nombre' => $lead->nombre,
            'telefono' => $lead->telefono,
            'email' => $lead->email ?? '',
            'ciudad' => $lead->ciudad ?? '',
            'servicio' => $lead->servicio ?? '',
            'mensaje' => $lead->mensaje ?? '',
            'fecha_evento' => $lead->fechaEvento ?? '',
            'cantidad' => $lead->cantidad !== null ? (string) $lead->cantidad : '',
            'pagina' => $lead->pagina ?? '',
            'utm' => (string) wp_json_encode($lead->utm),
            'consentimiento' => current_time('mysql'),
            'ip_hash' => $context['ip_hash'],
            'user_agent' => mb_substr($context['user_agent'], 0, 255),
            'estado' => 'nuevo',
        ];
        foreach ($meta as $key => $value) {
            update_post_meta($id, self::META_PREFIX.$key, $value);
        }

        if ($lead->ciudad !== null) {
            $term = get_term_by('slug', sanitize_title($lead->ciudad), Taxonomies::CIUDAD);
            if ($term instanceof \WP_Term) {
                wp_set_object_terms($id, [$term->term_id], Taxonomies::CIUDAD);
            }
        }

        return ['id' => $id, 'reference' => $reference];
    }

    public static function meta(int $leadId, string $key): string
    {
        $value = get_post_meta($leadId, self::META_PREFIX.$key, true);

        return is_scalar($value) ? (string) $value : '';
    }
}
