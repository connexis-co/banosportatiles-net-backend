<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Contracts;

/**
 * A plugin module that attaches its callbacks to WordPress hooks.
 */
interface Hookable
{
    public function register(): void;
}
