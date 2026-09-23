<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Routing;

/**
 * Public URIs of the Astro front (always relative, with leading and trailing slash) ⇄ WordPress objects.
 *
 *   page    → /{ancestors}/{slug}/ (front page → /)
 *   post    → /blog/{slug}/
 *   equipo  → /equipos/{slug}/
 *   category→ /blog/tema/{slug}/
 */
final class UriResolver
{
    public const POST_BASE = 'blog';

    public const EQUIPO_BASE = 'equipos';

    public const CATEGORY_BASE = 'blog/tema';

    public const NODE_TYPES = ['page', 'post', 'equipo'];

    private const DRAFT_STATUSES = ['publish', 'draft', 'pending', 'future', 'private'];

    /** Normalizes any path or URL to "/a/b/" (no host, query or fragment; collapsed slashes). */
    public static function normalize(string $uri): string
    {
        $uri = trim($uri);
        // Only full URLs are parsed; anything else is a path ("//a//b" must not become host "a").
        $path = preg_match('#^[a-z][a-z0-9+.\-]*://#i', $uri) === 1
            ? (string) (parse_url($uri, PHP_URL_PATH) ?? '')
            : (string) preg_replace('/[?#].*$/s', '', $uri);
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== '' && $s !== '.' && $s !== '..'));

        return $segments === [] ? '/' : '/'.implode('/', $segments).'/';
    }

    /**
     * Pure classification of a public URI.
     *
     * @return array{kind: 'front'|'post'|'equipo'|'category'|'page'|'none', slug: string}
     */
    public static function classify(string $uri): array
    {
        $normalized = self::normalize($uri);
        if ($normalized === '/') {
            return ['kind' => 'front', 'slug' => ''];
        }

        $segments = explode('/', trim($normalized, '/'));
        $count = count($segments);

        if ($segments[0] === self::POST_BASE && $count === 3 && $segments[1] === 'tema') {
            return ['kind' => 'category', 'slug' => $segments[2]];
        }
        if ($segments[0] === self::POST_BASE && $count === 2) {
            return ['kind' => 'post', 'slug' => $segments[1]];
        }
        if ($segments[0] === self::EQUIPO_BASE && $count === 2) {
            return ['kind' => 'equipo', 'slug' => $segments[1]];
        }
        if (($segments[0] === self::POST_BASE || $segments[0] === self::EQUIPO_BASE) && $count > 2) {
            return ['kind' => 'none', 'slug' => ''];
        }

        return ['kind' => 'page', 'slug' => implode('/', $segments)];
    }

    /**
     * Public URI of a post. Drafts without a slug get one from their title when $allowDraftSlug is true.
     */
    public function forPost(\WP_Post|int $post, bool $allowDraftSlug = false): ?string
    {
        $post = get_post($post);
        if (! $post instanceof \WP_Post) {
            return null;
        }

        $slug = self::slugOf($post, $allowDraftSlug);

        return match ($post->post_type) {
            'page' => $this->pageUri($post, $allowDraftSlug),
            'post' => $slug !== '' ? '/'.self::POST_BASE.'/'.$slug.'/' : null,
            'equipo' => $slug !== '' ? '/'.self::EQUIPO_BASE.'/'.$slug.'/' : null,
            default => null,
        };
    }

    public function forTerm(\WP_Term $term): ?string
    {
        return $term->taxonomy === 'category' ? '/'.self::CATEGORY_BASE.'/'.$term->slug.'/' : null;
    }

    /** Finds the WordPress object behind a public URI (published only unless $includeDrafts). */
    public function resolve(string $uri, bool $includeDrafts = false): ?\WP_Post
    {
        $statuses = $includeDrafts ? self::DRAFT_STATUSES : ['publish'];
        ['kind' => $kind, 'slug' => $slug] = self::classify($uri);

        $post = match ($kind) {
            'front' => $this->frontPage(),
            'post', 'equipo' => $this->findByName($kind, $slug, $statuses),
            'page' => get_page_by_path($slug, OBJECT, 'page'),
            default => null,
        };

        return ($post instanceof \WP_Post && in_array($post->post_status, $statuses, true)) ? $post : null;
    }

    public function frontPageId(): int
    {
        return get_option('show_on_front') === 'page' ? (int) get_option('page_on_front') : 0;
    }

    private function frontPage(): ?\WP_Post
    {
        $id = $this->frontPageId();
        $post = $id > 0 ? get_post($id) : null;

        return $post instanceof \WP_Post ? $post : null;
    }

    private function pageUri(\WP_Post $page, bool $allowDraftSlug): ?string
    {
        if ($page->ID === $this->frontPageId()) {
            return '/';
        }

        $segments = [];
        foreach (array_reverse(get_post_ancestors($page)) as $ancestorId) {
            $ancestor = get_post($ancestorId);
            if ($ancestor instanceof \WP_Post && $ancestor->ID !== $this->frontPageId()) {
                $segments[] = self::slugOf($ancestor, $allowDraftSlug);
            }
        }
        $segments[] = self::slugOf($page, $allowDraftSlug);
        $segments = array_filter($segments, static fn (string $s): bool => $s !== '');

        return $segments === [] ? null : '/'.implode('/', $segments).'/';
    }

    /**
     * @param  list<string>  $statuses
     */
    private function findByName(string $type, string $slug, array $statuses): ?\WP_Post
    {
        $posts = get_posts([
            'name' => $slug,
            'post_type' => $type,
            'post_status' => $statuses,
            'numberposts' => 1,
            'no_found_rows' => true,
        ]);
        $post = $posts[0] ?? null;

        return $post instanceof \WP_Post ? $post : null;
    }

    private static function slugOf(\WP_Post $post, bool $allowDraftSlug): string
    {
        if ($post->post_name !== '') {
            return $post->post_name;
        }

        return $allowDraftSlug ? sanitize_title($post->post_title !== '' ? $post->post_title : (string) $post->ID) : '';
    }
}
