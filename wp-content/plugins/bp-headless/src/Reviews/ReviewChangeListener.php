<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Reviews;

use BanosPortatiles\Headless\Cache\ResponseCache;
use BanosPortatiles\Headless\Contracts\Hookable;
use BanosPortatiles\Headless\Deploy\DeployScheduler;
use GeminiLabs\SiteReviews\Review;

/**
 * Any change to a review (created, approved, unapproved, edited, deleted or answered) invalidates the API cache
 * once per request. When the change is visible on the public site (the review is or was approved) it also asks
 * for a deploy with the 15-minute window of DeployScheduler::REVIEWS_DELAY, so the JSON-LD catches up.
 * A new review waiting for moderation does not rebuild the site: its approval will.
 */
final class ReviewChangeListener implements Hookable
{
    private bool $dirty = false;

    public function __construct(
        private readonly ResponseCache $cache,
        private readonly DeployScheduler $scheduler,
    ) {}

    public function register(): void
    {
        add_action('site-reviews/review/created', [$this, 'onCreated'], 20, 1);
        add_action('site-reviews/review/updated', [$this, 'onUpdated'], 20, 3);
        add_action('site-reviews/review/responded', [$this, 'onResponded'], 20, 2);
        add_action('transition_post_status', [$this, 'onTransition'], 20, 3);
        add_action('post_updated', [$this, 'onPostUpdated'], 20, 3);
        add_action('before_delete_post', [$this, 'onDelete'], 20, 2);
        // Before DeployScheduler::flush (priority 10), which turns the collected reasons into one cron event.
        add_action('shutdown', [$this, 'flush'], 5);
    }

    public function onCreated(mixed $review): void
    {
        if ($review instanceof Review) {
            $this->changed('reseña '.$review->ID.' creada', (bool) $review->is_approved);
        }
    }

    public function onUpdated(mixed $review, mixed $data = [], mixed $before = null): void
    {
        if ($review instanceof Review) {
            $wasPublic = $before instanceof \WP_Post && $before->post_status === 'publish';
            $this->changed('reseña '.$review->ID.' editada', (bool) $review->is_approved || $wasPublic);
        }
    }

    public function onResponded(mixed $review, mixed $response = ''): void
    {
        if (! $review instanceof Review) {
            return;
        }
        $id = (int) $review->ID;
        is_string($response) && trim($response) !== ''
            ? update_post_meta($id, SiteReviewsGateway::META_RESPONSE_DATE, current_time('mysql', true))
            : delete_post_meta($id, SiteReviewsGateway::META_RESPONSE_DATE);
        $this->changed('respuesta a la reseña '.$id, (bool) $review->is_approved);
    }

    public function onTransition(mixed $new, mixed $old, mixed $post): void
    {
        if ($post instanceof \WP_Post && $post->post_type === ReviewsWriteGuard::POST_TYPE && $new !== $old) {
            $this->changed('reseña '.$post->ID.': '.(is_string($old) ? $old : '?').' → '.(is_string($new) ? $new : '?'), $new === 'publish' || $old === 'publish');
        }
    }

    public function onPostUpdated(mixed $postId, mixed $after, mixed $before): void
    {
        if ($after instanceof \WP_Post && $after->post_type === ReviewsWriteGuard::POST_TYPE) {
            $wasPublic = $before instanceof \WP_Post && $before->post_status === 'publish';
            $this->changed('reseña '.$after->ID.' actualizada', $after->post_status === 'publish' || $wasPublic);
        }
    }

    public function onDelete(mixed $postId, mixed $post = null): void
    {
        if ($post instanceof \WP_Post && $post->post_type === ReviewsWriteGuard::POST_TYPE) {
            $this->changed('reseña '.$post->ID.' borrada', $post->post_status === 'publish');
        }
    }

    public function changed(string $reason, bool $public): void
    {
        $this->dirty = true;
        if ($public) {
            $this->scheduler->markDirty($reason, DeployScheduler::REVIEWS_DELAY);
        }
    }

    public function flush(): void
    {
        if ($this->dirty) {
            $this->dirty = false;
            $this->cache->flush();
        }
    }
}
