<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Import;

use BanosPortatiles\Headless\Normalizer\PriceNormalizer;
use BanosPortatiles\Headless\Normalizer\TocResolver;
use BanosPortatiles\Headless\Routing\UriResolver;
use BanosPortatiles\Headless\Support\Arr;

/**
 * Seed frontmatter → SCF values (keyed by field name; relations resolved to IDs through SeedLookup).
 * It is the exact inverse of the API normalizers, which keeps "seed → WordPress → API" lossless.
 */
final class FieldValueMapper
{
    /** @var \Closure(string): void */
    private \Closure $warn;

    /**
     * @param  (callable(string): void)|null  $warn
     */
    public function __construct(private readonly SeedLookup $lookup, ?callable $warn = null)
    {
        $this->warn = $warn !== null ? \Closure::fromCallable($warn) : static function (string $message): void {};
    }

    /**
     * @param  array<array-key, mixed>  $seo
     * @return array<string, mixed>
     */
    public function seo(array $seo): array
    {
        $ogImage = $seo['og_image'] ?? ($seo['ogImage'] ?? null);

        return [
            'title' => Arr::string($seo, 'title'),
            'description' => Arr::string($seo, 'description'),
            'keyword' => Arr::string($seo, 'keyword'),
            'keywords_secundarias' => implode("\n", Arr::strings($seo, 'keywords_secundarias')),
            'intencion' => Arr::string($seo, 'intencion'),
            'canonical' => Arr::string($seo, 'canonical'),
            'noindex' => Arr::bool($seo, 'noindex') ? 1 : 0,
            'og_image' => $ogImage !== null ? ($this->lookup->imageId($ogImage) ?? '') : '',
        ];
    }

    /**
     * @param  array<array-key, mixed>  $hero
     * @return array<string, mixed>
     */
    public function hero(array $hero): array
    {
        $image = $hero['image'] ?? null;

        return [
            'eyebrow' => Arr::string($hero, 'eyebrow'),
            'h1' => Arr::string($hero, 'h1'),
            'lead' => Arr::string($hero, 'lead'),
            'bullets' => self::textRows(Arr::strings($hero, 'bullets')),
            'image' => $image !== null ? ($this->lookup->imageId($image) ?? '') : '',
            'cta_primario' => self::link(Arr::array($hero, 'cta_primario')),
            'cta_secundario' => self::link(Arr::array($hero, 'cta_secundario')),
            'mostrar_formulario' => Arr::bool($hero, 'mostrar_formulario') ? 1 : 0,
        ];
    }

