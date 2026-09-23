<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Normalizer\FaqNormalizer;
use BanosPortatiles\Headless\Normalizer\HeroNormalizer;
use BanosPortatiles\Headless\Normalizer\SectionsNormalizer;
use BanosPortatiles\Headless\Normalizer\SeoNormalizer;
use BanosPortatiles\Headless\Tests\Fakes\FakeReferenceResolver;

function refs(): FakeReferenceResolver
{
    return new FakeReferenceResolver(
        slugs: [7 => 'bano-portatil-estandar', 9 => 'pozo-septico-guia'],
        uris: [11 => '/alquiler-de-banos-portatiles/', 9 => '/blog/pozo-septico-guia/'],
        terms: [2 => 'medellin', 3 => 'cali'],
        images: [8 => ['src' => 'https://api.banosportatiles.net/wp-content/uploads/a.webp', 'width' => 1200, 'height' => 630, 'alt' => 'Baño']],
        faqs: [5 => ['q' => '¿Cuántos baños necesito?', 'a' => 'Depende del uso.']],
    );
}

it('normalizes flexible content rows into seed-shaped sections', function (): void {
    $sections = (new SectionsNormalizer(refs()))->normalize([
        ['acf_fc_layout' => 'features_grid', 'title' => 'Beneficios', 'intro' => '', 'items' => [['icon' => 'truck', 'title' => 'Logística', 'text' => ''], ['icon' => '', 'title' => '', 'text' => '']]],
        ['acf_fc_layout' => 'contenido'],
        ['acf_fc_layout' => 'equipment_grid', 'title' => '', 'items' => [7, 999]],
        ['acf_fc_layout' => 'services_grid', 'title' => 'Servicios', 'items' => false],
        ['acf_fc_layout' => 'coverage', 'title' => 'Cobertura', 'items' => [2, 3]],
        ['acf_fc_layout' => 'comparison_table', 'title' => 'Tabla', 'columns' => [['label' => 'A'], ['label' => 'B']], 'rows' => [['cells' => [['value' => '1'], ['value' => '2']]], ['cells' => [['value' => ''], ['value' => '']]]], 'note' => ''],
        ['acf_fc_layout' => 'callout', 'variant' => '', 'title' => 'Norma', 'text' => 'Texto', 'source' => ['title' => '', 'url' => '']],
        ['acf_fc_layout' => 'cta_banner', 'title' => 'CTA', 'text' => '', 'cta' => ['label' => 'Cotizar', 'href' => '/cotizar/']],
        ['acf_fc_layout' => 'gallery', 'title' => '', 'items' => [8]],
        ['acf_fc_layout' => 'desconocido', 'title' => 'x'],
        'basura',
    ]);

    expect($sections)->toBe([
        ['layout' => 'features_grid', 'title' => 'Beneficios', 'items' => [['icon' => 'truck', 'title' => 'Logística']]],
        ['layout' => 'contenido'],
        ['layout' => 'equipment_grid', 'items' => ['bano-portatil-estandar']],
        ['layout' => 'services_grid', 'title' => 'Servicios', 'items' => []],
        ['layout' => 'coverage', 'title' => 'Cobertura', 'items' => ['medellin', 'cali']],
        ['layout' => 'comparison_table', 'title' => 'Tabla', 'columns' => ['A', 'B'], 'rows' => [['1', '2']]],
        ['layout' => 'callout', 'variant' => 'info', 'title' => 'Norma', 'text' => 'Texto'],
        ['layout' => 'cta_banner', 'title' => 'CTA', 'cta' => ['label' => 'Cotizar', 'href' => '/cotizar/']],
        ['layout' => 'gallery', 'items' => [refs()->images[8]]],
    ]);
});

it('normalizes the hero with fallbacks and omits empty optional keys', function (): void {
    $normalizer = new HeroNormalizer(refs());

    expect($normalizer->normalize(['h1' => '', 'lead' => 'Entradilla', 'bullets' => [['text' => 'Uno'], ['text' => '']], 'image' => 8, 'cta_primario' => ['label' => 'Cotizar', 'href' => '/cotizar/'], 'cta_secundario' => ['label' => '', 'href' => ''], 'mostrar_formulario' => 1], 'Título'))
        ->toBe([
            'h1' => 'Título',
            'lead' => 'Entradilla',
            'bullets' => ['Uno'],
            'image' => refs()->images[8],
            'cta_primario' => ['label' => 'Cotizar', 'href' => '/cotizar/'],
            'mostrar_formulario' => true,
        ])
        ->and($normalizer->normalize(['h1' => '', 'bullets' => [], 'mostrar_formulario' => 0], 'Título'))->toBeNull()
        ->and($normalizer->normalize(false, 'Título'))->toBeNull();
});

it('normalizes SEO with title/description fallbacks', function (): void {
    $normalizer = new SeoNormalizer(refs());
    $long = str_repeat('palabra ', 40);

    expect($normalizer->normalize(['title' => 'SEO', 'description' => 'Desc', 'canonical' => '', 'noindex' => 1, 'og_image' => 8, 'keyword' => 'kw'], 'T', 'E'))
        ->toBe(['title' => 'SEO', 'description' => 'Desc', 'noindex' => true, 'ogImage' => refs()->images[8], 'keyword' => 'kw'])
        ->and($normalizer->normalize(null, 'Título', 'Extracto'))->toBe(['title' => 'Título', 'description' => 'Extracto', 'noindex' => false])
        ->and(mb_strlen((string) $normalizer->normalize([], 'T', $long)['description']))->toBeLessThanOrEqual(SeoNormalizer::DESCRIPTION_LENGTH);
});

it('merges inline and bank FAQs without duplicates', function (): void {
    $faqs = (new FaqNormalizer(refs(), fn (string $a): string => mb_strtoupper($a)))->normalize(
        [['q' => '¿Propia?', 'a' => 'sí'], ['q' => '', 'a' => 'sin pregunta'], ['q' => '¿Cuántos baños necesito?', 'a' => 'inline']],
        [5, 6]
    );

    expect($faqs)->toBe([
        ['q' => '¿Propia?', 'a' => 'SÍ'],
        ['q' => '¿Cuántos baños necesito?', 'a' => 'INLINE'],
    ]);
});
