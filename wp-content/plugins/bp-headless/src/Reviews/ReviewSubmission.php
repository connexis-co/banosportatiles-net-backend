<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Reviews;

/**
 * A validated text review (POST /reviews). Consent (Ley 1581) was checked by the validator.
 */
final readonly class ReviewSubmission
{
    public function __construct(
        public int $rating,
        public string $title,
        public string $content,
        public string $name,
        public string $email,
    ) {}
}
