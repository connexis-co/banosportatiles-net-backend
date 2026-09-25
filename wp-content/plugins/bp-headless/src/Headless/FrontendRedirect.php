<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Headless;

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Contracts\Hookable;
use BanosPortatiles\Headless\Routing\UriResolver;

/**
 * The CMS host has no public front. Its root (admin.banosportatiles.net/, without query) goes to the admin (302;
 * WordPress sends visitors without a session to the login), so editors land where they work. Every other
 * template request is redirected (301) to BP_FRONTEND_URL: singular content and categories map to their public
 * URI; anything else keeps its path and query. Previews, admin, login, REST, AJAX, cron, WP-CLI, robots.txt and
 * static assets are untouched.
 */
final class FrontendRedirect implements Hookable
{
    public function __construct(
        private readonly Config $config,
        private readonly UriResolver $uris,
        private readonly PreviewLinks $previews,
    ) {}

    public function register(): void
    {
        add_action('template_redirect', [$this, 'redirect'], 0);
    }

    public function redirect(): void
    {
        if (is_robots() || is_favicon() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI)) {
            return;
        }

        $requestUri = self::requestUri();
        $path = (string) parse_url($requestUri, PHP_URL_PATH);
        if (str_starts_with($path, '/wp-content/') || str_starts_with($path, '/wp-includes/')) {
            return;
        }

        [$target, $status] = $this->target($requestUri);
        if ($status === 302) {
            nocache_headers();
        }
        wp_redirect($target, $status, 'BP Headless');
        exit;
    }

    /**
     * Where a front request of the CMS host goes: [URL, HTTP status].
     *
     * @return array{0: string, 1: int}
     */
    public function target(string $requestUri): array
    {
        if (self::isRoot($requestUri)) {
            return [admin_url(), 302];
        }
        $front = $this->config->frontendUrl();
        $object = get_queried_object();

        if (is_preview() && $object instanceof \WP_Post && current_user_can('edit_post', $object->ID)) {
            return [$this->previews->urlFor($object), 302];
        }
        if ($object instanceof \WP_Post && is_singular(UriResolver::NODE_TYPES)) {
            $uri = $this->uris->forPost($object);
            if ($uri !== null) {
                return [$front.$uri, 301];
            }
        }
        if ($object instanceof \WP_Term && is_category()) {
            $uri = $this->uris->forTerm($object);
            if ($uri !== null) {
                return [$front.$uri, 301];
            }
        }

        return [$front.$requestUri, 301];
    }

    /** "/" (or "/?") without a query string: the bare CMS host. */
    public static function isRoot(string $requestUri): bool
    {
        return rtrim($requestUri, '?') === '/';
    }

    private static function requestUri(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $uri = '/'.ltrim(str_replace(["\r", "\n"], '', $uri), '/');

        return str_starts_with($uri, '//') ? '/' : $uri;
    }
}
