<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Reviews;

/**
 * What a node shows: the star widget (quick votes) and/or text reviews with a rating.
 */
final readonly class RatingFlags
{
    public function __construct(
        public bool $stars,
        public bool $reviews,
    ) {}

    public static function off(): self
    {
        return new self(false, false);
    }

    public function any(): bool
    {
        return $this->stars || $this->reviews;
    }
}
