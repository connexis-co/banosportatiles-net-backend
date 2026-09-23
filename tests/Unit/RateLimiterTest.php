<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Leads\RateLimiter;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    $store = [];
    Functions\when('get_transient')->alias(function (string $key) use (&$store): mixed {
        return $store[$key] ?? false;
    });
    Functions\when('set_transient')->alias(function (string $key, mixed $value) use (&$store): bool {
        $store[$key] = $value;

        return true;
    });
});

it('allows up to the limit inside a window and then blocks', function (): void {
    $limiter = new RateLimiter;

    foreach ([4, 3, 2, 1, 0] as $remaining) {
        $result = $limiter->hit('leads', '203.0.113.9', 5, 600, 1000);
        expect($result->allowed)->toBeTrue()->and($result->remaining)->toBe($remaining);
    }

    $blocked = $limiter->hit('leads', '203.0.113.9', 5, 600, 1100);
    expect($blocked->allowed)->toBeFalse()
        ->and($blocked->retryAfter)->toBe(500)
        ->and($limiter->tooMany('leads', '203.0.113.9', 5, 1100))->toBeTrue()
        ->and($limiter->hit('leads', '198.51.100.7', 5, 600, 1100)->allowed)->toBeTrue();
});

it('starts a new window after the reset time', function (): void {
    $limiter = new RateLimiter;
    $limiter->hit('leads', 'ip', 1, 600, 1000);

    expect($limiter->hit('leads', 'ip', 1, 600, 1500)->allowed)->toBeFalse()
        ->and($limiter->hit('leads', 'ip', 1, 600, 1601)->allowed)->toBeTrue();
});
