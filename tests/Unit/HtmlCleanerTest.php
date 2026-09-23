<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Html\HtmlCleaner;

function cleaner(?callable $mapper = null): HtmlCleaner
{
    return new HtmlCleaner(['banosportatiles.net', 'www.banosportatiles.net'], 'https://api.banosportatiles.net', $mapper);
}

it('rewrites links to the public host as relative URIs with a trailing slash', function (string $href, string $expected): void {
    expect(cleaner()->rewriteHref($href))->toBe($expected);
})->with([
    'absolute' => ['https://banosportatiles.net/blog/pozo-septico-guia', '/blog/pozo-septico-guia/'],
    'www' => ['https://www.banosportatiles.net/cotizar/', '/cotizar/'],
    'query and fragment' => ['https://banosportatiles.net/cotizar?utm_source=x#form', '/cotizar/?utm_source=x#form'],
    'root' => ['https://banosportatiles.net', '/'],
    'relative' => ['/alquiler-de-banos-portatiles/medellin', '/alquiler-de-banos-portatiles/medellin/'],
    'file keeps no slash' => ['/docs/ficha.pdf', '/docs/ficha.pdf'],
    'anchor' => ['#faq', '#faq'],
    'mailto' => ['mailto:hola@example.com', 'mailto:hola@example.com'],
    'tel' => ['tel:+573000000000', 'tel:+573000000000'],
    'external' => ['https://www.minvivienda.gov.co/norma', 'https://www.minvivienda.gov.co/norma'],
]);

it('maps CMS permalinks through the resolver and keeps CMS-only paths absolute', function (): void {
    $cleaner = cleaner(fn (string $path): ?string => $path === '/pozo-septico-guia/' ? '/blog/pozo-septico-guia/' : null);

    expect($cleaner->rewriteHref('https://api.banosportatiles.net/pozo-septico-guia/#mantenimiento'))->toBe('/blog/pozo-septico-guia/#mantenimiento')
        ->and($cleaner->rewriteHref('https://api.banosportatiles.net/alquiler/medellin'))->toBe('/alquiler/medellin/')
        ->and($cleaner->rewriteHref('https://api.banosportatiles.net/wp-content/uploads/a.pdf'))->toBe('https://api.banosportatiles.net/wp-content/uploads/a.pdf')
        ->and($cleaner->rewriteHref('https://api.banosportatiles.net/wp-admin/'))->toBe('https://api.banosportatiles.net/wp-admin/');
});

it('distinguishes hosts by port (local CMS vs local front)', function (): void {
    $cleaner = new HtmlCleaner(['localhost:4321'], 'http://localhost:8080');

    expect($cleaner->rewriteHref('http://localhost:8080/cotizar'))->toBe('/cotizar/')
        ->and($cleaner->rewriteHref('http://localhost:4321/blog/x'))->toBe('/blog/x/')
        ->and($cleaner->rewriteHref('http://localhost/otra'))->toBe('http://localhost/otra');
});

it('removes dangerous markup but keeps allowlisted embeds', function (): void {
    $html = cleaner()->clean(
        '<p onclick="alert(1)" style="color:red">Hola <a href="javascript:alert(1)">x</a></p>'
        .'<script>alert(1)</script><iframe src="https://evil.example/x"></iframe>'
        .'<iframe src="https://www.youtube-nocookie.com/embed/abc"></iframe><form><input></form>'
    );

    expect($html)->not->toContain('onclick')
        ->not->toContain('style=')
        ->not->toContain('javascript:')
        ->not->toContain('<script')
        ->not->toContain('evil.example')
        ->not->toContain('<form')
        ->toContain('youtube-nocookie.com/embed/abc')
        ->toContain('loading="lazy"');
});

it('adds unique slug ids to h2/h3 and respects existing ids', function (): void {
    $html = cleaner()->clean('<h2 id="intro">Intro</h2><h2>¿Qué es un pozo séptico?</h2><h3>Qué es un pozo séptico</h3><h2>Intro</h2><h4>Sin id</h4>');

    expect($html)->toContain('<h2 id="intro">Intro</h2>')
        ->toContain('<h2 id="que-es-un-pozo-septico">')
        ->toContain('<h3 id="que-es-un-pozo-septico-2">')
        ->toContain('<h2 id="intro-2">Intro</h2>')
        ->toContain('<h4>Sin id</h4>');
});

it('keeps UTF-8 text, drops empty paragraphs and hardens images and blank targets', function (): void {
    $html = cleaner()->clean(
        '<p>Baños portátiles en Medellín — «guía»</p><p>&nbsp;</p><p><br></p>'
        .'<p><img src="/wp-content/uploads/a.webp" srcset="/wp-content/uploads/a.webp 1x, /wp-content/uploads/a@2x.webp 2x"></p>'
        .'<p><a href="https://www.funcionpublica.gov.co/" target="_blank">Norma</a></p>'
    );

    expect($html)->toContain('Baños portátiles en Medellín — «guía»')
        ->and(substr_count($html, '<p>'))->toBe(3)
        ->and($html)->toContain('src="https://api.banosportatiles.net/wp-content/uploads/a.webp"')
        ->toContain('https://api.banosportatiles.net/wp-content/uploads/a@2x.webp 2x')
        ->toContain('loading="lazy"')
        ->toContain('rel="noopener noreferrer"');
});

it('returns an empty string for empty or comment-only input', function (): void {
    expect(cleaner()->clean(''))->toBe('')
        ->and(cleaner()->clean("<!-- wp:paragraph -->\n<!-- /wp:paragraph -->"))->toBe('');
});
