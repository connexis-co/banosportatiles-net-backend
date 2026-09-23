<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Leads;

final readonly class RateLimitResult
{
    public function __construct(public bool $allowed, public int $remaining, public int $retryAfter) {}
}
