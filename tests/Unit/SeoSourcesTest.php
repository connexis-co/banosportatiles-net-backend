<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Normalizer\SeoNormalizer;
use BanosPortatiles\Headless\Seo\CanonicalUrl;
use BanosPortatiles\Headless\Seo\RankMathMigration;
use BanosPortatiles\Headless\Seo\RankMathSeoSource;
use BanosPortatiles\Headless\Seo\RankMathSetup;
use BanosPortatiles\Headless\Seo\ScfSeoSource;
use BanosPortatiles\Headless\Seo\SeoContext;
use BanosPortatiles\Headless\Tests\Fakes\FakeFieldReader;
use BanosPortatiles\Headless\Tests\Fakes\FakeRankMathApi;
use BanosPortatiles\Headless\Tests\Fakes\FakeReferenceResolver;
use Brain\Monkey\Functions;

const FRONT = 'https://banosportatiles.net';

function seoContext(): SeoContext
{
    return new SeoContext(FRONT, ['admin.banosportatiles.net', 'banosportatiles.net', 'www.banosportatiles.net']);
}

function seoRefs(): FakeReferenceResolver
{
    return new FakeReferenceResolver(images: [
        8 => ['src' => 'https://admin.banosportatiles.net/wp-content/uploads/og.webp', 'width' => 1200, 'height' => 630, 'alt' => 'OG'],
        9 => ['src' => 'https://admin.banosportatiles.net/wp-content/uploads/tw.webp', 'width' => 1200, 'height' => 600, 'alt' => 'TW'],
    ]);
}

function medellinPage(): WP_Post
{
    return new WP_Post(['ID' => 12, 'post_type' => 'page', 'post_title' => 'Alquiler de baños portátiles en Medellín', 'post_excerpt' => '']);
}

/**
 * Rank Math meta of the test page (Brain Monkey: get_post_meta).
 *
 * @param  array<string, mixed>  $meta
 */
function rankMathMeta(array $meta): void
{
    Functions\when('get_post_meta')->alias(static fn (int $id, string $key = '', bool $single = false): mixed => $meta[$key] ?? '');
    Functions\when('attachment_url_to_postid')->alias(static fn (string $url): int => str_ends_with($url, 'tw.webp') ? 9 : 0);
}

it('publishes canonicals on the public domain and omits self-references', function (string $raw, ?string $expected): void {
    expect(CanonicalUrl::resolve($raw, '/medellin/', FRONT, ['admin.banosportatiles.net', 'banosportatiles.net', 'www.banosportatiles.net']))->toBe($expected);
})->with([
    'empty' => ['', null],
    'cms url' => ['https://admin.banosportatiles.net/alquiler-de-banos-portatiles/', FRONT.'/alquiler-de-banos-portatiles/'],
    'www without slash' => ['https://www.banosportatiles.net/cali', FRONT.'/cali/'],
    'relative path' => ['/pozos-septicos/limpieza', FRONT.'/pozos-septicos/limpieza/'],
    'itself (cms)' => ['https://admin.banosportatiles.net/medellin/?utm=x', null],
    'itself (public)' => [FRONT.'/medellin/', null],
    'other domain' => ['https://banosportatilesbogota.co/alquiler/', 'https://banosportatilesbogota.co/alquiler/'],
    'garbage' => ['http:///nada', null],
]);

it('keeps the SCF source as the fallback with source "bp"', function (): void {
    $source = new ScfSeoSource(new FakeFieldReader, seoRefs(), seoContext());
    $long = str_repeat('palabra ', 40);

    expect($source->fromValues(['title' => 'SEO', 'description' => 'Desc', 'canonical' => 'https://admin.banosportatiles.net/cali/', 'noindex' => 1, 'og_image' => 8, 'keyword' => 'kw'], 'T', 'E', '/medellin/'))
        ->toBe([
            'title' => 'SEO',
            'description' => 'Desc',
            'canonical' => FRONT.'/cali/',
            'noindex' => true,
            'ogImage' => seoRefs()->images[8],
            'keyword' => 'kw',
            'keywords' => ['kw'],
            'source' => 'bp',
        ])
        ->and($source->fromValues(null, 'Título', 'Extracto'))->toBe(['title' => 'Título', 'description' => 'Extracto', 'noindex' => false, 'source' => 'bp'])
        ->and(mb_strlen((string) $source->fromValues([], 'T', $long)['description']))->toBeLessThanOrEqual(SeoNormalizer::DESCRIPTION_LENGTH);
});

