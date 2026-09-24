<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Reviews;

/**
 * Result of validating a POST /ratings or /reviews payload: sanitized values or errors per field (Spanish).
 */
final readonly class ReviewValidation
{
    /**
     * @param  array<string, string>  $errors
     */
    public function __construct(
        public array $errors,
        public string $uri = '',
        public int $rating = 0,
        public ?VoterContext $voter = null,
        public ?ReviewSubmission $submission = null,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [] && $this->uri !== '' && $this->rating > 0 && $this->voter !== null;
    }
}
