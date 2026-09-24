<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Normalizer;

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Content\Taxonomies;
use BanosPortatiles\Headless\Fields\FieldReader;
use BanosPortatiles\Headless\Fields\SiteSettings;
use BanosPortatiles\Headless\Reviews\RatingService;
use BanosPortatiles\Headless\Routing\UriResolver;
use BanosPortatiles\Headless\Seo\SiteSeo;
use BanosPortatiles\Headless\Support\Arr;

/**
 * "Ajustes del sitio" + ciudades + categorías → GET /bp/v1/site. Keys mirror seed/site.yaml.
 */
final class SiteNormalizer
{
    public function __construct(
        private readonly FieldReader $fields,
        private readonly ReferenceResolver $refs,
        private readonly UriResolver $uris,
        private readonly CiudadNormalizer $ciudades,
        private readonly RatingService $ratings,
        private readonly SiteSeo $seo,
        private readonly LogoNormalizer $logos,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function normalize(): array
    {
        $brand = $this->option('brand');
        $menus = $this->option('menus');
        $brandName = Arr::string($brand, 'name') ?: html_entity_decode((string) get_bloginfo('name'), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $site = [
            'brand' => ['name' => $brandName, 'tagline' => Arr::string($brand, 'tagline')] + Arr::withoutEmpty([
                'logo' => $this->logos->normalize($brand['logo'] ?? null),
                'logo_dark' => $this->logos->normalize($brand['logo_dark'] ?? null),
            ]),
            'header' => self::header($this->option('header')),
            'contact' => self::pick($this->option('contact'), ['whatsapp', 'phone', 'email', 'horario']),
            'social' => $this->social(),
            'legal' => self::pick($this->option('legal'), ['responsable', 'razon_social', 'nit', 'telefono', 'direccion', 'ciudad', 'email_datos']),
            'analytics' => self::pick($this->option('analytics'), ['ga4', 'gtm']),
            'forms' => self::pick($this->option('forms'), ['turnstile_site_key']),
        ];

        // Single source of truth: the «Sitio en venta» plugin (bp-sitio-en-venta). Omitted when it is not active.
        $banner = apply_filters('bp_headless/sale_banner', null);
        if (is_array($banner)) {
            $site['sale_banner'] = $banner;
        }

        return $site + [
            'menus' => [
                'header' => self::headerMenu(Arr::rows($menus, 'header')),
                'secondary' => self::secondaryMenu(Arr::rows($menus, 'secondary')),
                'footer' => self::footerMenu(Arr::rows($menus, 'footer')),
            ],
            'ciudades' => $this->ciudades(),
            'categorias' => $this->categorias(),
            'ratings' => $this->ratings->settings()->toSite($this->ratings->available()),
            'toc' => TocResolver::site($this->fields->get('toc', Config::OPTIONS_ID)),
            'microcopy' => self::microcopy($this->option('microcopy')),
            'seo' => $this->seo->toSite($brandName),
        ];
    }

    /**
     * Fixed button of the header: never empty (defaults of SiteSettings::HEADER_DEFAULTS).
     *
     * @param  array<array-key, mixed>  $header
     * @return array{cta_label: string, cta_short: string, cta_href: string}
     */
    public static function header(array $header): array
    {
        $out = SiteSettings::HEADER_DEFAULTS;
        foreach ($out as $key => $default) {
            $out[$key] = Arr::string($header, $key) ?: $default;
        }

        return $out;
    }

    /**
     * Main menu: {label, href, description?, icon?, kind, children: [{label, href, description?, icon?, group?}]}.
     * A top-level item needs a label (its href may be empty when it only opens a submenu); a child needs both.
     *
     * @param  list<array<array-key, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function headerMenu(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $label = Arr::string($row, 'label');
            if ($label === '') {
                continue;
            }
            $kind = Arr::string($row, 'kind');
            $items[] = ['label' => $label, 'href' => Arr::string($row, 'href')]
                + Arr::withoutEmpty(['description' => Arr::string($row, 'description'), 'icon' => Arr::string($row, 'icon')])
                + [
                    'kind' => isset(SiteSettings::MENU_KINDS[$kind]) ? $kind : 'links',
                    'children' => array_values(array_filter(array_map(
                        static fn (array $child): ?array => self::menuLink($child, ['description', 'icon', 'group']),
                        Arr::rows($row, 'children')
                    ))),
                ];
        }

        return $items;
    }

    /**
     * Utility links (Nosotros, FAQ, Contacto): [{label, href, icon?}].
     *
     * @param  list<array<array-key, mixed>>  $rows
     * @return list<array<string, string>>
     */
    public static function secondaryMenu(array $rows): array
    {
        return array_values(array_filter(array_map(static fn (array $row): ?array => self::menuLink($row, ['icon']), $rows)));
    }

    /**
     * «Textos globales». Empty texts are omitted so the front keeps its defaults (same Zod schema as the seed).
     *
     * @param  array<array-key, mixed>  $microcopy
     * @return array<string, mixed>|\stdClass
     */
    public static function microcopy(array $microcopy): array|\stdClass
    {
        $cta = static function (mixed $group): ?array {
            $values = Arr::withoutEmpty([
                'title' => Arr::string(is_array($group) ? $group : [], 'title'),
                'text' => Arr::string(is_array($group) ? $group : [], 'text'),
                'label' => Arr::string(is_array($group) ? $group : [], 'label'),
            ]);

            return $values !== [] ? $values : null;
        };
        $bullets = Arr::strings($microcopy, 'aside_bullets');

        $out = array_filter([
            'cta_banner' => $cta($microcopy['cta_banner'] ?? null),
            'aside_title' => Arr::string($microcopy, 'aside_title') ?: null,
            'aside_bullets' => $bullets !== [] ? $bullets : null,
            'aside_label' => Arr::string($microcopy, 'aside_label') ?: null,
            'mega_footer' => Arr::string($microcopy, 'mega_footer') ?: null,
            'blog_cta' => $cta($microcopy['blog_cta'] ?? null),
            'equipo_cta' => $cta($microcopy['equipo_cta'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);

        return $out === [] ? new \stdClass : $out;
    }

    /**
     * Link with label and href (both required) plus the optional keys that have a value.
     *
     * @param  array<array-key, mixed>  $row
     * @param  list<string>  $optional
     * @return array<string, string>|null
     */
    private static function menuLink(array $row, array $optional): ?array
    {
        $link = SectionsNormalizer::link($row);
        if ($link === null) {
            return null;
        }
        $extra = [];
        foreach ($optional as $key) {
            $extra[$key] = Arr::string($row, $key);
        }

        return $link + Arr::withoutEmpty($extra);
    }

    /**
     * @param  list<array<array-key, mixed>>  $rows
     * @return list<array{title: string, links: list<array{label: string, href: string}>}>
     */
    public static function footerMenu(array $rows): array
    {
        $columns = [];
        foreach ($rows as $row) {
            $links = self::links(Arr::rows($row, 'links'));
            if (Arr::string($row, 'title') !== '' || $links !== []) {
                $columns[] = ['title' => Arr::string($row, 'title'), 'links' => $links];
            }
        }

        return $columns;
    }

    /**
     * @param  list<array<array-key, mixed>>  $rows
     * @return list<array{label: string, href: string}>
     */
    private static function links(array $rows): array
    {
        return array_values(array_filter(array_map(SectionsNormalizer::link(...), $rows)));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private static function pick(array $data, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = Arr::string($data, $key);
        }

        return $out;
    }

    /** @return array<array-key, mixed> */
    private function option(string $name): array
    {
        $value = $this->fields->get($name, Config::OPTIONS_ID);

        return is_array($value) ? $value : [];
    }

    /** @return list<array{network: string, url: string}> */
    private function social(): array
    {
        $out = [];
        foreach (Arr::rows(['s' => $this->fields->get('social', Config::OPTIONS_ID)], 's') as $row) {
            if (Arr::string($row, 'url') !== '') {
                $out[] = ['network' => Arr::string($row, 'network'), 'url' => Arr::string($row, 'url')];
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function ciudades(): array
    {
        $terms = get_terms(['taxonomy' => Taxonomies::CIUDAD, 'hide_empty' => false, 'orderby' => 'name']);
        if (! is_array($terms)) {
            return [];
        }

        $out = [];
        foreach ($terms as $term) {
            if ($term instanceof \WP_Term) {
                $out[] = $this->ciudades->full($term);
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function categorias(): array
    {
        $terms = get_terms(['taxonomy' => 'category', 'hide_empty' => false, 'orderby' => 'name']);
        if (! is_array($terms)) {
            return [];
        }

        $default = (int) get_option('default_category');
        $out = [];
        foreach ($terms as $term) {
            if (! $term instanceof \WP_Term || ($term->term_id === $default && $term->count === 0)) {
                continue;
            }
            $pillarId = Arr::ids($this->fields->get('pillar', 'term_'.$term->term_id))[0] ?? 0;
            $out[] = [
                'slug' => $term->slug,
                'name' => html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'description' => trim($term->description),
                'uri' => (string) $this->uris->forTerm($term),
                'count' => $term->count,
            ] + Arr::withoutEmpty([
                'pillar' => $pillarId > 0 ? $this->refs->slug($pillarId) : null,
                'pillarUri' => $pillarId > 0 ? $this->refs->uri($pillarId) : null,
            ]);
        }

        return $out;
    }
}
