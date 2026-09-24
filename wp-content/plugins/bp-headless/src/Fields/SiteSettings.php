<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Fields;

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Normalizer\TocResolver;
use BanosPortatiles\Headless\Reviews\RatingPolicy;
use BanosPortatiles\Headless\Reviews\RatingSettings;

/**
 * Options page "Ajustes del sitio" (stored as options "bp_site_*"). Mirrors seed/site.yaml.
 */
final class SiteSettings
{
    public const MENU_SLUG = 'bp-site-settings';

    public const DEPLOY_HOOK_FIELD = 'field_bp_site_deploy_hook_url';

    /** Top-level menu items: manual children or a submenu built by the front from the content. */
    public const MENU_KINDS = [
        'links' => 'Enlaces del submenú (manuales)',
        'ciudades' => 'Ciudades por región (automático; los enlaces manuales son destacados)',
        'servicios' => 'Servicios (automático; los enlaces manuales son destacados)',
        'blog' => 'Blog: categorías y guías (automático; los enlaces manuales son destacados)',
    ];

    public const HEADER_DEFAULTS = ['cta_label' => 'Solicitar cotización', 'cta_short' => 'Cotizar', 'cta_href' => '/cotizar/'];

    public const SOCIAL_NETWORKS = [
        'facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok',
        'youtube' => 'YouTube', 'linkedin' => 'LinkedIn', 'x' => 'X',
    ];

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
        // Enlaces del menú principal: además, una frase corta que el mega-menú muestra bajo el texto.
        $menuLink = static fn (FieldBuilder $l): array => [
            ...$link($l),
            $l->text('description', 'Descripción corta', ['instructions' => 'Opcional. Una frase de máx. 80 caracteres que se ve bajo el enlace en el mega-menú.', 'maxlength' => 110]),
        ];

