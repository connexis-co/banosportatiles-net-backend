<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Rest;

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * Replaces core's permissive CORS (which reflects any Origin) with an allowlist: the public front,
 * BP_CORS_ORIGINS and the CMS itself.
 */
final class Cors implements Hookable
{
    public function __construct(private readonly Config $config) {}

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
            add_filter('rest_pre_serve_request', [$this, 'sendHeaders'], 10, 1);
        }, 15);
        add_filter('rest_allowed_cors_headers', static fn (array $headers): array => array_values(array_unique([...$headers, 'If-None-Match', 'X-BP-Signature', 'X-BP-Timestamp'])));
        add_filter('rest_exposed_cors_headers', static fn (array $headers): array => array_values(array_unique([...$headers, 'ETag'])));
    }

    public function sendHeaders(mixed $served): mixed
    {
        if (headers_sent()) {
            return $served;
        }

        header('Vary: Origin', false);
        $origin = get_http_origin();
        if ($origin !== '' && self::isAllowed($origin, $this->config->corsOrigins())) {
            header('Access-Control-Allow-Origin: '.$origin);
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Max-Age: 600');
        }

        return $served;
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function isAllowed(string $origin, array $allowed): bool
    {
        $normalized = Config::originOf($origin);

        return $normalized !== null && in_array($normalized, $allowed, true);
    }
}
