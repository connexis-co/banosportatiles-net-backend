<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Normalizer;

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Content\Taxonomies;
use BanosPortatiles\Headless\Fields\FieldReader;
use BanosPortatiles\Headless\Reviews\RatingService;
use BanosPortatiles\Headless\Routing\UriResolver;
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
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function normalize(): array
    {
        $brand = $this->option('brand');
        $contact = $this->option('contact');
        $legal = $this->option('legal');
        $analytics = $this->option('analytics');
        $forms = $this->option('forms');
        $menus = $this->option('menus');
        $logoId = Arr::ids($brand['logo'] ?? null)[0] ?? 0;

        $site = [
            'brand' => [
                'name' => Arr::string($brand, 'name') ?: (string) get_bloginfo('name'),
                'tagline' => Arr::string($brand, 'tagline'),
            ] + Arr::withoutEmpty(['logo' => $logoId > 0 ? $this->refs->image($logoId) : null]),
            'contact' => self::pick($contact, ['whatsapp', 'phone', 'email', 'horario']),
            'social' => $this->social(),
            'legal' => self::pick($legal, ['responsable', 'razon_social', 'nit', 'direccion', 'ciudad', 'email_datos']),
            'analytics' => self::pick($analytics, ['ga4', 'gtm']),
            'forms' => self::pick($forms, ['turnstile_site_key']),
            'menus' => [
                'header' => self::headerMenu(Arr::rows($menus, 'header')),
                'footer' => self::footerMenu(Arr::rows($menus, 'footer')),
            ],
            'ciudades' => $this->ciudades(),
            'categorias' => $this->categorias(),
            'ratings' => $this->ratings->settings()->toSite($this->ratings->available()),
        ];

        // Single source of truth: the «Sitio en venta» plugin (bp-sitio-en-venta). Omitted when it is not active.
        $banner = apply_filters('bp_headless/sale_banner', null);
        if (is_array($banner)) {
            $site = array_slice($site, 0, 7, true) + ['sale_banner' => $banner] + array_slice($site, 7, null, true);
        }

        return $site;
    }

    /**
     * @param  list<array<array-key, mixed>>  $rows
     * @return list<array{label: string, href: string, description?: string, children: list<array{label: string, href: string, description?: string}>}>
     */
    public static function headerMenu(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $link = self::menuLink($row);
            if ($link !== null) {
                $children = array_values(array_filter(array_map(self::menuLink(...), Arr::rows($row, 'children'))));
                $items[] = $link + ['children' => $children];
            }
        }

        return $items;
    }

    /**
     * Enlace del menú principal: texto, destino y, si la hay, la descripción corta del mega-menú.
     *
     * @return array{label: string, href: string, description?: string}|null
     */
    private static function menuLink(mixed $row): ?array
    {
        $link = SectionsNormalizer::link($row);
        if ($link === null || ! is_array($row)) {
            return $link;
        }
        $description = Arr::string($row, 'description');

        return $description !== '' ? $link + ['description' => $description] : $link;
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
