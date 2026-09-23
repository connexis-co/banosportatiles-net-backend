<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Cache;

use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * Invalidates the API cache on every content change (cheap: one option update + one DELETE).
 */
final class CacheInvalidator implements Hookable
{
    public function __construct(private readonly ResponseCache $cache) {}

    public function register(): void
    {
        add_action(ContentChangeListener::ACTION, [$this->cache, 'flush'], 5, 0);
    }
}
