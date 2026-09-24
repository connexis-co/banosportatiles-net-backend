<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Reviews;

use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * The only public way to create ratings and reviews is POST /bp/v1/ratings|reviews (HMAC, from the Worker).
 * Every public write path of Site Reviews is closed:
 *
 * 1. REST: /site-reviews/v1/submissions is removed; any other non-read request to /site-reviews/* needs an
 *    editor (edit_posts). Authenticated reads keep their own permissions.
 * 2. Its form router (admin-ajax "glsr_public_action" and the no-JS POST handled on "init"): the
 *    "submit-review" route answers 403 before Site Reviews handles it.
 * 3. Last line: a new "site-review" post can only be inserted by bp-headless (trusted()), WP-CLI or an editor.
 *
 * Also headless housekeeping: no front assets or JSON-LD from Site Reviews and no Gravatar lookups.
 */
final class ReviewsWriteGuard implements Hookable
{
    public const POST_TYPE = 'site-review';

    public const REST_PREFIX = '/site-reviews/';

    private int $trusted = 0;

    public function register(): void
    {
        add_filter('rest_endpoints', [$this, 'removeEndpoints']);
        // After SCF's ACF_Rest_Api::initialize (priority 10), which returns null and would discard an earlier error.
        add_filter('rest_pre_dispatch', [$this, 'guardRest'], 20, 3);
        add_action('site-reviews/route/request', [$this, 'guardRoute'], 0, 2);
        add_filter('wp_insert_post_empty_content', [$this, 'guardInsert'], 99, 2);

        add_filter('site-reviews/assets/css', '__return_false');
        add_filter('site-reviews/assets/js', '__return_false');
        add_filter('site-reviews/schema/all', '__return_empty_array');
        // An empty avatar URL makes Site Reviews use its local fallback instead of checking Gravatar over HTTP.
        add_filter('site-reviews/avatar/generate', '__return_empty_string');
    }

    /**
     * Runs a write of bp-headless itself (the signed endpoints).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function trusted(callable $callback): mixed
    {
        $this->trusted++;
        try {
            return $callback();
        } finally {
            $this->trusted--;
        }
    }

    /**
     * @param  array<string, mixed>  $endpoints
     * @return array<string, mixed>
     */
    public function removeEndpoints(array $endpoints): array
    {
        foreach (array_keys($endpoints) as $route) {
            if (str_starts_with($route, self::REST_PREFIX) && str_contains($route, '/submissions')) {
                unset($endpoints[$route]);
            }
        }

        return $endpoints;
    }

    public function guardRest(mixed $result, mixed $server, mixed $request): mixed
    {
        if (! $request instanceof \WP_REST_Request || ! self::isWrite($request->get_route(), $request->get_method())) {
            return $result;
        }

        return current_user_can('edit_posts')
            ? $result
            : new \WP_Error('bp_reviews_readonly', 'Las valoraciones solo se reciben por la API del sitio.', ['status' => 403]);
    }

    public static function isWrite(string $route, string $method): bool
    {
        return str_starts_with($route, self::REST_PREFIX) && ! in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public function guardRoute(mixed $request, mixed $hook): void
    {
        if (! is_string($hook) || ! str_ends_with($hook, '/submit-review')) {
            return;
        }
        $message = 'Las opiniones se envían desde el sitio público.';
        if (wp_doing_ajax()) {
            wp_send_json_error(['code' => 403, 'message' => $message], 403);
        }
        wp_die(esc_html($message), 'Formulario deshabilitado', ['response' => 403]);
    }

    public function guardInsert(mixed $maybeEmpty, mixed $postarr): mixed
    {
        if (! is_array($postarr) || ($postarr['post_type'] ?? '') !== self::POST_TYPE || ! empty($postarr['ID'])) {
            return $maybeEmpty;
        }

        return $this->allowed() ? $maybeEmpty : true;
    }

    private function allowed(): bool
    {
        return $this->trusted > 0 || (defined('WP_CLI') && WP_CLI) || current_user_can('edit_posts');
    }
}
