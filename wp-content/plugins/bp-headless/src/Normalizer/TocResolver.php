<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Normalizer;

use BanosPortatiles\Headless\Support\Arr;

/**
 * Table of contents (pure). «Ajustes del sitio → Tabla de contenidos» (SCF group "toc") gives the defaults and
 * /site → toc; the per-post group "toc" overrides them. The Node carries the RESOLVED configuration: the front
 * builds the list from the H2/H3 of the page (contract §3.1 and §3.2).
 *
 *   node.toc = {enabled, title, depth: 2|3, min, numbered, collapsedMobile, sticky, exclude: string[], labels: {id: label}}
 */
final class TocResolver
{
    /** Template slugs (PageTemplates::slugFor) that can show a TOC. */
    public const TYPES = [
        'hub-servicio' => 'Hubs de servicio',
        'servicio' => 'Servicios',
        'ciudad' => 'Ciudades',
        'post' => 'Entradas del blog',
        'legal' => 'Páginas legales',
        'landing' => 'Landings',
        'default' => 'Páginas sin plantilla',
        'equipo' => 'Fichas de equipo',
        'equipos' => 'Listado de equipos',
        'home' => 'Inicio',
        'blog-index' => 'Índice del blog',
        'contacto' => 'Contacto',
        'cotizar' => 'Cotizar',
        'venta-sitio' => 'Sitio en venta',
    ];

    public const DEFAULT_TYPES = ['hub-servicio', 'servicio', 'ciudad', 'post', 'legal'];

    public const DEFAULT_TITLE = 'En esta página';

    public const DEFAULT_TITLE_BLOG = 'Contenido de la guía';

    public const DEFAULT_DEPTH = 2;

    public const DEFAULT_MIN = 3;

    public const MODES = ['inherit' => 'Heredar de Ajustes del sitio', 'show' => 'Mostrar', 'hide' => 'Ocultar'];

    /**
     * /site → toc.
     *
     * @return array{enabled_types: list<string>, title: string, titleBlog: string, depth: int, min: int, numbered: bool, collapsedMobile: bool, sticky: bool}
     */
    public static function site(mixed $global): array
    {
        $global = is_array($global) ? $global : [];
        // Never saved → defaults; saved with every box unchecked (SCF stores "") → none.
        $types = array_key_exists('enabled_types', $global)
            ? array_values(array_intersect(Arr::strings($global, 'enabled_types'), array_keys(self::TYPES)))
            : self::DEFAULT_TYPES;
        $min = Arr::int($global, 'min', self::DEFAULT_MIN);

        return [
            'enabled_types' => $types,
            'title' => Arr::string($global, 'title') ?: self::DEFAULT_TITLE,
            'titleBlog' => Arr::string($global, 'title_blog') ?: self::DEFAULT_TITLE_BLOG,
            'depth' => self::depth($global['depth'] ?? null) ?? self::DEFAULT_DEPTH,
            'min' => $min >= 1 ? $min : self::DEFAULT_MIN,
            'numbered' => Arr::bool($global, 'numbered'),
            'collapsedMobile' => Arr::bool($global, 'collapsed_mobile', true),
            'sticky' => Arr::bool($global, 'sticky', true),
        ];
    }

    /**
     * Node → toc, resolved for its template ("post" uses the blog title).
     *
     * @return array<string, mixed>
     */
    public static function resolve(mixed $global, mixed $local, string $template): array
    {
        $site = self::site($global);
        $local = is_array($local) ? $local : [];

        $enabled = match (Arr::string($local, 'mode')) {
            'show' => true,
            'hide' => false,
            default => in_array($template, $site['enabled_types'], true),
        };

        return [
            'enabled' => $enabled,
            'title' => Arr::string($local, 'title') ?: ($template === 'post' ? $site['titleBlog'] : $site['title']),
            'depth' => self::depth($local['depth'] ?? null) ?? $site['depth'],
            'min' => $site['min'],
            'numbered' => $site['numbered'],
            'collapsedMobile' => $site['collapsedMobile'],
            'sticky' => $site['sticky'],
            'exclude' => array_values(array_unique(Arr::lines(Arr::string($local, 'exclude')))),
            'labels' => self::labels(Arr::rows($local, 'labels')),
        ];
    }

    /** 2 or 3 (from int or string); anything else (e.g. "inherit") → null. */
    public static function depth(mixed $value): ?int
    {
        $depth = is_numeric($value) ? (int) $value : 0;

        return in_array($depth, [2, 3], true) ? $depth : null;
    }

    /**
     * Repeater rows {id, label} → {id: label} (a JSON object even when empty). "#id" is accepted.
     *
     * @param  list<array<array-key, mixed>>  $rows
     * @return array<string, string>|\stdClass
     */
    public static function labels(array $rows): array|\stdClass
    {
        $labels = [];
        foreach ($rows as $row) {
            $id = ltrim(Arr::string($row, 'id'), '#');
            $label = Arr::string($row, 'label');
            if ($id !== '' && $label !== '') {
                $labels[$id] = $label;
            }
        }

        return $labels === [] ? new \stdClass : $labels;
    }
}
