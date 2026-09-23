<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Fields;

use BanosPortatiles\Headless\Content\PostTypes;
use BanosPortatiles\Headless\Content\Taxonomies;

/**
 * SCF field groups of the content model (plan §4.3). Field names mirror the seed contract
 * (docs/plans/contrato-contenido-seed.md) so the importer and the API map 1:1.
 */
final class FieldGroups
{
    public const CALLOUT_VARIANTS = ['info' => 'Información', 'normativa' => 'Normativa', 'alerta' => 'Alerta', 'consejo' => 'Consejo'];

    public const INTENTS = ['transaccional' => 'Transaccional', 'comercial' => 'Comercial', 'informacional' => 'Informacional', 'navegacional' => 'Navegacional'];

    public const MODALIDADES = ['alquiler' => 'Alquiler', 'venta' => 'Venta'];

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            self::hero(),
            self::sections(),
            self::faqs(),
            self::blog(),
            self::equipo(),
            self::seo(),
            self::ciudad(),
            self::category(),
            SiteSettings::group(),
        ];
    }

    /** @return array<string, mixed> */
    public static function seo(): array
    {
        $f = new FieldBuilder;

        return self::group('seo', 'SEO', self::postTypes(['page', 'post', PostTypes::EQUIPO]), [
            $f->group('seo', 'SEO', static fn (FieldBuilder $s): array => [
                $s->text('title', 'Título SEO', ['maxlength' => 70, 'instructions' => '≤ 60 caracteres, con la keyword principal. Vacío = título de la página.']),
                $s->textarea('description', 'Meta descripción', ['maxlength' => 170, 'instructions' => '140–155 caracteres, con keyword y llamado a la acción.']),
                $s->text('keyword', 'Keyword principal'),
                $s->textarea('keywords_secundarias', 'Keywords secundarias', ['instructions' => 'Una por línea (uso interno; no se publica).']),
                $s->select('intencion', 'Intención de búsqueda', self::INTENTS, ['allow_null' => 1]),
                $s->url('canonical', 'Canonical', ['instructions' => 'Solo si esta página NO es la canónica. URL absoluta del sitio público.']),
                $s->trueFalse('noindex', 'No indexar (noindex)'),
                $s->image('og_image', 'Imagen para redes (Open Graph)', ['instructions' => '1200 × 630 px.']),
            ]),
        ], ['menu_order' => 90]);
    }

    /** @return array<string, mixed> */
    public static function hero(): array
    {
        $f = new FieldBuilder;

        return self::group('hero', 'Hero', self::postTypes(['page']), [
            $f->group('hero', 'Hero', static fn (FieldBuilder $h): array => [
                $h->text('eyebrow', 'Antetítulo'),
                $h->text('h1', 'H1', ['instructions' => 'Incluye la keyword principal. Vacío = título de la página.']),
                $h->textarea('lead', 'Entradilla', ['instructions' => '1–2 frases de propuesta de valor.']),
                $h->repeater('bullets', 'Viñetas', static fn (FieldBuilder $b): array => [
                    $b->text('text', 'Texto'),
                ], ['max' => 6]),
                $h->image('image', 'Imagen'),
                self::cta($h, 'cta_primario', 'CTA primario'),
                self::cta($h, 'cta_secundario', 'CTA secundario'),
                $h->trueFalse('mostrar_formulario', 'Mostrar formulario de cotización en el hero'),
            ]),
        ], ['menu_order' => 0]);
    }

    /** @return array<string, mixed> */
    public static function sections(): array
    {
        $f = new FieldBuilder;
        $title = static fn (FieldBuilder $b): array => $b->text('title', 'Título (H2)');

        $layouts = [
            'features_grid' => ['label' => 'Grid de beneficios', 'fields' => static fn (FieldBuilder $l): array => [
                $title($l),
                $l->textarea('intro', 'Introducción'),
                $l->repeater('items', 'Elementos', static fn (FieldBuilder $i): array => [
                    $i->text('icon', 'Icono', ['instructions' => 'Nombre de lucide (truck, shield-check…).']),
                    $i->text('title', 'Título'),
                    $i->textarea('text', 'Texto'),
                ], ['layout' => 'block']),
            ]],
            'contenido' => ['label' => 'Contenido del editor', 'fields' => static fn (FieldBuilder $l): array => [
                $l->message('info', 'Contenido principal', 'Marca el lugar donde se muestra el contenido del editor de la página.'),
            ]],
            'steps' => ['label' => 'Pasos', 'fields' => static fn (FieldBuilder $l): array => [
                $title($l),
                $l->textarea('intro', 'Introducción'),
                $l->repeater('items', 'Pasos', static fn (FieldBuilder $i): array => [
                    $i->text('title', 'Título'),
                    $i->textarea('text', 'Texto'),
                ], ['layout' => 'block']),
            ]],
            'pricing_factors' => ['label' => 'Factores de precio', 'fields' => static fn (FieldBuilder $l): array => [
                $title($l),
                $l->textarea('intro', 'Introducción'),
                $l->repeater('items', 'Factores', static fn (FieldBuilder $i): array => [
                    $i->text('factor', 'Factor'),
                    $i->textarea('detalle', 'Detalle'),
                ]),
                $l->text('disclaimer', 'Aviso', ['instructions' => 'Fecha y fuente de los precios de referencia.']),
            ]],
            'comparison_table' => ['label' => 'Tabla comparativa', 'fields' => static fn (FieldBuilder $l): array => [
                $title($l),
                $l->textarea('intro', 'Introducción'),
                $l->repeater('columns', 'Columnas', static fn (FieldBuilder $c): array => [
                    $c->text('label', 'Encabezado'),
                ]),
                $l->repeater('rows', 'Filas', static fn (FieldBuilder $r): array => [
                    $r->repeater('cells', 'Celdas', static fn (FieldBuilder $c): array => [
                        $c->text('value', 'Valor'),
                    ]),
                ], ['layout' => 'block']),
                $l->textarea('note', 'Nota'),
            ]],
            'equipment_grid' => ['label' => 'Grid de equipos', 'fields' => static fn (FieldBuilder $l): array => [
                $title($l),
                $l->textarea('intro', 'Introducción'),
                $l->relationship('items', 'Equipos', [PostTypes::EQUIPO]),
            ]],
            'services_grid' => ['label' => 'Grid de servicios', 'fields' => static fn (FieldBuilder $l): array => [
                $title($l),
                $l->textarea('intro', 'Introducción'),
                $l->relationship('items', 'Páginas', ['page'], ['instructions' => 'Vacío = páginas hijas.']),
            ]],
            'coverage' => ['label' => 'Cobertura', 'fields' => static fn (FieldBuilder $l): array => [
                $title($l),
                $l->textarea('intro', 'Introducción'),
                $l->taxonomy('items', 'Ciudades', Taxonomies::CIUDAD, ['instructions' => 'Vacío = todas.']),
            ]],
            'callout' => ['label' => 'Destacado', 'fields' => static fn (FieldBuilder $l): array => [
                $l->select('variant', 'Variante', self::CALLOUT_VARIANTS, ['default_value' => 'info']),
                $title($l),
                $l->textarea('text', 'Texto (Markdown corto)', ['rows' => 4]),
                $l->group('source', 'Fuente', static fn (FieldBuilder $s): array => [
                    $s->text('title', 'Título de la fuente'),
                    $s->url('url', 'URL'),
                ]),
            ]],
            'related_posts' => ['label' => 'Guías relacionadas', 'fields' => static fn (FieldBuilder $l): array => [
                $title($l),
                $l->textarea('intro', 'Introducción'),
                $l->relationship('items', 'Posts', ['post']),
            ]],
            'cta_banner' => ['label' => 'Banner CTA', 'fields' => static fn (FieldBuilder $l): array => [
                $title($l),
                $l->textarea('text', 'Texto'),
                self::cta($l, 'cta', 'CTA'),
            ]],
            'gallery' => ['label' => 'Galería', 'fields' => static fn (FieldBuilder $l): array => [
                $title($l),
                $l->textarea('intro', 'Introducción'),
                $l->gallery('items', 'Imágenes'),
            ]],
        ];

        return self::group('sections', 'Secciones', self::postTypes(['page']), [
            $f->flexible('sections', 'Secciones', $layouts, ['instructions' => 'Bloques en orden. «Contenido del editor» marca dónde va el cuerpo.']),
        ], ['menu_order' => 1]);
    }

    /** @return array<string, mixed> */
    public static function faqs(): array
    {
        $f = new FieldBuilder;

        return self::group('faqs', 'Preguntas frecuentes', self::postTypes(['page', 'post', PostTypes::EQUIPO]), [
            $f->repeater('faqs', 'FAQs de esta página', static fn (FieldBuilder $q): array => [
                $q->text('q', 'Pregunta'),
                $q->textarea('a', 'Respuesta', ['rows' => 4, 'instructions' => '40–90 palabras.']),
            ], ['layout' => 'block', 'button_label' => 'Añadir pregunta']),
            $f->relationship('faq_refs', 'FAQs del banco', [PostTypes::FAQ], ['instructions' => 'Se muestran después de las preguntas propias.']),
        ], ['menu_order' => 2]);
    }

    /** @return array<string, mixed> */
    public static function blog(): array
    {
        $f = new FieldBuilder('blog');

        return self::group('blog', 'Blog', self::postTypes(['post']), [
            $f->trueFalse('pillar', 'Es pilar (superblog)'),
            $f->postObject('pillar_parent', 'Pilar padre', ['post'], ['instructions' => 'En posts satélite: el pilar de su cluster.']),
            $f->text('author', 'Autor', ['default_value' => 'Equipo editorial BañosPortátiles.net']),
            $f->repeater('key_points', 'Puntos clave («En resumen»)', static fn (FieldBuilder $k): array => [
                $k->text('text', 'Punto'),
            ], ['max' => 6]),
            $f->repeater('sources', 'Fuentes citadas', static fn (FieldBuilder $s): array => [
                $s->text('title', 'Título'),
                $s->url('url', 'URL'),
            ]),
            $f->relationship('related_services', 'Servicios relacionados', ['page']),
            $f->relationship('related_posts', 'Posts relacionados', ['post']),
        ], ['menu_order' => 0]);
    }

    /** @return array<string, mixed> */
    public static function equipo(): array
    {
        $f = new FieldBuilder('equipo');

        return self::group('equipo', 'Ficha del equipo', self::postTypes([PostTypes::EQUIPO]), [
            $f->checkbox('modalidad', 'Modalidad', self::MODALIDADES),
            $f->repeater('specs', 'Especificaciones', static fn (FieldBuilder $s): array => [
                $s->text('k', 'Característica'),
                $s->text('v', 'Valor', ['instructions' => '«Según fabricante» cuando varíe.']),
            ]),
            $f->repeater('usos', 'Usos', static fn (FieldBuilder $u): array => [
                $u->text('text', 'Uso'),
            ]),
            $f->gallery('gallery', 'Galería'),
            $f->file('ficha_pdf', 'Ficha técnica (PDF)', ['mime_types' => 'pdf']),
        ], ['menu_order' => 0]);
    }

    /** @return array<string, mixed> */
    public static function ciudad(): array
    {
        $f = new FieldBuilder('ciudad');

        return self::group('ciudad', 'Datos de la ciudad', [[['param' => 'taxonomy', 'operator' => '==', 'value' => Taxonomies::CIUDAD]]], [
            $f->text('departamento', 'Departamento'),
            $f->text('autoridad_ambiental', 'Autoridad ambiental', ['instructions' => 'CAR o autoridad ambiental urbana que regula vertimientos y residuos en la ciudad (p. ej. AMVA, DAGMA, CAR).']),
            $f->number('lat', 'Latitud', ['step' => 'any']),
            $f->number('lng', 'Longitud', ['step' => 'any']),
            $f->textarea('cercanos', 'Municipios cercanos', ['instructions' => 'Uno por línea.']),
            $f->textarea('nota', 'Nota de contexto', ['rows' => 4]),
        ]);
    }

    /** @return array<string, mixed> */
    public static function category(): array
    {
        $f = new FieldBuilder('category');

        return self::group('category', 'Cluster del blog', [[['param' => 'taxonomy', 'operator' => '==', 'value' => 'category']]], [
            $f->postObject('pillar', 'Post pilar del cluster', ['post']),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  list<list<array<string, string>>>  $location
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function group(string $key, string $title, array $location, array $fields, array $extra = []): array
    {
        return [
            'key' => 'group_bp_'.$key,
            'title' => $title,
            'fields' => $fields,
            'location' => $location,
            'position' => 'normal',
            'style' => 'default',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
            'active' => true,
            'show_in_rest' => 0,
        ] + $extra;
    }

    /**
     * @param  list<string>  $types
     * @return list<list<array<string, string>>>
     */
    public static function postTypes(array $types): array
    {
        return array_map(static fn (string $type): array => [['param' => 'post_type', 'operator' => '==', 'value' => $type]], $types);
    }

    /** @return array<string, mixed> */
    private static function cta(FieldBuilder $parent, string $name, string $label): array
    {
        return $parent->group($name, $label, static fn (FieldBuilder $c): array => [
            $c->text('label', 'Texto'),
            $c->text('href', 'Enlace', ['instructions' => 'Ruta interna (/cotizar/), URL externa o «whatsapp».']),
        ], ['layout' => 'row']);
    }
}
