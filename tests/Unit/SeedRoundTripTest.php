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
        ['acf_fc_layout' => 'gallery', 'title' => 'Fotos', 'anchor' => '', 'intro' => '', 'items' => [900]],
        ['acf_fc_layout' => 'equipment_grid', 'title' => '', 'anchor' => '', 'intro' => '', 'items' => []],
    ])->and($warnings)->toHaveCount(2);
});

it('round-trips section images, item images, anchors, rich_text and the faq marker', function (): void {
    $mapper = new FieldValueMapper(new FakeSeedLookup);
    $refs = new FakeReferenceResolver(images: [900 => ['src' => 'https://cms/x.webp', 'width' => 1200, 'height' => 800, 'alt' => 'Foto']]);
    $photo = ['src' => 'images/generated/pasos.jpg', 'alt' => 'Foto'];

    $api = (new SectionsNormalizer($refs))->normalize($mapper->sections([
        ['layout' => 'steps', 'title' => 'Cómo funciona', 'anchor' => '#Cómo funciona', 'image' => $photo, 'items' => [
            ['title' => 'Cotiza', 'text' => 'Cuéntanos', 'image' => $photo],
            ['title' => 'Instalamos', 'text' => ''],
        ]],
        ['layout' => 'features_grid', 'title' => 'Beneficios', 'items' => [['icon' => 'truck', 'title' => 'Logística', 'image' => $photo]]],
        ['layout' => 'rich_text', 'title' => 'Qué incluye', 'text' => 'Texto **Markdown**.', 'image' => $photo, 'image_position' => 'izquierda'],
        ['layout' => 'rich_text', 'title' => 'Sin foto', 'text' => 'Solo texto.', 'image_position' => 'arriba'],
        ['layout' => 'pricing_factors', 'title' => 'Precio', 'image' => $photo, 'items' => [['factor' => 'Días', 'detalle' => 'Duración']]],
        ['layout' => 'cta_banner', 'title' => 'Cotiza', 'text' => 'Hoy', 'cta' => ['label' => 'Ir', 'href' => '/cotizar/'], 'image' => $photo],
        ['layout' => 'faq', 'title' => 'Preguntas frecuentes', 'anchor' => 'preguntas-frecuentes'],
    ]));
    $image = $refs->images[900];

    expect($api)->toBe([
        ['layout' => 'steps', 'title' => 'Cómo funciona', 'anchor' => 'como-funciona', 'image' => $image, 'items' => [
            ['title' => 'Cotiza', 'text' => 'Cuéntanos', 'image' => $image],
            ['title' => 'Instalamos'],
        ]],
        ['layout' => 'features_grid', 'title' => 'Beneficios', 'items' => [['icon' => 'truck', 'title' => 'Logística', 'image' => $image]]],
        ['layout' => 'rich_text', 'title' => 'Qué incluye', 'text' => 'Texto **Markdown**.', 'image' => $image, 'image_position' => 'left'],
        ['layout' => 'rich_text', 'title' => 'Sin foto', 'text' => 'Solo texto.'],
        ['layout' => 'pricing_factors', 'title' => 'Precio', 'image' => $image, 'items' => [['factor' => 'Días', 'detalle' => 'Duración']]],
        ['layout' => 'cta_banner', 'title' => 'Cotiza', 'text' => 'Hoy', 'cta' => ['label' => 'Ir', 'href' => '/cotizar/'], 'image' => $image],
        ['layout' => 'faq', 'title' => 'Preguntas frecuentes', 'anchor' => 'preguntas-frecuentes'],
    ]);
});

it('maps site.yaml to the options page groups', function (): void {
    $values = (new FieldValueMapper(new FakeSeedLookup))->site(sampleBundle()['site']);

    expect($values)->toHaveKeys(['brand', 'contact', 'legal', 'analytics', 'menus'])
        ->not->toHaveKey('sale_banner') // lives in the bp-sitio-en-venta plugin
        ->and($values['legal'])->toHaveKeys(['razon_social', 'nit', 'direccion'])
        ->and($values['menus']['header'][0]['children'])->toHaveCount(2);
});
