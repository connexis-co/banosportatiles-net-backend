<?php

declare(strict_types=1);

use BanosPortatiles\SitioEnVenta\Support\PathRules;

it('normalizes the excluded paths textarea', function (): void {
    expect(PathRules::normalizeList("/cotizar/\ncotizar\n\n  /sitio-en-venta  \nhttps://banosportatiles.net/blog/*?x=1\n# comentario\n/legal//privacidad\n/archivo.pdf"))
        ->toBe(['/cotizar/', '/sitio-en-venta/', '/blog/*', '/legal/privacidad/', '/archivo.pdf']);
});

it('matches exact pages regardless of trailing slash, query or fragment', function (string $path, bool $expected): void {
    expect(PathRules::matches($path, ['/cotizar/', '/blog/*', '/archivo.pdf']))->toBe($expected);
})->with([
    ['/cotizar/', true],
    ['/cotizar', true],
    ['/cotizar/?utm_source=google#form', true],
    ['/cotizar/gracias/', false],
    ['/blog/', true],
    ['/blog/pozo-septico-guia/', true],
    ['/blogger/', false],
    ['/archivo.pdf', true],
    ['/', false],
]);

it('treats "/" as the home page only', function (): void {
    expect(PathRules::matches('/', ['/']))->toBeTrue()
        ->and(PathRules::matches('/alquiler/', ['/']))->toBeFalse()
        ->and(PathRules::matches('/alquiler/', ['/*']))->toBeTrue();
});