    /**
     * @param  list<array<array-key, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    public function sections(array $sections): array
    {
        $rows = [];
        foreach ($sections as $index => $section) {
            $layout = Arr::string($section, 'layout');
            $fields = $this->section($layout, $section);
            if ($fields === null) {
                ($this->warn)(sprintf('Sección %d: layout desconocido «%s» (omitida).', $index, $layout));

                continue;
            }
            $rows[] = ['acf_fc_layout' => $layout] + $fields;
        }

        return $rows;
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @return list<array{q: string, a: string}>
     */
    public function faqs(array $item): array
    {
        $faqs = [];
        foreach (Arr::rows($item, 'faqs') as $faq) {
            if (Arr::string($faq, 'q') !== '' && Arr::string($faq, 'a') !== '') {
                $faqs[] = ['q' => Arr::string($faq, 'q'), 'a' => Arr::string($faq, 'a')];
            }
        }

        return $faqs;
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @return list<int>
     */
    public function faqRefs(array $item): array
    {
        return $this->ids(Arr::strings($item, 'faq_refs'), $this->lookup->faqId(...), 'FAQ');
    }

    /**
     * Blog fields that do not depend on other content (pass 1).
     *
     * @param  array<array-key, mixed>  $item
     * @return array<string, mixed>
     */
    public function blog(array $item): array
    {
        $sources = [];
        foreach (Arr::rows($item, 'sources') as $source) {
            $sources[] = ['title' => Arr::string($source, 'title'), 'url' => Arr::string($source, 'url')];
        }

        return [
            'pillar' => Arr::bool($item, 'pillar') ? 1 : 0,
            'author' => Arr::string($item, 'author'),
            'key_points' => self::textRows(Arr::strings($item, 'key_points')),
            'sources' => $sources,
        ];
    }

    /**
     * Blog relations (pass 2, once every post and page exists).
     *
     * @param  array<array-key, mixed>  $item
     * @return array<string, mixed>
     */
    public function blogRelations(array $item): array
    {
        $parent = Arr::string($item, 'pillar_parent');

        return [
            'pillar_parent' => $parent !== '' ? ($this->lookup->postId($parent) ?? $this->missing('pilar', $parent)) : '',
            'related_services' => $this->ids(Arr::strings($item, 'related_services'), $this->lookup->pageId(...), 'servicio'),
            'related_posts' => $this->ids(Arr::strings($item, 'related_posts'), $this->lookup->postId(...), 'post'),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @return array<string, mixed>
     */
    public function equipo(array $item): array
    {
        $specs = [];
        foreach (Arr::rows($item, 'specs') as $spec) {
            if (Arr::string($spec, 'k') !== '') {
                $specs[] = ['k' => Arr::string($spec, 'k'), 'v' => Arr::string($spec, 'v')];
            }
        }
        $gallery = [];
        foreach (Arr::array($item, 'gallery') as $image) {
            $id = $this->lookup->imageId($image);
            if ($id !== null) {
                $gallery[] = $id;
            }
        }

        return [
            'modalidad' => array_values(array_intersect(Arr::strings($item, 'modalidad'), ['alquiler', 'venta'])),
            'specs' => $specs,
            'usos' => self::textRows(Arr::strings($item, 'usos')),
            'gallery' => $gallery,
        ];
    }

    /**
     * Seed "price" (snake_case or camelCase keys) → SCF group "price"; null when the item has no price.
     * The seed carries no prices today: this only keeps the importer ready (and lossless) for when it does.
     *
     * @param  array<array-key, mixed>  $item
     * @return array<string, mixed>|null
     */
    public function price(array $item): ?array
    {
        if (! isset($item['price']) || ! is_array($item['price'])) {
            return null;
        }
        $price = $item['price'];
        $pick = static fn (string $snake, string $camel): string => Arr::string($price, $snake) ?: Arr::string($price, $camel);
        $unit = Arr::string($price, 'unit');
        if (is_array($price['unit'] ?? null)) {
            $code = Arr::string($price['unit'], 'code');
            $unit = $code === 'C62' && str_contains(Arr::string($price['unit'], 'label'), 'unidad') ? 'C62_unidad' : $code;
        }
        if ($unit !== '' && ! isset(PriceNormalizer::UNITS[$unit])) {
            ($this->warn)(sprintf('Precio: unidad desconocida «%s» (se omite).', $unit));
            $unit = '';
        }

        return [
            'show' => Arr::bool($price, 'show', true) ? 1 : 0,
            'mode' => Arr::string($price, 'mode') ?: 'from',
            'amount' => PriceNormalizer::amount($price['amount'] ?? null) ?? '',
            'min' => PriceNormalizer::amount($price['min'] ?? null) ?? '',
            'max' => PriceNormalizer::amount($price['max'] ?? null) ?? '',
            'unit' => $unit,
            'tax_included' => (array_key_exists('tax_included', $price) ? Arr::bool($price, 'tax_included') : Arr::bool($price, 'taxIncluded')) ? 1 : 0,
            'valid_until' => PriceNormalizer::date($pick('valid_until', 'validUntil')) ?? '',
            'updated' => PriceNormalizer::date(Arr::string($price, 'updated')) ?? '',
            'note' => Arr::string($price, 'note'),
            'availability' => isset(PriceNormalizer::AVAILABILITY[Arr::string($price, 'availability')]) ? Arr::string($price, 'availability') : 'InStock',
        ];
    }

    /**
     * Seed "toc" of a page/post/equipo → SCF group "toc"; null when absent (the importer keeps the editor's
     * settings). Accepts {mode: show|hide|inherit} or {enabled: bool}, title, depth, exclude[] and labels {id: label}.
     *
     * @param  array<array-key, mixed>  $item
     * @return array<string, mixed>|null
     */
    public function toc(array $item): ?array
    {
        if (! isset($item['toc']) || ! is_array($item['toc'])) {
            return null;
        }
        $toc = $item['toc'];
        $mode = Arr::string($toc, 'mode');
        if (! isset(TocResolver::MODES[$mode])) {
            $mode = array_key_exists('enabled', $toc) ? (Arr::bool($toc, 'enabled') ? 'show' : 'hide') : 'inherit';
        }
        $labels = [];
        foreach (Arr::array($toc, 'labels') as $id => $label) {
            $pair = is_array($label) ? [Arr::string($label, 'id'), Arr::string($label, 'label')] : [(string) $id, is_scalar($label) ? trim((string) $label) : ''];
            if ($pair[0] !== '' && $pair[1] !== '') {
                $labels[] = ['id' => ltrim($pair[0], '#'), 'label' => $pair[1]];
            }
        }
        $depth = TocResolver::depth($toc['depth'] ?? null);

        return [
            'mode' => $mode,
            'title' => Arr::string($toc, 'title'),
            'depth' => $depth !== null ? (string) $depth : 'inherit',
            'exclude' => implode("\n", Arr::strings($toc, 'exclude')),
            'labels' => $labels,
        ];
    }

    /**
     * Seed "schema_type" / "schemaType" → SCF value; null when absent (the importer then keeps the current value).
     *
     * @param  array<array-key, mixed>  $item
     */
    public function schemaType(array $item): ?string
    {
        $value = Arr::string($item, 'schema_type') ?: Arr::string($item, 'schemaType');

        return $value === '' ? null : PriceNormalizer::schemaType($value);
    }

    /**
     * @param  array<array-key, mixed>  $ciudad
     * @return array<string, mixed>
     */
    public function ciudad(array $ciudad): array
    {
        return [
            'departamento' => Arr::string($ciudad, 'departamento'),
            'autoridad_ambiental' => Arr::string($ciudad, 'autoridad_ambiental'),
            'lat' => Arr::float($ciudad, 'lat') ?? '',
            'lng' => Arr::float($ciudad, 'lng') ?? '',
            'cercanos' => implode("\n", Arr::strings($ciudad, 'cercanos')),
            'nota' => Arr::string($ciudad, 'nota'),
        ];
    }

    /**
     * site.yaml → options page values (only the groups present in the seed are returned).
     * sale_banner is not here: it belongs to the bp-sitio-en-venta plugin (see SeedImporter::importSite).
     *
     * @param  array<array-key, mixed>  $site
     * @return array<string, mixed>
     */
    public function site(array $site): array
    {
        $groups = [
            'brand' => ['name', 'tagline'],
            'contact' => ['whatsapp', 'phone', 'email', 'horario'],
            'legal' => ['responsable', 'razon_social', 'nit', 'direccion', 'ciudad', 'email_datos'],
            'analytics' => ['ga4', 'gtm'],
            'forms' => ['turnstile_site_key', 'leads_email', 'leads_webhook_url'],
        ];

        $values = [];
        foreach ($groups as $group => $keys) {
            if (! isset($site[$group]) || ! is_array($site[$group])) {
                continue;
            }
            foreach ($keys as $key) {
                if (array_key_exists($key, $site[$group])) {
                    $values[$group][$key] = Arr::string($site[$group], $key);
                }
            }
        }

        if (isset($site['brand']) && is_array($site['brand']) && ($site['brand']['logo'] ?? '') !== '') {
            $values['brand']['logo'] = $this->lookup->imageId($site['brand']['logo']) ?? '';
        }
        if (isset($site['social']) && is_array($site['social'])) {
            $values['social'] = array_map(static fn (array $row): array => [
                'network' => Arr::string($row, 'network'),
                'url' => Arr::string($row, 'url'),
            ], Arr::rows($site, 'social'));
        }
        if (isset($site['menus']) && is_array($site['menus'])) {
            $menus = $site['menus'];
            $values['menus'] = [
                'header' => array_map(static fn (array $item): array => self::menuLink($item) + [
                    'children' => array_map(self::menuLink(...), Arr::rows($item, 'children')),
                ], Arr::rows($menus, 'header')),
                'footer' => array_map(static fn (array $column): array => [
                    'title' => Arr::string($column, 'title'),
                    'links' => array_map(self::link(...), Arr::rows($column, 'links')),
                ], Arr::rows($menus, 'footer')),
            ];
        }

        return $values;
    }

    /**
     * @param  array<array-key, mixed>  $section
     * @return array<string, mixed>|null
     */
    private function section(string $layout, array $section): ?array
    {
        $title = ['title' => Arr::string($section, 'title')];
        $intro = ['intro' => Arr::string($section, 'intro')];

        return match ($layout) {
            'contenido' => [],
            'features_grid' => $title + $intro + ['items' => self::rows($section, ['icon', 'title', 'text'])],
            'steps' => $title + $intro + ['items' => self::rows($section, ['title', 'text'])],
            'pricing_factors' => $title + $intro + ['items' => self::rows($section, ['factor', 'detalle']), 'disclaimer' => Arr::string($section, 'disclaimer')],
            'comparison_table' => $title + $intro + [
                'columns' => array_map(static fn (string $label): array => ['label' => $label], Arr::strings($section, 'columns')),
                'rows' => array_map(
                    static fn (mixed $row): array => ['cells' => array_map(
                        static fn (mixed $cell): array => ['value' => is_scalar($cell) ? trim((string) $cell) : ''],
                        is_array($row) ? array_values($row) : []
                    )],
                    array_values(Arr::array($section, 'rows'))
                ),
                'note' => Arr::string($section, 'note'),
            ],
            'equipment_grid' => $title + $intro + ['items' => $this->ids(Arr::strings($section, 'items'), $this->lookup->equipoId(...), 'equipo')],
            'services_grid' => $title + $intro + ['items' => $this->ids(Arr::strings($section, 'items'), $this->lookup->pageId(...), 'servicio')],
            'coverage' => $title + $intro + ['items' => $this->ids(Arr::strings($section, 'items'), $this->lookup->ciudadId(...), 'ciudad')],
            'related_posts' => $title + $intro + ['items' => $this->ids(Arr::strings($section, 'items'), $this->lookup->postId(...), 'post')],
            'callout' => [
                'variant' => Arr::string($section, 'variant') ?: 'info',
                'title' => Arr::string($section, 'title'),
                'text' => Arr::string($section, 'text'),
                'source' => ['title' => Arr::string(Arr::array($section, 'source'), 'title'), 'url' => Arr::string(Arr::array($section, 'source'), 'url')],
            ],
            'cta_banner' => $title + ['text' => Arr::string($section, 'text'), 'cta' => self::link(Arr::array($section, 'cta'))],
            'gallery' => $title + $intro + ['items' => array_values(array_filter(array_map(
                $this->lookup->imageId(...),
                array_values(Arr::array($section, 'items'))
            )))],
            default => null,
        };
    }

    /**
     * @param  list<string>  $refs
     * @param  callable(string): ?int  $resolve
     * @return list<int>
     */
    private function ids(array $refs, callable $resolve, string $label): array
    {
        $ids = [];
        foreach ($refs as $ref) {
            $id = $resolve($label === 'servicio' ? UriResolver::normalize($ref) : $ref);
            if ($id === null) {
                $this->missing($label, $ref);

                continue;
            }
            $ids[] = $id;
        }

        return $ids;
    }

    private function missing(string $label, string $ref): string
    {
        ($this->warn)(sprintf('Referencia no encontrada (%s): «%s».', $label, $ref));

        return '';
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $keys
     * @return list<array<string, string>>
     */
    private static function rows(array $data, array $keys): array
    {
        $rows = [];
        foreach (Arr::rows($data, 'items') as $item) {
            $row = [];
            foreach ($keys as $key) {
                $row[$key] = Arr::string($item, $key);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  list<string>  $texts
     * @return list<array{text: string}>
     */
    private static function textRows(array $texts): array
    {
        return array_map(static fn (string $text): array => ['text' => $text], $texts);
    }

    /**
     * @param  array<array-key, mixed>  $link
     * @return array{label: string, href: string}
     */
    private static function link(array $link): array
    {
        return ['label' => Arr::string($link, 'label'), 'href' => Arr::string($link, 'href')];
    }

    /**
     * @param  array<array-key, mixed>  $link
     * @return array{label: string, href: string, description: string}
     */
    private static function menuLink(array $link): array
    {
        return self::link($link) + ['description' => Arr::string($link, 'description')];
    }
}