it('reads Rank Math meta with its variables, keywords, social data and breadcrumb title', function (): void {
    rankMathMeta([
        'rank_math_title' => '%title% %sep% Cotiza',
        'rank_math_description' => 'Alquiler en Medellín y el Valle de Aburrá.',
        'rank_math_focus_keyword' => 'alquiler de baños portátiles medellín, baños portátiles medellín ,alquiler de baños portátiles medellín',
        'rank_math_canonical_url' => 'https://admin.banosportatiles.net/medellin/',
        'rank_math_robots' => ['index', 'follow', 'noarchive'],
        'rank_math_advanced_robots' => ['max-snippet' => '-1', 'max-image-preview' => 'large', 'max-video-preview' => '-1'],
        'rank_math_facebook_title' => 'Baños portátiles en %title%',
        'rank_math_facebook_image_id' => '8',
        'rank_math_twitter_use_facebook' => 'off',
        'rank_math_twitter_title' => 'Twitter %sep% %sitename%',
        'rank_math_twitter_image' => 'https://admin.banosportatiles.net/wp-content/uploads/tw.webp',
        'rank_math_twitter_card_type' => 'summary_card',
        'rank_math_breadcrumb_title' => 'Medellín',
    ]);
    $source = new RankMathSeoSource(new FakeRankMathApi(['titles.title_separator' => '|']), seoRefs(), seoContext());

    expect($source->normalize(medellinPage(), 'T', 'E', '/medellin/'))->toBe([
        'title' => 'Alquiler de baños portátiles en Medellín | Cotiza',
        'description' => 'Alquiler en Medellín y el Valle de Aburrá.',
        'noindex' => false,
        'robots' => ['maxSnippet' => -1, 'maxImagePreview' => 'large', 'maxVideoPreview' => -1, 'noarchive' => true],
        'ogTitle' => 'Baños portátiles en Alquiler de baños portátiles en Medellín',
        'ogImage' => seoRefs()->images[8],
        'twitterTitle' => 'Twitter | BañosPortátiles.net',
        'twitterImage' => seoRefs()->images[9],
        'twitterCard' => 'summary',
        'keyword' => 'alquiler de baños portátiles medellín',
        'keywords' => ['alquiler de baños portátiles medellín', 'baños portátiles medellín'],
        'breadcrumbTitle' => 'Medellín',
        'source' => 'rankmath',
    ]);
});

it('falls back to the post type templates of Rank Math', function (): void {
    rankMathMeta([]);
    $api = new FakeRankMathApi(['titles.pt_page_title' => '%title% %sep% %sitename%', 'titles.pt_page_description' => '%excerpt%', 'titles.title_separator' => '|', 'titles.twitter_card_type' => 'summary_large_image']);
    $seo = (new RankMathSeoSource($api, seoRefs(), seoContext()))->normalize(medellinPage(), 'T', 'E', '/medellin/');

    expect($seo)->toBe([
        'title' => 'Alquiler de baños portátiles en Medellín | BañosPortátiles.net',
        'description' => 'Extracto automático del contenido.',
        'noindex' => false,
        'twitterCard' => 'summary_large_image',
        'source' => 'rankmath',
    ]);
});

