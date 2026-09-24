<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Html\ContentRenderer;
use BanosPortatiles\Headless\Html\HtmlCleaner;
use BanosPortatiles\Headless\Normalizer\CiudadNormalizer;
use BanosPortatiles\Headless\Normalizer\FaqNormalizer;
use BanosPortatiles\Headless\Normalizer\HeroNormalizer;
use BanosPortatiles\Headless\Normalizer\NodeNormalizer;
use BanosPortatiles\Headless\Normalizer\SectionsNormalizer;
use BanosPortatiles\Headless\Normalizer\SeoNormalizer;
use BanosPortatiles\Headless\Reviews\RatingService;
use BanosPortatiles\Headless\Reviews\ReviewRecord;
use BanosPortatiles\Headless\Routing\UriResolver;
use BanosPortatiles\Headless\Seo\RankMathSeoSource;
use BanosPortatiles\Headless\Seo\ScfSeoSource;
use BanosPortatiles\Headless\Seo\SeoContext;
use BanosPortatiles\Headless\Tests\Fakes\FakeFieldReader;
use BanosPortatiles\Headless\Tests\Fakes\FakeRankMathApi;
use BanosPortatiles\Headless\Tests\Fakes\FakeReferenceResolver;
use BanosPortatiles\Headless\Tests\Fakes\FakeReviewsGateway;
use Brain\Monkey\Functions;

/**
 * Contract §3: optional properties are OMITTED, never null. Walks the whole Node.
 *
 * @return list<string> JSON paths holding null
 */
function nullPaths(mixed $value, string $path = '$'): array
{
    if ($value === null) {
        return [$path];
    }
    if (is_object($value)) {
        $value = get_object_vars($value);
    }
    if (! is_array($value)) {
        return [];
    }
    $paths = [];
    foreach ($value as $key => $child) {
        $paths = [...$paths, ...nullPaths($child, $path.'.'.$key)];
    }

    return $paths;
}

/**
 * NodeNormalizer with fakes and Brain Monkey stubs for the WordPress functions it calls.
 *
 * @param  array<int|string, array<string, mixed>>  $fields
 */
function nodeNormalizer(array $fields, bool $rankMath = false): NodeNormalizer
{
    Functions\stubs([
        'get_post' => static fn (mixed $post): mixed => $post instanceof WP_Post ? $post : null,
        'get_option' => static fn (string $name): mixed => $name === 'show_on_front' ? 'page' : 0,
        'get_post_ancestors' => [],
        'get_posts' => [],
        'get_page_by_path' => null,
        'get_page_template_slug' => static fn (WP_Post $post): string => $post->ID === 12 ? 'ciudad' : ($post->ID === 11 ? 'servicio' : ''),
        'is_object_in_taxonomy' => false,
        'get_the_terms' => false,
        'get_post_thumbnail_id' => 0,
        'get_post_datetime' => static fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-20T08:00:00-05:00'),
        'current_datetime' => static fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-24T08:00:00-05:00'),
        'wp_strip_all_tags' => static fn (string $text): string => strip_tags($text),
        'wp_trim_words' => static fn (string $text): string => $text,
        'has_blocks' => false,
        'wpautop' => static fn (string $html): string => $html,
        'do_shortcode' => static fn (string $html): string => $html,
        'shortcode_unautop' => static fn (string $html): string => $html,
        'wp_filter_content_tags' => static fn (string $html): string => $html,
        'wp_kses_allowed_html' => [],
        'wp_kses' => static fn (string $html): string => $html,
        'get_bloginfo' => 'BañosPortátiles.net',
        'get_post_meta' => '',
        'attachment_url_to_postid' => 0,
    ]);

    $reader = new FakeFieldReader($fields);
    $refs = new FakeReferenceResolver(uris: [11 => '/alquiler-de-banos-portatiles/']);
    $context = new SeoContext('https://banosportatiles.net', ['admin.banosportatiles.net', 'banosportatiles.net']);
    $gateway = new FakeReviewsGateway;
    $gateway->add(new ReviewRecord(1, 5, 'Ana Pérez', '', 'Opinión de prueba con texto suficiente.', true, new DateTimeImmutable('2026-09-21'), postIds: [9]));
    $cleaner = new HtmlCleaner(['banosportatiles.net'], 'https://admin.banosportatiles.net');

    return new NodeNormalizer(
        $reader,
        $refs,
        new UriResolver,
        new ContentRenderer($cleaner),
        new SeoNormalizer(new RankMathSeoSource(new FakeRankMathApi(isActive: $rankMath), $refs, $context), new ScfSeoSource($reader, $refs, $context)),
        new HeroNormalizer($refs),
        new SectionsNormalizer($refs),
        new FaqNormalizer($refs),
        new CiudadNormalizer($reader),
        new RatingService($gateway, $reader),
    );
}

it('never puts null in a Node (top level, seo, price, rating, reviews, toc)', function (bool $rankMath): void {
    $normalizer = nodeNormalizer([
        'bp_site' => ['toc' => null, 'ratings' => null],
        12 => [
            'seo' => ['title' => '', 'description' => '', 'canonical' => '', 'noindex' => 0, 'og_image' => '', 'keyword' => ''],
            'price' => ['show' => 0, 'mode' => 'from', 'amount' => ''],
            'schema_type' => '',
            'toc' => ['mode' => 'inherit', 'title' => '', 'depth' => 'inherit', 'exclude' => '', 'labels' => false],
            'hero' => ['h1' => '', 'lead' => '', 'bullets' => false, 'image' => '', 'mostrar_formulario' => 0],
        ],
        11 => ['price' => ['show' => 1, 'mode' => 'fixed', 'amount' => 0]],
        9 => ['pillar' => 1, 'author' => ''],
    ], $rankMath);

    $nodes = [
        'city page' => new WP_Post(['ID' => 12, 'post_type' => 'page', 'post_name' => 'medellin', 'post_title' => 'Medellín', 'post_parent' => 0]),
        'service page with an invalid price' => new WP_Post(['ID' => 11, 'post_type' => 'page', 'post_name' => 'alquiler-de-banos-portatiles', 'post_title' => 'Alquiler']),
        'blog post with reviews' => new WP_Post(['ID' => 9, 'post_type' => 'post', 'post_name' => 'pozo-septico-guia', 'post_title' => 'Pozo séptico', 'post_content' => '<h2>Uno</h2><p>Texto.</p>']),
        'equipo' => new WP_Post(['ID' => 7, 'post_type' => 'equipo', 'post_name' => 'bano-portatil-estandar', 'post_title' => 'Baño estándar']),
    ];

    foreach ($nodes as $label => $post) {
        $node = $normalizer->normalize($post);

        expect(nullPaths($node))->toBe([], $label)
            ->and($node)->toHaveKeys(['seo', 'schemaType', 'toc'])
            ->and($node)->not->toHaveKey('price')
            ->and($node['seo'])->toHaveKey('source');
    }

    $post = $normalizer->normalize($nodes['blog post with reviews']);
    expect($post['rating'])->toMatchArray(['count' => 1, 'reviewCount' => 1])
        ->and($post['reviews'])->toHaveCount(1)
        ->and($normalizer->normalize($nodes['city page']))->toHaveKey('rating')->not->toHaveKey('reviews');
})->with(['SCF' => [false], 'Rank Math' => [true]]);
