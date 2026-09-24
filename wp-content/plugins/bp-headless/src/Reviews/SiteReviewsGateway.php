<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Reviews;

use GeminiLabs\SiteReviews\Review;

/**
 * Site Reviews 8.x behind ReviewsGateway, through its public PHP API (glsr_create_review, glsr_get_reviews,
 * glsr_get_review, glsr_update_review, glsr_get_option). bp-headless keeps its own meta on each review:
 * _bp_voter + _bp_post_id (deduplication), _bp_ua, _bp_country, _bp_consent_at and _bp_response_date.
 */
final class SiteReviewsGateway implements ReviewsGateway
{
    public const POST_TYPE = 'site-review';

    public const TAXONOMY = 'site-review-category';

    public const VOTE_TERM = 'calificacion';

    public const REVIEW_TERM = 'comentario';

    /** Categories of the reviews engine: quick votes vs. reviews with text. */
    public const TERMS = [self::VOTE_TERM => 'Calificación', self::REVIEW_TERM => 'Comentario'];

    public const META_VOTER = '_bp_voter';

    public const META_POST = '_bp_post_id';

    public const META_UA = '_bp_ua';

    public const META_COUNTRY = '_bp_country';

    public const META_CONSENT = '_bp_consent_at';

    public const META_RESPONSE_DATE = '_bp_response_date';

    private const STATUSES = ['publish', 'pending', 'draft', 'private', 'future'];

    /** @var array<int, list<ReviewRecord>> per-request cache of approved records by post */
    private array $approved = [];

    public function __construct(private readonly ReviewsWriteGuard $guard) {}

    public static function isActive(): bool
    {
        return function_exists('glsr_create_review') && function_exists('glsr_get_reviews')
            && function_exists('glsr_get_review') && function_exists('glsr_update_review') && function_exists('glsr_get_option');
    }

    public function available(): bool
    {
        return self::isActive();
    }

    public function approved(int $postId): array
    {
        return $this->approved[$postId] ??= $this->reviews(['assigned_posts' => [$postId], 'status' => 'approved']);
    }

    public function pendingCount(int $postId): int
    {
        return glsr_get_reviews(['assigned_posts' => [$postId], 'status' => 'unapproved', 'per_page' => 1])->total;
    }

