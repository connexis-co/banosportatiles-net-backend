<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Cache;

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Content\PostTypes;
use BanosPortatiles\Headless\Content\Taxonomies;
use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * Detects changes that affect the public site and emits "bp_headless/content_changed" (reason).
 * Subscribers: API cache invalidation and the deploy-hook scheduler.
 */
final class ContentChangeListener implements Hookable
{
    public const ACTION = 'bp_headless/content_changed';

    public const POST_TYPES = ['page', 'post', PostTypes::EQUIPO, PostTypes::FAQ];

    public const TAXONOMIES = [Taxonomies::CIUDAD, Taxonomies::TEMA_FAQ, 'category'];

    private bool $muted = false;

    public function register(): void
    {
        add_action('wp_after_insert_post', [$this, 'onPostSaved'], 20, 4);
        add_action('acf/save_post', [$this, 'onFieldsSaved'], 20);
        add_action('before_delete_post', [$this, 'onPostRemoved'], 10, 2);
        add_action('wp_trash_post', [$this, 'onPostRemoved'], 10, 1);
        foreach (['created_term', 'edited_term', 'delete_term'] as $hook) {
            add_action($hook, [$this, 'onTermChanged'], 20, 3);
        }
        add_action('redirection_redirect_updated', function (): void {
            $this->emit('redirects');
        });
        add_action('redirection_redirect_deleted', function (): void {
            $this->emit('redirects');
        });
        foreach (['show_on_front', 'page_on_front', 'blogname'] as $option) {
            add_action('update_option_'.$option, function () use ($option): void {
                $this->emit('settings:'.$option);
            });
        }
    }

    /** Silences events (bulk imports emit a single change at the end). */
    public function mute(bool $muted = true): void
    {
        $this->muted = $muted;
    }

    public function emit(string $reason): void
    {
        if (! $this->muted) {
            do_action(self::ACTION, $reason);
        }
    }

    public function onPostSaved(int $postId, \WP_Post $post, bool $update, ?\WP_Post $before): void
    {
        if (! self::isTracked($post)) {
            return;
        }
        if ($post->post_status === 'publish' || $before?->post_status === 'publish') {
            $this->emit($post->post_type.':'.$postId);
        }
    }

    public function onFieldsSaved(int|string $postId): void
    {
        if ($postId === Config::OPTIONS_ID) {
            $this->emit('options');

            return;
        }
        if (is_string($postId) && str_starts_with($postId, 'term_')) {
            $this->emit('term:'.substr($postId, 5));

            return;
        }
        $post = get_post((int) $postId);
        if ($post instanceof \WP_Post && self::isTracked($post) && $post->post_status === 'publish') {
            $this->emit($post->post_type.':'.$post->ID.':fields');
        }
    }

    public function onPostRemoved(int $postId, ?\WP_Post $post = null): void
    {
        $post ??= get_post($postId);
        if ($post instanceof \WP_Post && self::isTracked($post) && $post->post_status === 'publish') {
            $this->emit($post->post_type.':'.$postId.':removed');
        }
    }

    public function onTermChanged(int $termId, int $ttId, string $taxonomy): void
    {
        if (in_array($taxonomy, self::TAXONOMIES, true)) {
            $this->emit($taxonomy.':'.$termId);
        }
    }

    private static function isTracked(\WP_Post $post): bool
    {
        return in_array($post->post_type, self::POST_TYPES, true) && ! wp_is_post_revision($post) && ! wp_is_post_autosave($post);
    }
}
