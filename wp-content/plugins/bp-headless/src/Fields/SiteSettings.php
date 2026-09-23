<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Fields;

use BanosPortatiles\Headless\Config;

/**
 * Options page "Ajustes del sitio" (stored as options "bp_site_*"). Mirrors seed/site.yaml.
 */
final class SiteSettings
{
    public const MENU_SLUG = 'bp-site-settings';

    public const DEPLOY_HOOK_FIELD = 'field_bp_site_deploy_hook_url';

    public const SOCIAL_NETWORKS = [
        'facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok',
        'youtube' => 'YouTube', 'linkedin' => 'LinkedIn', 'x' => 'X',
    ];

    public const BANNER_VARIANTS = ['dark' => 'Oscuro', 'light' => 'Claro', 'accent' => 'Acento'];

    /**
     * @return array<string, mixed>
     */
    public static function page(): array
    {
        return [
            'page_title' => 'Ajustes del sitio',
            'menu_title' => 'Ajustes del sitio',
            'menu_slug' => self::MENU_SLUG,
            'capability' => 'edit_others_pages',
            'post_id' => Config::OPTIONS_ID,
            'autoload' => true,
            'redirect' => false,
            'position' => 3,
            'icon_url' => 'dashicons-admin-site-alt3',
            'update_button' => 'Guardar ajustes',
            'updated_message' => 'Ajustes guardados. El sitio público se reconstruirá en ~1 minuto.',
        ];
    }

    /** @return array<string, mixed> */
    public static function group(): array
    {
        $f = new FieldBuilder('site');
        $link = static fn (FieldBuilder $l): array => [
            $l->text('label', 'Texto'),
            $l->text('href', 'Enlace'),
        ];

        return FieldGroups::group('site', 'Ajustes del sitio', [[['param' => 'options_page', 'operator' => '==', 'value' => self::MENU_SLUG]]], [
            $f->tab('tab_brand', 'Marca y contacto'),
            $f->group('brand', 'Marca', static fn (FieldBuilder $b): array => [
                $b->text('name', 'Nombre', ['default_value' => 'BañosPortátiles.net']),
                $b->text('tagline', 'Lema'),
                $b->image('logo', 'Logo'),
            ]),
            $f->group('contact', 'Contacto', static fn (FieldBuilder $c): array => [
                $c->text('whatsapp', 'WhatsApp', ['instructions' => 'Formato internacional: +57…']),
                $c->text('phone', 'Teléfono', ['instructions' => 'Formato internacional: +57…']),
                $c->email('email', 'Email'),
                $c->text('horario', 'Horario', ['placeholder' => 'Lun–Sáb 7:00–18:00']),
            ]),
            $f->repeater('social', 'Redes sociales', static fn (FieldBuilder $s): array => [
                $s->select('network', 'Red', self::SOCIAL_NETWORKS),
                $s->url('url', 'URL'),
            ]),

            $f->tab('tab_legal', 'Legal'),
            $f->group('legal', 'Responsable del tratamiento de datos', static fn (FieldBuilder $l): array => [
                $l->text('responsable', 'Responsable (nombre visible)'),
                $l->text('razon_social', 'Razón social'),
                $l->text('nit', 'NIT'),
                $l->text('direccion', 'Dirección'),
                $l->text('ciudad', 'Ciudad'),
                $l->email('email_datos', 'Email para datos personales'),
            ], ['instructions' => 'Ley 1581 de 2012. Deja vacío lo que aún no esté confirmado.']),

            $f->tab('tab_tracking', 'Analítica y formularios'),
            $f->group('analytics', 'Analítica', static fn (FieldBuilder $a): array => [
                $a->text('ga4', 'GA4 (G-…)'),
                $a->text('gtm', 'Google Tag Manager (GTM-…)'),
            ]),
            $f->group('forms', 'Formularios', static fn (FieldBuilder $fo): array => [
                $fo->text('turnstile_site_key', 'Cloudflare Turnstile — site key (pública)'),
                $fo->email('leads_email', 'Email que recibe los leads', ['instructions' => 'Vacío = email de contacto o del administrador.']),
                $fo->url('leads_webhook_url', 'Webhook de leads (opcional)', ['instructions' => 'n8n, Make, CRM… Recibe un POST firmado (HMAC) por cada lead.']),
            ]),

            $f->tab('tab_banner', 'Banner de venta'),
            $f->group('sale_banner', 'Banner «sitio en venta»', static fn (FieldBuilder $s): array => [
                $s->trueFalse('enabled', 'Activo'),
                $s->text('message', 'Mensaje'),
                $s->text('cta_label', 'Texto del botón'),
                $s->text('cta_href', 'Enlace del botón', ['default_value' => '/sitio-en-venta/']),
                $s->select('variant', 'Variante', self::BANNER_VARIANTS, ['default_value' => 'dark']),
            ]),

            $f->tab('tab_menus', 'Menús'),
            $f->group('menus', 'Menús', static fn (FieldBuilder $m): array => [
                $m->repeater('header', 'Menú principal', static fn (FieldBuilder $h): array => [
                    ...$link($h),
                    $h->repeater('children', 'Submenú', $link),
                ], ['layout' => 'block', 'button_label' => 'Añadir elemento']),
                $m->repeater('footer', 'Footer (columnas)', static fn (FieldBuilder $c): array => [
                    $c->text('title', 'Título de la columna'),
                    $c->repeater('links', 'Enlaces', $link),
                ], ['layout' => 'block', 'button_label' => 'Añadir columna']),
            ]),

            $f->tab('tab_deploy', 'Despliegue'),
            $f->group('deploy', 'Despliegue del sitio público', static fn (FieldBuilder $d): array => [
                $d->url('hook_url', 'Deploy hook (Cloudflare Workers Builds)', ['instructions' => 'Solo administradores. Si BP_DEPLOY_HOOK_URL está en wp-config.php, esa constante manda.']),
            ]),
        ], ['style' => 'seamless']);
    }
}