    public function findByVoter(int $postId, string $voter, int $since): ?ReviewRecord
    {
        $ids = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => self::STATUSES,
            'fields' => 'ids',
            'numberposts' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
            'meta_query' => [
                'relation' => 'AND',
                ['key' => self::META_VOTER, 'value' => $voter],
                ['key' => self::META_POST, 'value' => (string) $postId],
            ],
            'date_query' => [['column' => 'post_date_gmt', 'after' => gmdate('Y-m-d H:i:s', $since), 'inclusive' => true]],
        ]);
        $id = $ids[0] ?? null;

        return is_int($id) ? $this->record($id) : null;
    }

    public function screen(VoterContext $voter, ?ReviewSubmission $review = null): ?string
    {
        $integration = glsr_get_option('forms.blacklist.integration', 'comments');
        $entries = $integration === 'comments' ? get_option('disallowed_keys', '') : glsr_get_option('forms.blacklist.entries', '');
        $target = implode("\n", array_filter([$review?->name, $review?->content, $review?->email, $voter->ip, $review?->title]));
        if (! Blacklist::matches(is_string($entries) ? $entries : '', $target)) {
            return null;
        }

        return glsr_get_option('forms.blacklist.action', 'unapprove') === 'reject' ? 'reject' : 'unapprove';
    }

    public function createVote(int $postId, int $rating, VoterContext $voter, bool $approved = true): int|\WP_Error
    {
        // Quick votes do not email the admin (they need no moderation); reviews with text do.
        $silence = static fn (): array => [];
        add_filter('site-reviews/option/general/notifications', $silence, 99);
        try {
            return $this->create($postId, [
                'rating' => $rating,
                'is_approved' => $approved,
                'assigned_terms' => $this->termIds([self::VOTE_TERM]),
            ], $voter, []);
        } finally {
            remove_filter('site-reviews/option/general/notifications', $silence, 99);
        }
    }

    public function createReview(int $postId, ReviewSubmission $review, VoterContext $voter, bool $approved): int|\WP_Error
    {
        return $this->create($postId, [
            'rating' => $review->rating,
            'title' => $review->title,
            'content' => $review->content,
            'name' => $review->name,
            'email' => $review->email,
            'terms' => true,
            'is_approved' => $approved,
            'assigned_terms' => $this->termIds([self::REVIEW_TERM]),
        ], $voter, [self::META_CONSENT => current_time('mysql', true)]);
    }

    public function upgradeVote(int $reviewId, ReviewSubmission $review, VoterContext $voter, bool $approved): true|\WP_Error
    {
        $previous = $this->record($reviewId);
        $terms = $this->termIds([self::REVIEW_TERM]);
        $updated = $this->guard->trusted(static fn (): Review|false => glsr_update_review($reviewId, [
            'rating' => $review->rating,
            'title' => $review->title,
            'content' => $review->content,
            'name' => $review->name,
            'email' => $review->email,
            'terms' => true,
            'ip_address' => $voter->ip,
            'is_approved' => $approved,
            'status' => $approved ? 'publish' : 'pending',
            'date' => current_time('mysql'),
            'date_gmt' => current_time('mysql', true),
            'assigned_terms' => $terms,
        ]));
        if (! $updated instanceof Review) {
            return new \WP_Error('bp_review_not_saved', 'No pudimos guardar la opinión.', ['status' => 500]);
        }

        $this->writeMeta($reviewId, $voter, [self::META_CONSENT => current_time('mysql', true)]);
        foreach ($previous !== null ? $previous->postIds : [] as $postId) {
            $this->forget($postId);
        }
        // Site Reviews only notifies on creation: queue its notification (moderation needed) like it does itself.
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('site-reviews/queue/notification', ['review_id' => $reviewId], 'site-reviews');
        }

        return true;
    }

    public function find(?string $voter, ?string $ip, ?int $postId = null): array
    {
        $ids = null;
        if ($voter !== null && $voter !== '') {
            $meta = [['key' => self::META_VOTER, 'value' => strtolower($voter)]];
            if ($postId !== null) {
                $meta[] = ['key' => self::META_POST, 'value' => (string) $postId];
            }
            $ids = array_values(array_filter(get_posts([
                'post_type' => self::POST_TYPE,
                'post_status' => self::STATUSES,
                'fields' => 'ids',
                'numberposts' => -1,
                'no_found_rows' => true,
                'meta_query' => ['relation' => 'AND', ...$meta],
            ]), 'is_int'));
        }
        if ($ip !== null && $ip !== '') {
            $args = ['ip_address' => $ip, 'status' => 'all', 'per_page' => -1];
            if ($postId !== null) {
                $args['assigned_posts'] = [$postId];
            }
            $byIp = array_map(static fn (Review $review): int => (int) $review->ID, glsr_get_reviews($args)->reviews);
            $ids = $ids === null ? $byIp : array_values(array_intersect($ids, $byIp));
        }

        return array_values(array_filter(array_map($this->record(...), $ids ?? [])));
    }

    public function delete(int $reviewId): bool
    {
        $record = $this->record($reviewId);
        if ($record === null) {
            return false;
        }
        foreach ($record->postIds as $postId) {
            $this->forget($postId);
        }

        return wp_delete_post($reviewId, true) instanceof \WP_Post;
    }

    public function adminUrl(int $postId): string
    {
        return admin_url('edit.php?post_type='.self::POST_TYPE.'&assigned_post='.$postId);
    }

    public function forget(int $postId): void
    {
        unset($this->approved[$postId]);
    }

    /**
     * Ids of the categories «Calificación» and «Comentario», created when missing (also by `wp bp setup reviews`).
     *
     * @param  list<string>  $slugs
     * @return list<int>
     */
    public function termIds(array $slugs): array
    {
        $ids = [];
        foreach ($slugs as $slug) {
            $term = get_term_by('slug', $slug, self::TAXONOMY);
            if ($term instanceof \WP_Term) {
                $ids[] = $term->term_id;

                continue;
            }
            $created = wp_insert_term(self::TERMS[$slug] ?? $slug, self::TAXONOMY, ['slug' => $slug]);
            if (! is_wp_error($created)) {
                $ids[] = (int) $created['term_id'];
            }
        }

        return $ids;
    }

    public function record(int $reviewId): ?ReviewRecord
    {
        if ($reviewId <= 0 || get_post_type($reviewId) !== self::POST_TYPE) {
            return null;
        }
        $review = glsr_get_review($reviewId);

        return $review->isValid() ? $this->map($review) : null;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $meta
     */
    private function create(int $postId, array $values, VoterContext $voter, array $meta): int|\WP_Error
    {
        $values += ['assigned_posts' => [$postId], 'ip_address' => $voter->ip, 'type' => 'local'];
        $review = $this->guard->trusted(fn (): Review|false => $this->withoutDuplicateCheck(static fn (): Review|false => glsr_create_review($values)));
        if (! $review instanceof Review || ! $review->isValid()) {
            return new \WP_Error('bp_review_not_saved', 'No pudimos registrar la valoración.', ['status' => 500]);
        }

        $id = (int) $review->ID;
        $this->writeMeta($id, $voter, [self::META_POST => (string) $postId] + $meta);
        $this->forget($postId);

        return $id;
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function writeMeta(int $reviewId, VoterContext $voter, array $extra): void
    {
        $meta = [self::META_VOTER => $voter->voter, self::META_UA => $voter->ua, self::META_COUNTRY => $voter->country] + $extra;
        foreach ($meta as $key => $value) {
            $value !== '' ? update_post_meta($reviewId, $key, $value) : delete_post_meta($reviewId, $key);
        }
    }

    /**
     * bp-headless deduplicates by voter; Site Reviews' own duplicate check (same content + email) would reject
     * every second quick vote of a page, since votes have neither.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withoutDuplicateCheck(callable $callback): mixed
    {
        $filter = static fn (mixed $validators): array => array_values(array_filter(
            is_array($validators) ? $validators : [],
            static fn (mixed $validator): bool => ! (is_string($validator) && str_ends_with($validator, '\\DuplicateValidator'))
        ));
        add_filter('site-reviews/validators', $filter, 99);
        try {
            return $callback();
        } finally {
            remove_filter('site-reviews/validators', $filter, 99);
        }
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<ReviewRecord>
     */
    private function reviews(array $args): array
    {
        $records = [];
        foreach (glsr_get_reviews($args + ['per_page' => -1, 'orderby' => 'date', 'order' => 'desc'])->reviews as $review) {
            $record = $this->map($review);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    private function map(Review $review): ?ReviewRecord
    {
        $id = (int) $review->ID;
        if ($id <= 0) {
            return null;
        }

        return new ReviewRecord(
            id: $id,
            rating: (int) $review->rating,
            author: (string) $review->author,
            title: (string) $review->title,
            content: (string) $review->content,
            approved: (bool) $review->is_approved,
            date: self::gmtDate((string) $review->date_gmt) ?? self::localDate((string) $review->date),
            response: (string) $review->response,
            responseDate: self::gmtDate((string) get_post_meta($id, self::META_RESPONSE_DATE, true)),
            voter: (string) get_post_meta($id, self::META_VOTER, true),
            postIds: array_values(array_map('intval', (array) $review->assigned_posts)),
            ip: (string) $review->ip_address,
        );
    }

    private static function gmtDate(string $value): ?\DateTimeImmutable
    {
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));

        return $date === false ? null : $date->setTimezone(wp_timezone());
    }

    private static function localDate(string $value): ?\DateTimeImmutable
    {
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, wp_timezone());

        return $date === false ? null : $date;
    }
}
