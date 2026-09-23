<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Normalizer;

use BanosPortatiles\Headless\Support\Arr;

/**
 * SCF flexible content "sections" → [{layout, ...fields}] with the same keys and value types as the
 * seed contract (relations become slugs/URIs, images become Image objects).
 */
final class SectionsNormalizer
{
    public const LAYOUTS = [
        'features_grid', 'contenido', 'steps', 'pricing_factors', 'comparison_table', 'equipment_grid',
        'services_grid', 'coverage', 'callout', 'related_posts', 'cta_banner', 'gallery',
    ];

    public function __construct(private readonly ReferenceResolver $refs) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function normalize(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $sections = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $layout = Arr::string($row, 'acf_fc_layout');
            $fields = $this->fields($layout, $row);
            if ($fields !== null) {
                $sections[] = ['layout' => $layout] + $fields;
            }
        }

        return $sections;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function fields(string $layout, array $row): ?array
    {
        $head = Arr::withoutEmpty(['title' => Arr::string($row, 'title'), 'intro' => Arr::string($row, 'intro')]);

        return match ($layout) {
            'contenido' => [],
            'features_grid' => $head + ['items' => $this->items($row, ['icon', 'title', 'text'])],
            'steps' => $head + ['items' => $this->items($row, ['title', 'text'])],
            'pricing_factors' => $head + ['items' => $this->items($row, ['factor', 'detalle'])]
                + Arr::withoutEmpty(['disclaimer' => Arr::string($row, 'disclaimer')]),
            'comparison_table' => $head + ['columns' => Arr::strings($row, 'columns', 'label'), 'rows' => $this->tableRows($row)]
                + Arr::withoutEmpty(['note' => Arr::string($row, 'note')]),
            'equipment_grid', 'related_posts' => $head + ['items' => $this->resolve(Arr::ids($row['items'] ?? null), $this->refs->slug(...))],
            'services_grid' => $head + ['items' => $this->resolve(Arr::ids($row['items'] ?? null), $this->refs->uri(...))],
            'coverage' => $head + ['items' => $this->resolve(Arr::ids($row['items'] ?? null), $this->refs->termSlug(...))],
            'callout' => ['variant' => Arr::string($row, 'variant') ?: 'info'] + $head
                + Arr::withoutEmpty(['text' => Arr::string($row, 'text'), 'source' => $this->source(Arr::array($row, 'source'))]),
            'cta_banner' => $head + Arr::withoutEmpty(['text' => Arr::string($row, 'text'), 'cta' => self::link(Arr::array($row, 'cta'))]),
            'gallery' => $head + ['items' => $this->resolve(Arr::ids($row['items'] ?? null), $this->refs->image(...))],
            default => null,
        };
    }

    /** @return array{label: string, href: string}|null */
    public static function link(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $label = Arr::string($value, 'label');
        $href = Arr::string($value, 'href');

        return ($label !== '' && $href !== '') ? ['label' => $label, 'href' => $href] : null;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function items(array $row, array $keys): array
    {
        $items = [];
        foreach (Arr::rows($row, 'items') as $item) {
            $values = [];
            foreach ($keys as $key) {
                $values[$key] = Arr::string($item, $key);
            }
            $values = Arr::withoutEmpty($values);
            if ($values !== []) {
                $items[] = $values;
            }
        }

        return $items;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return list<list<string>>
     */
    private function tableRows(array $row): array
    {
        $rows = [];
        foreach (Arr::rows($row, 'rows') as $tableRow) {
            $cells = array_map(
                static fn (array $cell): string => Arr::string($cell, 'value'),
                Arr::rows($tableRow, 'cells')
            );
            if (array_filter($cells, static fn (string $c): bool => $c !== '') !== []) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    /**
     * @param  array<array-key, mixed>  $source
     * @return array{title: string, url: string}|null
     */
    private function source(array $source): ?array
    {
        $title = Arr::string($source, 'title');
        $url = Arr::string($source, 'url');

        return ($title !== '' || $url !== '') ? ['title' => $title, 'url' => $url] : null;
    }

    /**
     * @template T
     *
     * @param  list<int>  $ids
     * @param  callable(int): (T|null)  $resolver
     * @return list<T>
     */
    private function resolve(array $ids, callable $resolver): array
    {
        $out = [];
        foreach ($ids as $id) {
            $value = $resolver($id);
            if ($value !== null) {
                $out[] = $value;
            }
        }

        return $out;
    }
}