        return FieldGroups::group('site', 'Ajustes del sitio', [[['param' => 'options_page', 'operator' => '==', 'value' => self::MENU_SLUG]]], [
            $f->tab('tab_brand', 'Marca y contacto'),
            $f->group('brand', 'Marca', static fn (FieldBuilder $b): array => [
                $b->text('name', 'Nombre', ['default_value' => 'BañosPortátiles.net']),
                $b->text('tagline', 'Lema'),
                $b->image('logo', 'Logo', ['instructions' => 'SVG recomendado (Safe SVG lo sanea al subirlo y la API lo entrega en línea, máx. 100 KB). PNG o WebP también sirven. Vacío = el logo del sitio.']),
                $b->image('logo_dark', 'Logo para fondos oscuros', ['instructions' => 'Opcional (pie de página y barras oscuras). Mismas reglas que el logo.']),
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
                $l->text('telefono', 'Teléfono del responsable', ['instructions' => 'Solo se muestra en las páginas legales.']),
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
                $fo->email('leads_email', 'Email que recibe los leads', ['instructions' => 'Vacío = contacto@banosportatiles.net. La constante BP_LEADS_EMAIL_TO (wp-config) tiene prioridad; las copias van en BP_LEADS_EMAIL_CC.']),
                $fo->url('leads_webhook_url', 'Webhook de leads (opcional)', ['instructions' => 'n8n, Make, CRM… Recibe un POST firmado (HMAC) por cada lead.']),
            ]),

            $f->tab('tab_menus', 'Menús'),
            $f->group('header', 'Botón de la cabecera', static fn (FieldBuilder $h): array => [
                $h->text('cta_label', 'Texto', ['default_value' => self::HEADER_DEFAULTS['cta_label']]),
                $h->text('cta_short', 'Texto corto (móvil)', ['default_value' => self::HEADER_DEFAULTS['cta_short']]),
                $h->text('cta_href', 'Enlace', ['default_value' => self::HEADER_DEFAULTS['cta_href']]),
            ], ['layout' => 'row', 'instructions' => 'Botón fijo de la cabecera y del menú móvil (no va como elemento del menú).']),
            $f->group('menus', 'Menús', static fn (FieldBuilder $m): array => [
                $m->repeater('header', 'Menú principal', static fn (FieldBuilder $h): array => [
                    ...$menuLink($h),
                    $h->text('icon', 'Icono', ['instructions' => 'Nombre de Lucide (toilet, map-pinned…). Vacío = el front lo deduce.']),
                    $h->select('kind', 'Submenú', self::MENU_KINDS, ['default_value' => 'links']),
                    $h->repeater('children', 'Submenú', static fn (FieldBuilder $c): array => [
                        ...$menuLink($c),
                        $c->text('icon', 'Icono'),
                        $c->text('group', 'Grupo', ['instructions' => 'Encabezado que agrupa enlaces seguidos (p. ej. «Pozos y tanques sépticos»).']),
                    ], ['layout' => 'block']),
                ], ['layout' => 'block', 'button_label' => 'Añadir elemento']),
                $m->repeater('secondary', 'Enlaces secundarios', static fn (FieldBuilder $l): array => [
                    ...$link($l),
                    $l->text('icon', 'Icono'),
                ], ['instructions' => 'Nosotros, preguntas frecuentes, contacto…: pie del menú móvil y barra superior en escritorio.', 'button_label' => 'Añadir enlace']),
                $m->repeater('footer', 'Footer (columnas)', static fn (FieldBuilder $c): array => [
                    $c->text('title', 'Título de la columna'),
                    $c->repeater('links', 'Enlaces', $link),
                ], ['layout' => 'block', 'button_label' => 'Añadir columna']),
            ]),

            $f->tab('tab_microcopy', 'Textos globales'),
            $f->group('microcopy', 'Textos globales', self::microcopyFields(...), [
                'instructions' => 'Textos de conversión que se repiten en el sitio. Vacío = el texto por defecto del sitio.',
            ]),

            $f->tab('tab_ratings', 'Valoraciones'),
            $f->group('ratings', 'Valoraciones y opiniones', self::ratingsFields(...), [
                'instructions' => 'Votos con estrellas y opiniones con texto (plugin Site Reviews). Las opiniones se moderan en «Reseñas».',
            ]),

            $f->tab('tab_toc', 'Tabla de contenidos'),
            $f->group('toc', 'Tabla de contenidos', static fn (FieldBuilder $t): array => [
                $t->checkbox('enabled_types', 'Mostrar en', TocResolver::TYPES, [
                    'default_value' => TocResolver::DEFAULT_TYPES,
                    'layout' => 'vertical',
                    'instructions' => 'Cada página puede mostrarla u ocultarla en su caja «Tabla de contenidos».',
                ]),
                $t->text('title', 'Título', ['default_value' => TocResolver::DEFAULT_TITLE]),
                $t->text('title_blog', 'Título en el blog', ['default_value' => TocResolver::DEFAULT_TITLE_BLOG]),
                $t->select('depth', 'Niveles', ['2' => 'Solo H2', '3' => 'H2 y H3'], ['default_value' => (string) TocResolver::DEFAULT_DEPTH]),
                $t->select('depth_blog', 'Niveles en el blog', ['2' => 'Solo H2', '3' => 'H2 y H3'], ['allow_null' => 1, 'instructions' => 'Vacío = los mismos niveles que el resto del sitio.']),
                $t->number('min', 'Mínimo de encabezados para mostrarla', ['default_value' => TocResolver::DEFAULT_MIN, 'min' => 1, 'step' => 1]),
                $t->trueFalse('numbered', 'Numerada'),
                $t->trueFalse('collapsed_mobile', 'Cerrada por defecto en móvil', ['default_value' => 1]),
                $t->trueFalse('sticky', 'Fija en la columna lateral (escritorio)', ['default_value' => 1]),
            ]),

            $f->tab('tab_deploy', 'Despliegue'),
            $f->group('deploy', 'Despliegue del sitio público', static fn (FieldBuilder $d): array => [
                $d->url('hook_url', 'Deploy hook (Cloudflare Workers Builds)', ['instructions' => 'Solo administradores. Si BP_DEPLOY_HOOK_URL está en wp-config.php, esa constante manda.']),
                $d->trueFalse('daily_rebuild', 'Rebuild diario', [
                    'default_value' => 1,
                    'instructions' => 'Reconstruye el sitio una vez al día aunque nadie edite nada: así el JSON-LD refleja las valoraciones nuevas. Los cambios de contenido se publican en ~1 minuto y las reseñas en ≤ 15 minutos.',
                ]),
                $d->field('time_picker', 'daily_rebuild_time', 'Hora del rebuild diario', [
                    'display_format' => 'H:i',
                    'return_format' => 'H:i',
                    'default_value' => Config::DEFAULT_DAILY_REBUILD_TIME.':00',
                    'instructions' => 'Hora de Colombia (zona horaria del sitio).',
                    'conditional_logic' => [[['field' => $d->key('daily_rebuild'), 'operator' => '==', 'value' => '1']]],
                ]),
            ]),
        ], ['style' => 'seamless']);
    }

    /**
     * «Textos globales» (/site → microcopy): CTAs and conversion texts repeated across the site.
     *
     * @return list<array<string, mixed>>
     */
    private static function microcopyFields(FieldBuilder $m): array
    {
        $cta = static fn (FieldBuilder $c): array => [
            $c->text('title', 'Título'),
            $c->textarea('text', 'Texto', ['rows' => 2]),
            $c->text('label', 'Texto del botón'),
        ];

        return [
            $m->group('cta_banner', 'CTA al final de las páginas comerciales', $cta),
            $m->text('aside_title', 'Tarjeta lateral de cotización: título'),
            $m->repeater('aside_bullets', 'Tarjeta lateral de cotización: viñetas', static fn (FieldBuilder $b): array => [
                $b->text('text', 'Viñeta'),
            ], ['max' => 5, 'button_label' => 'Añadir viñeta']),
            $m->text('aside_label', 'Tarjeta lateral de cotización: botón'),
            $m->text('mega_footer', 'Franja inferior del mega-menú'),
            $m->group('blog_cta', 'CTA de las guías del blog', $cta),
            $m->group('equipo_cta', 'CTA de las fichas de equipos', $cta, ['instructions' => 'Admite {equipo}: el nombre del equipo en minúsculas.']),
        ];
    }

    /**
     * «Valoraciones» tab: global switch, defaults per content type, JSON-LD threshold, moderation and UI texts.
     *
     * @return list<array<string, mixed>>
     */
    private static function ratingsFields(FieldBuilder $r): array
    {
        $excluded = implode(', ', RatingPolicy::EXCLUDED_TEMPLATES);

        return [
            $r->trueFalse('enabled', 'Valoraciones activas', [
                'default_value' => 1,
                'instructions' => 'Interruptor general. Apagado, ninguna página muestra estrellas ni opiniones.',
            ]),
            $r->group('types', 'Por tipo de contenido', static function (FieldBuilder $t): array {
                $fields = [];
                foreach (RatingSettings::TYPES as $type => $label) {
                    $default = RatingSettings::DEFAULT_TYPES[$type];
                    $fields[] = $t->group($type, $label, static fn (FieldBuilder $g): array => [
                        $g->trueFalse('stars', 'Estrellas', ['default_value' => (int) $default['stars']]),
                        $g->trueFalse('reviews', 'Opiniones con texto', ['default_value' => (int) $default['reviews']]),
                    ], ['layout' => 'table']);
                }

                return $fields;
            }, ['instructions' => "Cada página puede cambiarlo en su caja «Valoraciones». Las plantillas {$excluded} no tienen valoraciones salvo que la página las active (Google no muestra estrellas del propio negocio)."]),
            $r->number('min_count_for_schema', 'Mínimo de valoraciones para el JSON-LD', [
                'default_value' => RatingSettings::DEFAULT_MIN_COUNT_FOR_SCHEMA,
                'min' => 1,
                'step' => 1,
                'instructions' => 'El sitio publica AggregateRating desde este número de valoraciones aprobadas.',
            ]),
            $r->trueFalse('auto_approve_reviews', 'Aprobar opiniones automáticamente', [
                'default_value' => 0,
                'instructions' => 'Apagado (recomendado): cada opinión con texto espera moderación en «Reseñas». Los votos con estrellas se aprueban solos.',
            ]),
            $r->group('texts', 'Textos de la interfaz', static fn (FieldBuilder $x): array => [
                $x->text('stars_title', 'Título del bloque de estrellas (general)', ['default_value' => RatingSettings::DEFAULT_TEXTS['starsTitle']]),
                $x->text('stars_title_servicios', 'Título en servicios', ['default_value' => RatingSettings::DEFAULT_TEXTS['starsTitleServicios']]),
                $x->text('stars_title_ciudades', 'Título en ciudades', ['default_value' => RatingSettings::DEFAULT_TEXTS['starsTitleCiudades']]),
                $x->text('stars_title_equipos', 'Título en equipos', ['default_value' => RatingSettings::DEFAULT_TEXTS['starsTitleEquipos']]),
                $x->text('stars_title_blog', 'Título en guías del blog', ['default_value' => RatingSettings::DEFAULT_TEXTS['starsTitleBlog']]),
                $x->text('stars_help', 'Ayuda bajo las estrellas', ['default_value' => RatingSettings::DEFAULT_TEXTS['starsHelp']]),
                $x->text('first_vote', 'Texto sin votos todavía', ['default_value' => RatingSettings::DEFAULT_TEXTS['firstVote']]),
                $x->text('thanks', 'Mensaje después de votar', ['default_value' => RatingSettings::DEFAULT_TEXTS['thanks']]),
                $x->text('reviews_title', 'Título de las opiniones', ['default_value' => RatingSettings::DEFAULT_TEXTS['reviewsTitle']]),
                $x->text('reviews_empty', 'Texto cuando aún no hay opiniones', ['default_value' => RatingSettings::DEFAULT_TEXTS['reviewsEmpty']]),
                $x->text('form_title', 'Título del formulario', ['default_value' => RatingSettings::DEFAULT_TEXTS['formTitle']]),
                $x->textarea('consent', 'Texto del consentimiento', ['default_value' => RatingSettings::DEFAULT_TEXTS['consent'], 'rows' => 2]),
                $x->text('pending', 'Mensaje después de enviar una opinión', ['default_value' => RatingSettings::DEFAULT_TEXTS['pending']]),
            ]),
        ];
    }
}
