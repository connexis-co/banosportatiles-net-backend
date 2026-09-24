<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Reviews;

/**
 * The reviews engine behind bp-headless (Site Reviews in production; NullReviewsGateway when it is not active,
 * in which case nodes omit "rating" and "reviews"). Every read and write of reviews goes through here.
 */
interface ReviewsGateway
{
    public function available(): bool;

    /**
     * Approved records (votes and text reviews) assigned to a post, newest first.
     *
     * @return list<ReviewRecord>
     */
    public function approved(int $postId): array;

    /** Unapproved (pending moderation) records assigned to a post. */
    public function pendingCount(int $postId): int;

    /** Newest record of this voter on this post created after $since (any status but trash). */
    public function findByVoter(int $postId, string $voter, int $since): ?ReviewRecord;

    /** Blacklist of the reviews engine: null (clean), "reject" or "unapprove" (keep it pending). */
    public function screen(VoterContext $voter, ?ReviewSubmission $review = null): ?string;

    /** Quick vote: rating only, category «Calificación». */
    public function createVote(int $postId, int $rating, VoterContext $voter, bool $approved = true): int|\WP_Error;

    /** Text review, category «Comentario». */
    public function createReview(int $postId, ReviewSubmission $review, VoterContext $voter, bool $approved): int|\WP_Error;

    /** Turns this voter's quick vote into a text review (new date, category «Comentario», moderation again). */
    public function upgradeVote(int $reviewId, ReviewSubmission $review, VoterContext $voter, bool $approved): true|\WP_Error;

    /**
     * Records of a voter and/or an IP, optionally only on one post (any status): WP-CLI purge.
     *
     * @return list<ReviewRecord>
     */
    public function find(?string $voter, ?string $ip, ?int $postId = null): array;

    public function delete(int $reviewId): bool;

    /** Admin list of the reviews engine filtered by the post. */
    public function adminUrl(int $postId): string;

    /** Forgets the per-request cache of a post (after a write). */
    public function forget(int $postId): void;
}
