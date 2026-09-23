<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Rest;

use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * HTTP caching for bp/v1: Cache-Control, strong ETag + 304 on If-None-Match, and X-Robots-Tag on every REST response.
 * max-age stays below the 60 s deploy debounce, so a build never reads a stale CDN copy.
 */
final class HttpCache implements Hookable
{
    public const MAX_AGE = 30;

    public function register(): void
    {
        add_filter('rest_post_dispatch', [$this, 'decorate'], 20, 3);
        add_filter('rest_pre_serve_request', [$this, 'serveNotModified'], 5, 4);
    }

    public function decorate(mixed $response, mixed $server, mixed $request): mixed
    {
        if (! $response instanceof \WP_HTTP_Response || ! $request instanceof \WP_REST_Request) {
            return $response;
        }

        $response->header('X-Robots-Tag', 'noindex, nofollow');
        if (! self::isOwnRoute($request)) {
            return $response;
        }

        $headers = $response->get_headers();
        if (isset($headers['Cache-Control'])) {
            return $response;
        }
        if ($request->get_method() !== 'GET' || $response->get_status() !== 200 || is_user_logged_in()) {
            $response->header('Cache-Control', 'private, no-store');

            return $response;
        }

        $etag = '"'.md5((string) wp_json_encode($response->get_data())).'"';
        $response->header('ETag', $etag);
        $response->header('Cache-Control', 'public, max-age='.self::MAX_AGE);

        if (self::matches($request->get_header('if_none_match'), $etag)) {
            $response->set_status(304);
            $response->set_data(null);
        }

        return $response;
    }

    public function serveNotModified(bool $served, mixed $result, mixed $request, mixed $server): bool
    {
        return $served || ($result instanceof \WP_HTTP_Response && $result->get_status() === 304
            && $request instanceof \WP_REST_Request && self::isOwnRoute($request));
    }

    public static function matches(?string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === null || $ifNoneMatch === '') {
            return false;
        }
        $strip = static fn (string $tag): string => trim(str_starts_with(trim($tag), 'W/') ? substr(trim($tag), 2) : $tag);
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            if (trim($candidate) === '*' || $strip($candidate) === $etag) {
                return true;
            }
        }

        return false;
    }

    private static function isOwnRoute(\WP_REST_Request $request): bool
    {
        return str_starts_with($request->get_route(), '/'.RestApi::NAMESPACE.'/');
    }
}
