<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Import\FieldValueMapper;
use BanosPortatiles\Headless\Normalizer\CiudadNormalizer;
use BanosPortatiles\Headless\Normalizer\SiteNormalizer;
use BanosPortatiles\Headless\Normalizer\TocResolver;
use BanosPortatiles\Headless\Reviews\RatingSettings;
use BanosPortatiles\Headless\Tests\Fakes\FakeFieldReader;
use BanosPortatiles\Headless\Tests\Fakes\FakeSeedLookup;

/**
 * Same shape as frontend/src/content/seed/site.yaml (header, menus with icon/kind/group, secondary links,
 * ratings, toc and microcopy).
 *
 * @return array<string, mixed>
 */
function frontSite(): array
{
    return [
        'brand' => ['name' => 'BañosPortátiles.net', 'tagline' => 'Baños portátiles y saneamiento', 'logo' => ''],
        'header' => ['cta_label' => 'Solicitar cotización', 'cta_short' => 'Cotizar', 'cta_href' => '/cotizar/'],
        'legal' => ['responsable' => 'Connexis S.A.S.', 'nit' => '902.036.644-0', 'telefono' => '+57 300 288 8757'],
        'menus' => [
            'header' => [
                ['label' => 'Saneamiento', 'href' => '/pozos-septicos/', 'icon' => 'cylinder', 'description' => 'Pozos y más', 'children' => [
                    ['label' => 'Pozos sépticos', 'href' => '/pozos-septicos/', 'icon' => 'cylinder', 'description' => 'Cómo funcionan', 'group' => 'Pozos y tanques sépticos'],
                ]],
                ['label' => 'Ciudades', 'href' => '/cobertura/', 'icon' => 'map-pinned', 'kind' => 'ciudades', 'children' => [['label' => 'Bogotá', 'href' => '/bogota/']]],
            ],
            'secondary' => [['label' => 'Nosotros', 'href' => '/nosotros/', 'icon' => 'handshake'], ['label' => 'Contacto', 'href' => '/contacto/']],
            'footer' => [['title' => 'Legal', 'links' => [['label' => 'Cookies', 'href' => '/politica-de-cookies/']]]],
        ],
        'ratings' => [
            'enabled' => true,
            'types' => ['servicios' => ['stars' => true, 'reviews' => false], 'blog' => ['stars' => true, 'reviews' => true], 'otras' => ['stars' => false, 'reviews' => false]],
            'minCountForSchema' => 2,
            'autoApproveReviews' => false,
            'texts' => ['starsTitle' => 'Califica esta página', 'firstVote' => 'Sé el primero en calificar', 'reviewsTitle' => 'Comentarios'],
        ],
        'toc' => [
            'enabled_types' => ['hub-servicio', 'servicio', 'ciudad', 'landing', 'legal', 'equipos', 'blog'],
            'title' => 'En esta página',
            'titleBlog' => 'Contenido de la guía',
            'depth' => 2,
            'depthBlog' => 3,
            'min' => 3,
            'numbered' => false,
            'collapsedMobile' => true,
            'sticky' => true,
        ],
        'microcopy' => [
            'cta_banner' => ['title' => 'Cuéntanos qué necesitas', 'text' => 'Te cotizamos.', 'label' => 'Solicitar cotización'],
            'aside_title' => '¿Necesitas una cotización?',
            'aside_bullets' => ['Sin compromiso', 'Coordinamos entrega'],
            'aside_label' => 'Solicitar cotización',
            'mega_footer' => 'Coordinamos el servicio en 18 ciudades.',
            'blog_cta' => ['title' => '¿Necesitas el servicio?', 'text' => '', 'label' => 'Cotizar'],
            'equipo_cta' => ['title' => '¿Necesitas {equipo}?', 'text' => 'Dinos cuántas unidades.', 'label' => 'Solicitar cotización'],
        ],
    ];
}

it('imports the new site settings from site.yaml into the SCF groups', function (): void {
    $warnings = [];
    $values = (new FieldValueMapper(new FakeSeedLookup, function (string $w) use (&$warnings): void {
        $warnings[] = $w;
    }))->site(frontSite());

    expect($values['header'])->toBe(['cta_label' => 'Solicitar cotización', 'cta_short' => 'Cotizar', 'cta_href' => '/cotizar/'])
        ->and($values['legal']['telefono'])->toBe('+57 300 288 8757')
        ->and($values['brand']['logo'])->toBe('')
        ->and($values['menus']['header'][0])->toMatchArray(['label' => 'Saneamiento', 'icon' => 'cylinder', 'kind' => 'links'])
        ->and($values['menus']['header'][0]['children'][0])->toBe(['label' => 'Pozos sépticos', 'href' => '/pozos-septicos/', 'description' => 'Cómo funcionan', 'icon' => 'cylinder', 'group' => 'Pozos y tanques sépticos'])
        ->and($values['menus']['header'][1]['kind'])->toBe('ciudades')
        ->and($values['menus']['secondary'][1])->toBe(['label' => 'Contacto', 'href' => '/contacto/', 'icon' => ''])
        ->and($values['ratings'])->toMatchArray(['enabled' => 1, 'min_count_for_schema' => 2, 'auto_approve_reviews' => 0])
        ->and($values['ratings']['types']['blog'])->toBe(['stars' => 1, 'reviews' => 1])
        ->and($values['ratings']['texts']['first_vote'])->toBe('Sé el primero en calificar')
        ->and($values['toc'])->toMatchArray(['depth' => '2', 'depth_blog' => '3', 'min' => 3, 'collapsed_mobile' => 1, 'sticky' => 1])
        ->and($values['toc']['enabled_types'])->toBe(['hub-servicio', 'servicio', 'ciudad', 'landing', 'legal', 'equipos', 'post'])
        ->and($values['microcopy']['aside_bullets'])->toBe([['text' => 'Sin compromiso'], ['text' => 'Coordinamos entrega']])
        ->and($warnings)->toBe([]);
});

