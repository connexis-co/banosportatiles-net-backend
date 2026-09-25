<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Leads;

use BanosPortatiles\Headless\Support\Arr;

/**
 * «Solicitar cotización» CTAs (pure): the site defaults of «Ajustes del sitio → Formularios» (/site → forms) and
 * the per-page «Cotización» group resolved into node.lead.
 *
 *   /site → forms: {turnstile_site_key, cta_mode, modal: {eyebrow, title, subtitle, success}, whatsapp: {bg, text}}
 *   node.lead:     {service?: {label, uri}, mode: "modal"|"page", title?}
 */
final class QuoteCta
{
    public const MODES = [
        'modal' => 'Abrir el formulario en una ventana (modal)',
        'page' => 'Llevar a la página /cotizar/',
    ];

    public const INHERIT = 'inherit';

    public const PAGE_MODES = [self::INHERIT => 'Heredar de «Ajustes del sitio → Formularios»'] + self::MODES;

    public const DEFAULT_MODE = 'modal';

    /** Same texts as the front defaults (frontend/src/content/schema.ts → formsSettingsSchema). */
    public const MODAL_DEFAULTS = [
        'eyebrow' => 'Cotización gratuita',
        'title' => 'Cotiza sin compromiso',
        'subtitle' => 'Déjanos tu nombre y celular; te respondemos con disponibilidad, tiempos de entrega y costos para tu ciudad.',
        'success' => 'Te contactaremos pronto para confirmar disponibilidad, tiempos de entrega y costos.',
    ];

    /** Official WhatsApp colors (white on #25D366 is 1.98:1, below WCAG AA for text). */
    public const WHATSAPP_DEFAULTS = ['bg' => '#25d366', 'text' => '#ffffff'];

    /** Templates with the «Cotización» group (plus the "equipo" post type). */
    public const TEMPLATES = ['home', 'hub-servicio', 'servicio', 'ciudad', 'equipos', 'landing', 'equipo'];

    /** Pages that can be the service of a quote (and are their own service automatically). */
    public const SERVICE_TEMPLATES = ['hub-servicio', 'servicio'];

    public const MAX_TITLE = 120;

    /**
     * «Ajustes del sitio → Formularios» → /site → forms. Texts and colors never come back empty (defaults).
     *
     * @param  array<array-key, mixed>  $forms
     * @return array{turnstile_site_key: string, cta_mode: string, modal: array<string, string>, whatsapp: array{bg: string, text: string}}
     */
    public static function site(array $forms): array
    {
        $modal = Arr::array($forms, 'modal');
        $whatsapp = Arr::array($forms, 'whatsapp');
        $texts = [];
        foreach (self::MODAL_DEFAULTS as $key => $default) {
            $texts[$key] = Arr::string($modal, $key) ?: $default;
        }

        return [
            'turnstile_site_key' => Arr::string($forms, 'turnstile_site_key'),
            'cta_mode' => self::mode(Arr::string($forms, 'cta_mode')) ?? self::DEFAULT_MODE,
            'modal' => $texts,
            'whatsapp' => [
                'bg' => self::color($whatsapp['bg'] ?? null) ?? self::WHATSAPP_DEFAULTS['bg'],
                'text' => self::color($whatsapp['text'] ?? null) ?? self::WHATSAPP_DEFAULTS['text'],
            ],
        ];
    }

    public static function supports(string $template): bool
    {
        return in_array($template, self::TEMPLATES, true);
    }

    public static function isServiceTemplate(string $template): bool
    {
        return in_array($template, self::SERVICE_TEMPLATES, true);
    }

    /**
     * node.lead. $local: the «Cotización» group (null when the template has none). $self: {label, uri} of the
     * node itself. $chosen: {label, uri} of the service picked in the group, when it is a published service page.
     * Service: the chosen one, else the node itself on hub-servicio/servicio, else none (the visitor picks it).
     *
     * @param  array{label: string, uri: string}  $self
     * @param  array{label: string, uri: string}|null  $chosen
     * @return array{service?: array{label: string, uri: string}, mode: string, title?: string}
     */
    public static function resolve(string $siteMode, mixed $local, string $template, array $self, ?array $chosen): array
    {
        $local = self::supports($template) && is_array($local) ? $local : [];
        $service = $chosen ?? (self::isServiceTemplate($template) ? $self : null);
        $title = mb_substr(Arr::string($local, 'title'), 0, self::MAX_TITLE);

        return ($service !== null ? ['service' => $service] : [])
            + ['mode' => self::mode(Arr::string($local, 'mode')) ?? self::mode($siteMode) ?? self::DEFAULT_MODE]
            + ($title !== '' ? ['title' => $title] : []);
    }

    /** "modal" | "page" (also "pagina"/"página" from the seed); null for "inherit" or anything else. */
    public static function mode(string $value): ?string
    {
        $value = strtolower(trim($value));

        return match ($value) {
            'modal' => 'modal',
            'page', 'pagina', 'página' => 'page',
            default => null,
        };
    }

    /** "#abc" / "#AABBCC" → "#aabbcc"; null when invalid. */
    public static function color(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $m) === 1) {
            return '#'.$m[1].$m[1].$m[2].$m[2].$m[3].$m[3];
        }

        return preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : null;
    }
}
