<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Headless\FrontendRedirect;
use BanosPortatiles\Headless\Headless\PreviewLinks;
use BanosPortatiles\Headless\Routing\UriResolver;
use Brain\Monkey\Functions;

function frontendRedirect(): FrontendRedirect
{
    $config = new Config;

    return new FrontendRedirect($config, new UriResolver, new PreviewLinks($config, new UriResolver, new BanosPortatiles\Headless\Security\PreviewToken('secreto-de-prueba')));
}

beforeEach(function (): void {
    Functions\stubs([
        'admin_url' => 'https://admin.banosportatiles.net/wp-admin/',
        'get_option' => static fn (string $name, mixed $default = false): mixed => $default,
        'get_queried_object' => static fn (): mixed => null,
        'is_preview' => false,
        'is_singular' => false,
        'is_category' => false,
    ]);
});

it('sends the bare CMS host to the admin with a 302', function (string $uri): void {
    expect(frontendRedirect()->target($uri))->toBe(['https://admin.banosportatiles.net/wp-admin/', 302]);
})->with(['/', '/?']);

it('keeps the 301 to the public site for every other front request', function (string $uri): void {
    expect(frontendRedirect()->target($uri))->toBe(['https://banosportatiles.net'.$uri, 301]);
})->with(['/alquiler-de-banos-portatiles/', '/?s=pozos', '/?p=12', '/feed/', '/pagina-vieja/?utm_source=x']);

it('recognizes only the bare root', function (): void {
    expect(FrontendRedirect::isRoot('/'))->toBeTrue()
        ->and(FrontendRedirect::isRoot('/?'))->toBeTrue()
        ->and(FrontendRedirect::isRoot('/?preview=true&p=3'))->toBeFalse()
        ->and(FrontendRedirect::isRoot('/wp-admin/'))->toBeFalse()
        ->and(FrontendRedirect::isRoot('//'))->toBeFalse();
});
