<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Import\FieldValueMapper;
use BanosPortatiles\Headless\Normalizer\SectionsNormalizer;
use BanosPortatiles\Headless\Support\Arr;
use BanosPortatiles\Headless\Tests\Fakes\FakeReferenceResolver;
use BanosPortatiles\Headless\Tests\Fakes\FakeSeedLookup;

/**
 * seed frontmatter → FieldValueMapper (SCF values with IDs) → SectionsNormalizer → identical seed sections.
 */
it('round-trips every section of the sample bundle losslessly', function (): void {
    $lookup = new FakeSeedLookup(
        equipos: ['bano-portatil-estandar' => 7],
        pages: ['/alquiler-de-banos-portatiles/' => 11],
        posts: ['pozo-septico-guia' => 9],
        ciudades: ['medellin' => 2, 'cali' => 3],
    );
    $refs = new FakeReferenceResolver(
        slugs: [7 => 'bano-portatil-estandar', 9 => 'pozo-septico-guia'],
        uris: [11 => '/alquiler-de-banos-portatiles/'],
        terms: [2 => 'medellin', 3 => 'cali'],
        images: [900 => ['src' => 'https://cms/x.webp', 'width' => 1200, 'height' => 630, 'alt' => '']],
    );
    $warnings = [];
    $mapper = new FieldValueMapper($lookup, function (string $w) use (&$warnings): void {
        $warnings[] = $w;
    });
    $normalizer = new SectionsNormalizer($refs);

    $checked = 0;
    foreach (sampleBundle()['pages'] as $page) {
        $seed = array_values(array_filter($page['sections'] ?? [], fn (array $s): bool => $s['layout'] !== 'gallery'));
        // Simulates SCF storage: values are returned as saved (return_format "id").
        $stored = $mapper->sections($seed);
        $api = $normalizer->normalize($stored);

        $expected = array_map(static function (array $section): array {
            if (in_array($section['layout'], ['services_grid', 'equipment_grid', 'coverage', 'related_posts'], true)) {
                $section['items'] ??= [];
            }

            return Arr::withoutEmpty($section);
        }, $seed);

        expect($api)->toBe($expected);
        $checked += count($seed);
    }

    expect($checked)->toBeGreaterThan(10)->and($warnings)->toBe([]);
});

it('maps gallery images to attachment IDs and reports unknown references', function (): void {
    $warnings = [];
    $mapper = new FieldValueMapper(new FakeSeedLookup, function (string $w) use (&$warnings): void {
        $warnings[] = $w;
    });

    $rows = $mapper->sections([
        ['layout' => 'gallery', 'title' => 'Fotos', 'items' => [['src' => 'a.webp', 'alt' => 'A'], ['src' => '']]],
        ['layout' => 'equipment_grid', 'items' => ['no-existe']],
        ['layout' => 'inventado'],
    ]);

    expect($rows)->toBe([
        ['acf_fc_layout' => 'gallery', 'title' => 'Fotos', 'items' => [900]],
        ['acf_fc_layout' => 'equipment_grid', 'title' => '', 'items' => []],
    ])->and($warnings)->toHaveCount(2);
});

it('maps site.yaml to the options page groups', function (): void {
    $values = (new FieldValueMapper(new FakeSeedLookup))->site(sampleBundle()['site']);

    expect($values)->toHaveKeys(['brand', 'contact', 'legal', 'analytics', 'menus'])
        ->not->toHaveKey('sale_banner') // lives in the bp-sitio-en-venta plugin
        ->and($values['legal'])->toHaveKeys(['razon_social', 'nit', 'direccion'])
        ->and($values['menus']['header'][0]['children'])->toHaveCount(2);
});
