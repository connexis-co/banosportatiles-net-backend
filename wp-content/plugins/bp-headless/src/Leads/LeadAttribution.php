<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Leads;

/**
 * Human-readable attribution of a lead (pure): where the CTA was and where the visit came from. Works on the
 * contract names of LeadData::attribution() (origen, servicio_uri, referrer, landing, utm_*, click ids).
 */
final class LeadAttribution
{
    /** Known CTA locations sent by the front (any other [a-z_] value is shown as is). */
    public const ORIGINS = [
        'hero' => 'Hero de la página',
        'header' => 'Botón de la cabecera',
        'header_movil' => 'Cabecera en el móvil',
        'aside' => 'Columna lateral',
        'banner' => 'Banner de cotización',
        'card' => 'Tarjeta',
        'mega' => 'Mega-menú',
        'cuerpo' => 'Cuerpo del contenido',
        'precio' => 'Bloque de precio',
        'modal' => 'Ventana de cotización',
        'hero_formulario' => 'Formulario del hero',
        'pagina_cotizar' => 'Página /cotizar/',
        'cotizar' => 'Página /cotizar/',
        'pagina_contacto' => 'Página /contacto/',
        'contacto' => 'Página /contacto/',
        'venta_sitio' => 'Aviso de sitio en venta',
    ];

    /** Order and labels of the stored attribution (lead detail and email). */
    public const LABELS = [
        'origen' => 'Botón (ubicación del CTA)',
        'servicio_uri' => 'Servicio (página)',
        'landing' => 'Primera página de la visita',
        'referrer' => 'Llegó desde (referrer)',
        'utm_source' => 'utm_source',
        'utm_medium' => 'utm_medium',
        'utm_campaign' => 'utm_campaign',
        'utm_term' => 'utm_term',
        'utm_content' => 'utm_content',
        'gclid' => 'gclid (Google Ads)',
        'gbraid' => 'gbraid (Google Ads, iOS)',
        'wbraid' => 'wbraid (Google Ads, iOS)',
        'fbclid' => 'fbclid (Meta)',
    ];

    public static function origin(string $origen): string
    {
        return self::ORIGINS[$origen] ?? $origen;
    }

    /**
     * Acquisition channel in a few words: Google Ads / Meta (click ids), utm_source / utm_medium, the referrer
     * host or «Directo».
     *
     * @param  array<string, string>  $attribution
     */
    public static function channel(array $attribution): string
    {
        foreach (['gclid', 'gbraid', 'wbraid'] as $key) {
            if (($attribution[$key] ?? '') !== '') {
                return 'Google Ads';
            }
        }
        if (($attribution['fbclid'] ?? '') !== '') {
            return 'Meta';
        }
        $source = $attribution['utm_source'] ?? '';
        if ($source !== '') {
            $medium = $attribution['utm_medium'] ?? '';

            return $medium !== '' ? $source.' / '.$medium : $source;
        }
        $referrer = $attribution['referrer'] ?? '';
        if ($referrer !== '') {
            $host = (string) parse_url($referrer, PHP_URL_HOST);

            return $host !== '' ? (string) preg_replace('/^www\./', '', $host) : $referrer;
        }

        return 'Directo';
    }

    /**
     * «Botón · canal» for the list of leads (e.g. «Hero de la página · Google Ads»).
     *
     * @param  array<string, string>  $attribution
     */
    public static function summary(array $attribution): string
    {
        $origen = $attribution['origen'] ?? '';

        return ($origen !== '' ? self::origin($origen) : '—').' · '.self::channel($attribution);
    }

    /**
     * UTM keys of LeadData::$utm (source, gclid…) → contract names (utm_source, gclid…).
     *
     * @param  array<array-key, mixed>  $utm
     * @return array<string, string>
     */
    public static function fromUtm(array $utm): array
    {
        $out = [];
        foreach (LeadValidator::UTM_KEYS as $key) {
            $value = $utm[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                $out[LeadValidator::attributionKey($key)] = (string) $value;
            }
        }

        return $out;
    }
}
