<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Reviews;

/**
 * A rating or review as bp-headless sees it, independent of the reviews plugin behind the gateway.
 * A quick vote is a record without text; a review has text (and a name). The email never leaves the gateway.
 */
final readonly class ReviewRecord
{
    /**
     * @param  list<int>  $postIds  Posts the review is assigned to.
     */
    public function __construct(
        public int $id,
        public int $rating,
        public string $author,
        public string $title,
        public string $content,
        public bool $approved,
        public ?\DateTimeImmutable $date,
        public string $response = '',
        public ?\DateTimeImmutable $responseDate = null,
        public string $voter = '',
        public array $postIds = [],
        public string $ip = '',
    ) {}

    public function hasText(): bool
    {
        return trim($this->content) !== '';
    }

    public function hasValidRating(): bool
    {
        return $this->rating >= RatingSummary::WORST && $this->rating <= RatingSummary::BEST;
    }
}
