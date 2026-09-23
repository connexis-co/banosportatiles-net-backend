<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Routing\UriResolver;
use Brain\Monkey\Functions;

it('normalizes paths and URLs', function (string $input, string $expected): void {
    expect(UriResolver::normalize($input))->toBe($expected);
})->with([
    ['', '/'],
    ['/', '/'],
    ['alquiler-de-banos-portatiles', '/alquiler-de-banos-portatiles/'],
    ['//alquiler//medellin', '/alquiler/medellin/'],
    ['https://banosportatiles.net/blog/x?utm=1#top', '/blog/x/'],
    ['/../etc/passwd', '/etc/passwd/'],
]);

it('classifies public URIs', function (string $uri, string $kind, string $slug): void {
    expect(UriResolver::classify($uri))->toBe(['kind' => $kind, 'slug' => $slug]);
})->with([
    ['/', 'front', ''],
    ['/blog/', 'page', 'blog'],
    ['/blog/pozo-septico-guia/', 'post', 'pozo-septico-guia'],
    ['/blog/tema/pozos-septicos/', 'category', 'pozos-septicos'],
    ['/blog/a/b/c/', 'none', ''],
    ['/equipos/', 'page', 'equipos'],
    ['/equipos/bano-portatil-estandar/', 'equipo', 'bano-portatil-estandar'],
    ['/alquiler-de-banos-portatiles/medellin/', 'page', 'alquiler-de-banos-portatiles/medellin'],
]);

it('builds URIs for pages, posts and equipos', function (): void {
    $posts = [
        10 => new WP_Post(['ID' => 10, 'post_type' => 'page', 'post_name' => 'inicio']),
        11 => new WP_Post(['ID' => 11, 'post_type' => 'page', 'post_name' => 'alquiler-de-banos-portatiles']),
        12 => new WP_Post(['ID' => 12, 'post_type' => 'page', 'post_name' => 'medellin', 'post_parent' => 11]),
        20 => new WP_Post(['ID' => 20, 'post_type' => 'post', 'post_name' => 'pozo-septico-guia']),
        30 => new WP_Post(['ID' => 30, 'post_type' => 'equipo', 'post_name' => 'bano-vip']),
        40 => new WP_Post(['ID' => 40, 'post_type' => 'post', 'post_name' => '', 'post_title' => 'Borrador sin slug', 'post_status' => 'draft']),
    ];
    Functions\when('get_post')->alias(fn (mixed $p): ?WP_Post => $p instanceof WP_Post ? $p : ($posts[(int) $p] ?? null));
    Functions\when('get_option')->alias(fn (string $name): mixed => ['show_on_front' => 'page', 'page_on_front' => 10][$name] ?? false);
    Functions\when('get_post_ancestors')->alias(fn (WP_Post $p): array => $p->post_parent > 0 ? [$p->post_parent] : []);
    Functions\when('sanitize_title')->alias(fn (string $t): string => strtolower(str_replace(' ', '-', $t)));

    $resolver = new UriResolver;

    expect($resolver->forPost(10))->toBe('/')
        ->and($resolver->forPost(11))->toBe('/alquiler-de-banos-portatiles/')
        ->and($resolver->forPost(12))->toBe('/alquiler-de-banos-portatiles/medellin/')
        ->and($resolver->forPost(20))->toBe('/blog/pozo-septico-guia/')
        ->and($resolver->forPost(30))->toBe('/equipos/bano-vip/')
        ->and($resolver->forPost(40))->toBeNull()
        ->and($resolver->forPost(40, true))->toBe('/blog/borrador-sin-slug/')
        ->and($resolver->forTerm(new WP_Term(['slug' => 'pozos-septicos', 'taxonomy' => 'category'])))->toBe('/blog/tema/pozos-septicos/')
        ->and($resolver->forTerm(new WP_Term(['slug' => 'medellin', 'taxonomy' => 'ciudad'])))->toBeNull();
});

it('resolves URIs to published content only unless drafts are allowed', function (): void {
    $draft = new WP_Post(['ID' => 5, 'post_type' => 'page', 'post_name' => 'cotizar', 'post_status' => 'draft']);
    Functions\when('get_page_by_path')->justReturn($draft);
    Functions\when('get_option')->justReturn(false);

    $resolver = new UriResolver;

    expect($resolver->resolve('/cotizar/'))->toBeNull()
        ->and($resolver->resolve('/cotizar/', true))->toBe($draft)
        ->and($resolver->resolve('/blog/a/b/c/'))->toBeNull();
});