it('resolves robots from the post, then its type, then the global default, never from the CMS host', function (): void {
    $settings = [
        'titles.robots_global' => ['index'],
        'titles.pt_post_custom_robots' => true,
        'titles.pt_post_robots' => ['noindex', 'nofollow'],
        'titles.pt_page_custom_robots' => false,
        'titles.pt_page_robots' => ['noindex'],
    ];
    $source = new RankMathSeoSource(new FakeRankMathApi($settings), seoRefs(), seoContext());
    $post = new WP_Post(['ID' => 3, 'post_type' => 'post', 'post_title' => 'Guía']);

    rankMathMeta([]);
    expect($source->noindex(medellinPage()))->toBeFalse()
        ->and($source->noindex($post))->toBeTrue()
        ->and($source->normalize($post, 'Guía', 'E', '/blog/guia/'))->toMatchArray(['noindex' => true, 'nofollow' => true]);

    rankMathMeta(['rank_math_robots' => ['noindex', 'nosnippet']]);
    expect($source->normalize(medellinPage(), 'T', 'E', '/medellin/'))->toMatchArray(['noindex' => true, 'robots' => ['maxSnippet' => 0]]);
});

it('falls back to SCF when Rank Math is inactive or fails', function (): void {
    $fields = new FakeFieldReader([12 => ['seo' => ['title' => 'Título SCF', 'noindex' => true]]]);
    $scf = new ScfSeoSource($fields, seoRefs(), seoContext());
    $inactive = new SeoNormalizer(new RankMathSeoSource(new FakeRankMathApi(isActive: false), seoRefs(), seoContext()), $scf);
    $broken = new SeoNormalizer(new RankMathSeoSource(new FakeRankMathApi(failure: new RuntimeException('meta cambiada')), seoRefs(), seoContext()), $scf);
    rankMathMeta(['rank_math_title' => '%title%']);

    $log = ini_get('error_log');
    ini_set('error_log', '/dev/null');
    try {
        expect($inactive->normalize(medellinPage(), 'T', 'E', '/medellin/'))->toMatchArray(['title' => 'Título SCF', 'source' => 'bp'])
            ->and($broken->normalize(medellinPage(), 'T', 'E', '/medellin/'))->toMatchArray(['title' => 'Título SCF', 'noindex' => true, 'source' => 'bp'])
            ->and($inactive->noindex(medellinPage()))->toBeTrue();
    } finally {
        ini_set('error_log', (string) $log);
    }
});

it('plans the SCF → Rank Math migration without overwriting (unless forced) and with up to 5 keywords', function (): void {
    $target = RankMathMigration::fromScf([
        'title' => 'Título',
        'description' => '',
        'keyword' => 'kw 1',
        'keywords_secundarias' => "kw 2\nKW 1\nkw 3, kw 4\nkw 5\nkw 6",
        'canonical' => '',
        'noindex' => true,
        'og_image' => 8,
    ], 'https://cms/og.webp');

    expect($target['rank_math_focus_keyword'])->toBe('kw 1,kw 2,kw 3,kw 4,kw 5')
        ->and($target['rank_math_robots'])->toBe(['noindex'])
        ->and(RankMathMigration::plan($target, [], false))->toBe([
            'rank_math_title' => 'Título',
            'rank_math_focus_keyword' => 'kw 1,kw 2,kw 3,kw 4,kw 5',
            'rank_math_robots' => ['noindex'],
            'rank_math_facebook_image_id' => '8',
            'rank_math_facebook_image' => 'https://cms/og.webp',
        ])
        ->and(RankMathMigration::plan($target, ['rank_math_title' => 'Ya editado', 'rank_math_robots' => ['index'], 'rank_math_facebook_image_id' => '3'], false))
        ->toBe(['rank_math_focus_keyword' => 'kw 1,kw 2,kw 3,kw 4,kw 5'])
        ->and(RankMathMigration::plan($target, ['rank_math_title' => 'Ya editado', 'rank_math_description' => 'Se conserva'], true))
        ->not->toHaveKey('rank_math_description')
        ->toHaveKey('rank_math_title');
});

it('turns the needed Rank Math modules on and the unused ones off', function (): void {
    expect(RankMathSetup::modules(['link-counter', 'analytics', 'seo-analysis', 'sitemap', 'rich-snippet', 'content-ai', 'llms-txt']))
        ->toBe(['seo-analysis', 'content-ai', 'acf']);
});