it('round-trips menus, texts, ratings and TOC: seed → SCF → /site', function (): void {
    $values = (new FieldValueMapper(new FakeSeedLookup))->site(frontSite());
    $site = frontSite();

    expect(SiteNormalizer::headerMenu($values['menus']['header']))->toBe([
        ['label' => 'Saneamiento', 'href' => '/pozos-septicos/', 'description' => 'Pozos y más', 'icon' => 'cylinder', 'kind' => 'links', 'children' => [
            ['label' => 'Pozos sépticos', 'href' => '/pozos-septicos/', 'description' => 'Cómo funcionan', 'icon' => 'cylinder', 'group' => 'Pozos y tanques sépticos'],
        ]],
        ['label' => 'Ciudades', 'href' => '/cobertura/', 'icon' => 'map-pinned', 'kind' => 'ciudades', 'children' => [['label' => 'Bogotá', 'href' => '/bogota/']]],
    ])->and(SiteNormalizer::secondaryMenu($values['menus']['secondary']))->toBe([
        ['label' => 'Nosotros', 'href' => '/nosotros/', 'icon' => 'handshake'],
        ['label' => 'Contacto', 'href' => '/contacto/'],
    ])->and(SiteNormalizer::microcopy($values['microcopy']))->toBe([
        'cta_banner' => $site['microcopy']['cta_banner'],
        'aside_title' => '¿Necesitas una cotización?',
        'aside_bullets' => ['Sin compromiso', 'Coordinamos entrega'],
        'aside_label' => 'Solicitar cotización',
        'mega_footer' => 'Coordinamos el servicio en 18 ciudades.',
        'blog_cta' => ['title' => '¿Necesitas el servicio?', 'label' => 'Cotizar'],
        'equipo_cta' => $site['microcopy']['equipo_cta'],
    ])->and(SiteNormalizer::header($values['header']))->toBe($site['header']);

    $ratings = RatingSettings::fromOption($values['ratings'])->toSite(true);
    expect($ratings['minCountForSchema'])->toBe(2)
        ->and($ratings['types']['blog'])->toBe(['stars' => true, 'reviews' => true])
        ->and($ratings['types']['ciudades'])->toBe(['stars' => true, 'reviews' => true])
        ->and($ratings['texts']['reviewsTitle'])->toBe('Comentarios')
        ->and($ratings['texts']['firstVote'])->toBe('Sé el primero en calificar');

    expect(TocResolver::site($values['toc']))->toBe([
        'enabled_types' => ['hub-servicio', 'servicio', 'ciudad', 'landing', 'legal', 'equipos', 'post'],
        'title' => 'En esta página',
        'titleBlog' => 'Contenido de la guía',
        'depth' => 2,
        'depthBlog' => 3,
        'min' => 3,
        'numbered' => false,
        'collapsedMobile' => true,
        'sticky' => true,
    ])->and(TocResolver::resolve($values['toc'], null, 'post')['depth'])->toBe(3)
        ->and(TocResolver::resolve($values['toc'], null, 'servicio')['depth'])->toBe(2);
});

it('fills the header button with its defaults and returns an empty microcopy object', function (): void {
    expect(SiteNormalizer::header(['cta_label' => '', 'cta_href' => '/contacto/']))->toBe(['cta_label' => 'Solicitar cotización', 'cta_short' => 'Cotizar', 'cta_href' => '/contacto/'])
        ->and(json_encode(SiteNormalizer::microcopy([])))->toBe('{}')
        ->and(json_encode(SiteNormalizer::microcopy(['cta_banner' => ['title' => '', 'text' => '', 'label' => '']])))->toBe('{}');
});

it('maps and exposes the region of each city', function (): void {
    $warnings = [];
    $mapper = new FieldValueMapper(new FakeSeedLookup, function (string $w) use (&$warnings): void {
        $warnings[] = $w;
    });
    $city = new WP_Term(['term_id' => 4, 'slug' => 'barranquilla', 'name' => 'Barranquilla', 'taxonomy' => 'ciudad']);

    expect($mapper->ciudad(['slug' => 'barranquilla', 'region' => 'Caribe'])['region'])->toBe('Caribe')
        ->and($mapper->ciudad(['slug' => 'x', 'region' => 'Amazonía'])['region'])->toBe('')
        ->and($warnings)->toHaveCount(1)
        ->and((new CiudadNormalizer(new FakeFieldReader(['term_4' => ['departamento' => 'Atlántico', 'region' => 'Caribe']])))->full($city))
        ->toMatchArray(['departamento' => 'Atlántico', 'region' => 'Caribe'])
        ->and((new CiudadNormalizer(new FakeFieldReader))->full($city))->not->toHaveKey('region');
});

it('maps a per-page rating override from the seed', function (): void {
    $mapper = new FieldValueMapper(new FakeSeedLookup);

    expect($mapper->ratings(['title' => 'Sin override']))->toBeNull()
        ->and($mapper->ratings(['rating' => false]))->toBe(['stars' => 'no', 'reviews' => 'no'])
        ->and($mapper->ratings(['rating' => ['reviews' => true]]))->toBe(['stars' => 'inherit', 'reviews' => 'yes'])
        ->and($mapper->ratings(['rating' => ['count' => 3]]))->toBeNull();
});
