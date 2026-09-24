<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Reviews;

/**
 * Site Reviews is not active: no ratings anywhere (nodes omit "rating"/"reviews", /site → ratings.enabled = false)
 * and the write endpoints answer 503.
 */
final class NullReviewsGateway implements ReviewsGateway
{
    public function available(): bool
    {
        return false;
    }

    public function approved(int $postId): array
    {
        return [];
    }

    public function pendingCount(int $postId): int
    {
        return 0;
    }

    public function findByVoter(int $postId, string $voter, int $since): ?ReviewRecord
    {
        return null;
    }

    public function screen(VoterContext $voter, ?ReviewSubmission $review = null): ?string
    {
        return null;
    }

    public function createVote(int $postId, int $rating, VoterContext $voter, bool $approved = true): \WP_Error
    {
        return self::unavailable();
    }

    public function createReview(int $postId, ReviewSubmission $review, VoterContext $voter, bool $approved): \WP_Error
    {
        return self::unavailable();
    }

    public function upgradeVote(int $reviewId, ReviewSubmission $review, VoterContext $voter, bool $approved): \WP_Error
    {
        return self::unavailable();
    }

    public function find(?string $voter, ?string $ip, ?int $postId = null): array
    {
        return [];
    }

    public function delete(int $reviewId): bool
    {
        return false;
    }

    public function adminUrl(int $postId): string
    {
        return '';
    }

    public function forget(int $postId): void {}

    private static function unavailable(): \WP_Error
    {
        return new \WP_Error('reviews_unavailable', 'Las valoraciones no están disponibles (plugin Site Reviews inactivo).', ['status' => 503]);
    }
}
