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

    /**
     * Banner colors. whatsapp_bg / whatsapp_text: the WhatsApp button (official brand colors by default; white
     * on #25D366 is 1.98:1, below WCAG AA, and the settings page warns about it).
     */
    public const COLORS = [
        'bg' => '#0f172a',
        'text' => '#f8fafc',
        'accent' => '#25d366',
        'accent_text' => '#052e16',
        'whatsapp_bg' => '#25d366',
        'whatsapp_text' => '#ffffff',
    ];

    /**
     * Stored colors over the defaults, in the order of COLORS (options saved before a color existed get its
     * default).
     *
     * @return array<string, string>
     */
    public static function colors(mixed $stored): array
    {
        $colors = self::COLORS;
        if (is_array($stored)) {
            foreach (array_keys(self::COLORS) as $key) {
                if (is_string($stored[$key] ?? null) && $stored[$key] !== '') {
                    $colors[$key] = $stored[$key];
                }
            }
        }

        return $colors;
    }

    public const SECONDARY_URL = '/sitio-en-venta/';

    /**
     * Suggested texts of each mode. Explicit on purpose: the WhatsApp of the notice is ONLY to buy or rent the
     * website, never to ask for a quote of portable toilets (that goes through the site's form).
     *
     * @return array{headline: string, message: string, whatsapp_message: string, cta_whatsapp_label: string, cta_whatsapp_short: string, whatsapp_note: string, secondary_label: string}
     */
    public static function texts(string $modo): array
    {
        return match ($modo) {
            'alquiler' => [
                'headline' => 'Este sitio web está disponible para alquiler',
                'message' => '¿Tienes una empresa de baños portátiles o saneamiento? Arrienda este sitio web: dominio, contenido y posicionamiento. El WhatsApp es solo para negociar el sitio.',
                'whatsapp_message' => 'Hola, me interesa alquilar el sitio web {sitio} (dominio, contenido y posicionamiento). No es para cotizar baños portátiles. Lo vi en {url}',
                'cta_whatsapp_label' => 'WhatsApp: alquilar este sitio',
                'cta_whatsapp_short' => 'Alquilar sitio',
                'whatsapp_note' => 'Solo para alquilar este sitio web. Para cotizar baños portátiles usa el formulario.',
                'secondary_label' => 'Ver condiciones del alquiler',
            ],
            'venta_o_alquiler' => [
                'headline' => 'Este sitio web está en venta o alquiler',
                'message' => '¿Tienes una empresa de baños portátiles o saneamiento? Compra o arrienda este sitio web: dominio, contenido y posicionamiento. El WhatsApp es solo para negociar el sitio.',
                'whatsapp_message' => 'Hola, me interesa comprar o alquilar el sitio web {sitio} (dominio, contenido y posicionamiento). No es para cotizar baños portátiles. Lo vi en {url}',
                'cta_whatsapp_label' => 'WhatsApp: comprar este sitio',
                'cta_whatsapp_short' => 'Comprar sitio',
                'whatsapp_note' => 'Solo para comprar o alquilar este sitio web. Para cotizar baños portátiles usa el formulario.',
                'secondary_label' => 'Ver detalles de la venta',
            ],
            default => [
                'headline' => 'Este sitio web está en venta',
                'message' => '¿Tienes una empresa de baños portátiles o saneamiento? Compra este sitio web: dominio, contenido y posicionamiento. El WhatsApp es solo para negociar el sitio.',
                'whatsapp_message' => 'Hola, me interesa comprar el sitio web {sitio} (dominio, contenido y posicionamiento). No es para cotizar baños portátiles. Lo vi en {url}',
                'cta_whatsapp_label' => 'WhatsApp: comprar este sitio',
                'cta_whatsapp_short' => 'Comprar sitio',
                'whatsapp_note' => 'Solo para comprar este sitio web. Para cotizar baños portátiles usa el formulario.',
                'secondary_label' => 'Ver detalles de la venta',
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
            'cta_whatsapp_short' => $texts['cta_whatsapp_short'],
            'whatsapp_note' => $texts['whatsapp_note'],
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
