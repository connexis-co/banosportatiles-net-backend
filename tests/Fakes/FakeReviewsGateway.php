<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Tests\Fakes;

use BanosPortatiles\Headless\Reviews\ReviewRecord;
use BanosPortatiles\Headless\Reviews\ReviewsGateway;
use BanosPortatiles\Headless\Reviews\ReviewSubmission;
use BanosPortatiles\Headless\Reviews\VoterContext;

/**
 * In-memory reviews engine for the endpoint tests. Test data only (never seeded anywhere).
 */
final class FakeReviewsGateway implements ReviewsGateway
{
    /** @var array<int, ReviewRecord> */
    public array $records = [];

    /** @var list<string> */
    public array $calls = [];

    public ?string $screenResult = null;

    private int $nextId = 500;

    public function __construct(public bool $isAvailable = true) {}

    public function available(): bool
    {
        return $this->isAvailable;
    }

    public function approved(int $postId): array
    {
        $approved = array_values(array_filter($this->records, static fn (ReviewRecord $r): bool => $r->approved && in_array($postId, $r->postIds, true)));
        usort($approved, static fn (ReviewRecord $a, ReviewRecord $b): int => ($b->date?->getTimestamp() ?? 0) <=> ($a->date?->getTimestamp() ?? 0));

        return $approved;
    }

    public function pendingCount(int $postId): int
    {
        return count(array_filter($this->records, static fn (ReviewRecord $r): bool => ! $r->approved && in_array($postId, $r->postIds, true)));
    }

    public function findByVoter(int $postId, string $voter, int $since): ?ReviewRecord
    {
        foreach ($this->records as $record) {
            if ($record->voter === $voter && in_array($postId, $record->postIds, true) && ($record->date?->getTimestamp() ?? 0) >= $since) {
                return $record;
            }
        }

        return null;
    }

    public function screen(VoterContext $voter, ?ReviewSubmission $review = null): ?string
    {
        return $this->screenResult;
    }

    public function createVote(int $postId, int $rating, VoterContext $voter, bool $approved = true): int|\WP_Error
    {
        $this->calls[] = 'createVote';

        return $this->store(new ReviewRecord($this->nextId++, $rating, '', '', '', $approved, new \DateTimeImmutable, voter: $voter->voter, postIds: [$postId], ip: $voter->ip));
    }

    public function createReview(int $postId, ReviewSubmission $review, VoterContext $voter, bool $approved): int|\WP_Error
    {
        $this->calls[] = 'createReview';

        return $this->store(new ReviewRecord($this->nextId++, $review->rating, $review->name, $review->title, $review->content, $approved, new \DateTimeImmutable, voter: $voter->voter, postIds: [$postId], ip: $voter->ip));
    }

    public function upgradeVote(int $reviewId, ReviewSubmission $review, VoterContext $voter, bool $approved): true|\WP_Error
    {
        $this->calls[] = 'upgradeVote';
        $old = $this->records[$reviewId];
        $this->records[$reviewId] = new ReviewRecord($reviewId, $review->rating, $review->name, $review->title, $review->content, $approved, new \DateTimeImmutable, voter: $old->voter, postIds: $old->postIds, ip: $voter->ip);

        return true;
    }

    public function find(?string $voter, ?string $ip, ?int $postId = null): array
    {
        return array_values(array_filter($this->records, static fn (ReviewRecord $r): bool => ($voter === null || $r->voter === $voter)
            && ($ip === null || $r->ip === $ip)
            && ($postId === null || in_array($postId, $r->postIds, true))));
    }

    public function delete(int $reviewId): bool
    {
        $exists = isset($this->records[$reviewId]);
        unset($this->records[$reviewId]);

        return $exists;
    }

    public function adminUrl(int $postId): string
    {
        return 'https://cms.test/wp-admin/edit.php?post_type=site-review&assigned_post='.$postId;
    }

    public function forget(int $postId): void {}

    public function add(ReviewRecord $record): void
    {
        $this->records[$record->id] = $record;
    }

    private function store(ReviewRecord $record): int
    {
        $this->records[$record->id] = $record;

        return $record->id;
    }
}
