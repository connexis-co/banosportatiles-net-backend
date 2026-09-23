<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Import\FieldValueMapper;
use BanosPortatiles\Headless\Normalizer\CiudadNormalizer;
use BanosPortatiles\Headless\Tests\Fakes\FakeFieldReader;
use BanosPortatiles\Headless\Tests\Fakes\FakeSeedLookup;

function medellin(): WP_Term
{
    return new WP_Term(['term_id' => 2, 'slug' => 'medellin', 'name' => 'Medell&iacute;n', 'taxonomy' => 'ciudad']);
}

it('exposes the full city (with autoridad_ambiental) for GET /site', function (): void {
    $normalizer = new CiudadNormalizer(new FakeFieldReader(['term_2' => [
        'departamento' => 'Antioquia',
        'autoridad_ambiental' => ' Área Metropolitana del Valle de Aburrá (AMVA) ',
        'lat' => '6.2442',
        'lng' => -75.5812,
        'cercanos' => "Envigado\nBello\n\n Itagüí ",
        'nota' => 'Laderas.',
    ]]));

    expect($normalizer->full(medellin()))->toBe([
        'slug' => 'medellin',
        'name' => 'Medellín',
        'departamento' => 'Antioquia',
        'autoridad_ambiental' => 'Área Metropolitana del Valle de Aburrá (AMVA)',
        'lat' => 6.2442,
        'lng' => -75.5812,
        'cercanos' => ['Envigado', 'Bello', 'Itagüí'],
        'nota' => 'Laderas.',
    ]);
});

it('keeps every /site key even when the term fields are empty (coordinates omitted)', function (): void {
    expect((new CiudadNormalizer(new FakeFieldReader))->full(medellin()))->toBe([
        'slug' => 'medellin',
        'name' => 'Medellín',
        'departamento' => '',
        'autoridad_ambiental' => '',
        'cercanos' => [],
        'nota' => '',
    ]);
});

it('adds autoridad_ambiental to Node terms only when it is set', function (): void {
    $withAuthority = new CiudadNormalizer(new FakeFieldReader(['term_2' => ['autoridad_ambiental' => 'AMVA']]));
    $without = new CiudadNormalizer(new FakeFieldReader);

    expect($withAuthority->term(medellin()))->toBe(['id' => 2, 'slug' => 'medellin', 'name' => 'Medellín', 'autoridad_ambiental' => 'AMVA'])
        ->and($without->term(medellin()))->toBe(['id' => 2, 'slug' => 'medellin', 'name' => 'Medellín']);
});

it('imports autoridad_ambiental from ciudades.yaml', function (): void {
    $mapper = new FieldValueMapper(new FakeSeedLookup);
    $values = array_map($mapper->ciudad(...), sampleBundle()['ciudades']);

    expect(array_column($values, 'autoridad_ambiental'))->toBe([
        'Área Metropolitana del Valle de Aburrá (AMVA)',
        'Departamento Administrativo de Gestión del Medio Ambiente (DAGMA)',
    ])->and($values[0])->toMatchArray(['departamento' => 'Antioquia', 'lat' => 6.2442, 'cercanos' => "Envigado\nBello\nItagüí\nSabaneta\nRionegro"]);
});
