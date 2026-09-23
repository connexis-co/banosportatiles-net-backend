<?php

declare(strict_types=1);

namespace BanosPortatiles\SitioEnVenta\Settings;

/**
 * Option name, allowed values and the default settings (texts depend on the mode).
 */
final class Defaults
{
    public const OPTION = 'bp_sitio_en_venta';

    public const MODES = [
        'venta' => 'Venta',
        'alquiler' => 'Alquiler',
        'venta_o_alquiler' => 'Venta o alquiler',
    ];

    /** @var array<string, array{label: string, help: string}> */
    public const PLACEMENTS = [
        'top_bar' => ['label' => 'Barra superior', 'help' => 'Franja arriba de todo el sitio.'],
        'bottom_bar' => ['label' => 'Barra inferior fija', 'help' => 'Siempre visible abajo; ideal en móvil.'],
        'home_after_hero' => ['label' => 'Inicio, después del hero', 'help' => 'Bloque destacado solo en la página de inicio.'],
        'sidebar_card' => ['label' => 'Tarjeta lateral', 'help' => 'Columna lateral en páginas y posts largos.'],
        'footer_block' => ['label' => 'Bloque en el footer', 'help' => 'Antes del pie de página, en todo el sitio.'],
    ];

    public const COLORS = [
        'bg' => '#0f172a',
        'text' => '#f8fafc',
        'accent' => '#25d366',
        'accent_text' => '#052e16',
    ];

    public const SECONDARY_URL = '/sitio-en-venta/';

    /**
     * @return array{headline: string, message: string, whatsapp_message: string, cta_whatsapp_label: string, secondary_label: string}
     */
    public static function texts(string $modo): array
    {
        return match ($modo) {
            'alquiler' => [
                'headline' => 'Este sitio web está disponible para alquiler',
                'message' => 'Recibe en tu empresa las solicitudes de cotización que llegan a este sitio. Pregunta por las condiciones del alquiler.',
                'whatsapp_message' => 'Hola, me interesa alquilar el sitio {sitio} ({url}). ¿Cuáles son las condiciones?',
                'cta_whatsapp_label' => 'Consultar por WhatsApp',
                'secondary_label' => 'Ver condiciones',
            ],
            'venta_o_alquiler' => [
                'headline' => 'Este sitio web está en venta o alquiler',
                'message' => 'Dominio, contenido y posicionamiento en el nicho de baños portátiles en Colombia, disponibles para compra o alquiler. Hablemos de la opción que más le sirve a tu empresa.',
                'whatsapp_message' => 'Hola, me interesa el sitio {sitio} ({url}). ¿Qué opciones de compra o alquiler tienen?',
                'cta_whatsapp_label' => 'Consultar por WhatsApp',
                'secondary_label' => 'Ver detalles',
            ],
            default => [
                'headline' => 'Este sitio web está en venta',
                'message' => 'Dominio, contenido y posicionamiento en el nicho de baños portátiles en Colombia. Si tu empresa quiere quedarse con este canal de clientes, hablemos.',
                'whatsapp_message' => 'Hola, me interesa comprar el sitio {sitio} ({url}). ¿Me compartes más información?',
                'cta_whatsapp_label' => 'Consultar por WhatsApp',
                'secondary_label' => 'Ver detalles',
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function settings(string $modo = 'venta'): array
    {
        $modo = isset(self::MODES[$modo]) ? $modo : 'venta';
        $texts = self::texts($modo);

        return [
            'enabled' => false,
            'modo' => $modo,
            'headline' => $texts['headline'],
            'message' => $texts['message'],
            'whatsapp_number' => '',
            'whatsapp_message' => $texts['whatsapp_message'],
            'show_whatsapp' => true,
            'cta_whatsapp_label' => $texts['cta_whatsapp_label'],
            'show_secondary' => true,
            'secondary_label' => $texts['secondary_label'],
            'secondary_url' => self::SECONDARY_URL,
            'colors' => self::COLORS,
            'placements' => ['top_bar', 'sidebar_card'],
            'dismissible' => true,
            'dismiss_days' => 7,
            'exclude_paths' => ['/cotizar/'],
        ];
    }
}
