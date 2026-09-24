<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Fields\FieldGroups;
use BanosPortatiles\Headless\Import\FieldValueMapper;
use BanosPortatiles\Headless\Normalizer\TocResolver;
use BanosPortatiles\Headless\Tests\Fakes\FakeSeedLookup;

it('exposes the global TOC settings for /site with defaults', function (): void {
    expect(TocResolver::site(null))->toBe([
        'enabled_types' => TocResolver::DEFAULT_TYPES,
        'title' => 'En esta página',
        'titleBlog' => 'Contenido de la guía',
        'depth' => 2,
        'min' => 3,
        'numbered' => false,
        'collapsedMobile' => true,
        'sticky' => true,
    ])->and(TocResolver::site([
        'enabled_types' => ['post', 'inventado'],
        'title' => 'Contenido',
        'title_blog' => '',
        'depth' => '3',
        'min' => '0',
        'numbered' => true,
        'collapsed_mobile' => false,
        'sticky' => '0',
    ]))->toBe([
        'enabled_types' => ['post'],
        'title' => 'Contenido',
        'titleBlog' => 'Contenido de la guía',
        'depth' => 3,
        'min' => 3,
        'numbered' => true,
        'collapsedMobile' => false,
        'sticky' => false,
    ])->and(TocResolver::site(['enabled_types' => ''])['enabled_types'])->toBe([]);
});

it('resolves the node TOC from the template and the site defaults', function (): void {
    $service = TocResolver::resolve(null, null, 'servicio');
    $post = TocResolver::resolve(null, ['mode' => 'inherit', 'depth' => 'inherit'], 'post');
    $home = TocResolver::resolve(null, null, 'home');

    expect($service)->toEqual([
        'enabled' => true,
        'title' => 'En esta página',
        'depth' => 2,
        'min' => 3,
        'numbered' => false,
        'collapsedMobile' => true,
        'sticky' => true,
        'exclude' => [],
        'labels' => new stdClass,
    ])->and($post['enabled'])->toBeTrue()
        ->and($post['title'])->toBe('Contenido de la guía')
        ->and($home['enabled'])->toBeFalse()
        ->and(json_encode($service['labels']))->toBe('{}');
});

it('applies the per-page override: mode, title, depth, exclusions and short labels', function (): void {
    $toc = TocResolver::resolve(['depth' => '2'], [
        'mode' => 'show',
        'title' => 'Resumen',
        'depth' => '3',
        'exclude' => "Preguntas frecuentes\n\n  fuentes \nPreguntas frecuentes",
        'labels' => [['id' => '#cuanto-cuesta', 'label' => 'Precio'], ['id' => '', 'label' => 'Sin id'], ['id' => 'normativa', 'label' => '']],
    ], 'home');

    expect($toc)->toMatchArray([
        'enabled' => true,
        'title' => 'Resumen',
        'depth' => 3,
        'exclude' => ['Preguntas frecuentes', 'fuentes'],
        'labels' => ['cuanto-cuesta' => 'Precio'],
    ])->and(TocResolver::resolve(null, ['mode' => 'hide'], 'post')['enabled'])->toBeFalse();
});

it('maps a seed TOC and leaves the editor settings alone when the seed has none', function (): void {
    $mapper = new FieldValueMapper(new FakeSeedLookup);

    expect($mapper->toc(['title' => 'Sin toc']))->toBeNull()
        ->and($mapper->toc(['toc' => ['enabled' => false]]))->toMatchArray(['mode' => 'hide', 'depth' => 'inherit'])
        ->and($mapper->toc(['toc' => ['depth' => 3, 'exclude' => ['Fuentes'], 'labels' => ['cuanto-cuesta' => 'Precio']]]))->toBe([
            'mode' => 'inherit',
            'title' => '',
            'depth' => '3',
            'exclude' => 'Fuentes',
            'labels' => [['id' => 'cuanto-cuesta', 'label' => 'Precio']],
        ]);
});

it('registers the per-page TOC group with the contract field names', function (): void {
    $names = array_map(static fn (array $field): string => $field['name'], FieldGroups::toc()['fields'][0]['sub_fields']);

    expect($names)->toBe(['mode', 'title', 'depth', 'exclude', 'labels']);
});
