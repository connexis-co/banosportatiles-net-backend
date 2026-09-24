<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Reviews;

/**
 * Who sends a vote or review, as computed by the Astro Worker:
 * voter = hex(SHA-256(ip + "|" + ua + "|" + salt)) (deduplication per node), plus the visitor IP (limits and
 * blacklist of the reviews plugin), user agent and country for moderation.
 */
final readonly class VoterContext
{
    public function __construct(
        public string $voter,
        public string $ip,
        public string $ua = '',
        public string $country = '',
    ) {}
}
