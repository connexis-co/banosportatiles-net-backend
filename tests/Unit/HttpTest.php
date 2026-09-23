<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Rest\Cors;
use BanosPortatiles\Headless\Rest\HttpCache;

it('matches If-None-Match against strong and weak ETags', function (): void {
    $etag = '"abc123"';

    expect(HttpCache::matches('"abc123"', $etag))->toBeTrue()
        ->and(HttpCache::matches('W/"abc123"', $etag))->toBeTrue()
        ->and(HttpCache::matches('"x", "abc123"', $etag))->toBeTrue()
        ->and(HttpCache::matches('*', $etag))->toBeTrue()
        ->and(HttpCache::matches('"otro"', $etag))->toBeFalse()
        ->and(HttpCache::matches(null, $etag))->toBeFalse();
});

it('allows CORS only for allowlisted origins', function (): void {
    $allowed = ['https://banosportatiles.net', 'http://localhost:4321'];

    expect(Cors::isAllowed('https://banosportatiles.net', $allowed))->toBeTrue()
        ->and(Cors::isAllowed('https://BanosPortatiles.net', $allowed))->toBeTrue()
        ->and(Cors::isAllowed('http://localhost:4321', $allowed))->toBeTrue()
        ->and(Cors::isAllowed('http://localhost:3000', $allowed))->toBeFalse()
        ->and(Cors::isAllowed('https://banosportatiles.net.evil.com', $allowed))->toBeFalse()
        ->and(Cors::isAllowed('null', $allowed))->toBeFalse();
});

it('extracts origins', function (): void {
    expect(Config::originOf('https://BanosPortatiles.net/path?x=1'))->toBe('https://banosportatiles.net')
        ->and(Config::originOf('http://localhost:4321/'))->toBe('http://localhost:4321')
        ->and(Config::originOf('no-es-url'))->toBeNull();
});
