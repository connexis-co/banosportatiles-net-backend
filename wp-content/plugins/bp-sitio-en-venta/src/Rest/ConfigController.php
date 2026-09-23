<?php

declare(strict_types=1);

namespace BanosPortatiles\SitioEnVenta\Rest;

use BanosPortatiles\SitioEnVenta\Settings\Store;
use BanosPortatiles\SitioEnVenta\Support\PathRules;

/**
 * GET /wp-json/bp-venta/v1/config[?path=/ruta/] — public notice configuration.
 * Cached (transient + Cache-Control + ETag/304). With ?path, adds "visible" for that page.
 */
final class ConfigController
{
    public const NAMESPACE = 'bp-venta/v1';

    public const MAX_AGE = 60;

    public function __construct(private readonly Store $store) {}

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
        add_filter('rest_pre_serve_request', [$this, 'serveNotModified'], 5, 4);
    }

    public function routes(): void
    {
        register_rest_route(self::NAMESPACE, '/config', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'handle'],
            'permission_callback' => '__return_true',
            'args' => [
                'path' => ['type' => 'string', 'description' => 'Ruta de la página para calcular «visible».'],
            ],
        ]);
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $config = $this->store->publicConfig();
        $path = $request->get_param('path');
        $etag = (string) $config['version'];

        if (is_string($path) && $path !== '') {
            $rules = array_values(array_filter(is_array($config['exclude_paths']) ? $config['exclude_paths'] : [], 'is_string'));
            $config['visible'] = (bool) $config['enabled'] && ! PathRules::matches($path, $rules);
            $etag .= '-'.substr(md5($path), 0, 8);
        }

        $response = new \WP_REST_Response($config);
        $response->header('Cache-Control', 'public, max-age='.self::MAX_AGE);
        $response->header('ETag', '"'.$etag.'"');

        $ifNoneMatch = (string) $request->get_header('if_none_match');
        if ($ifNoneMatch !== '' && in_array('"'.$etag.'"', array_map(static fn (string $tag): string => trim(str_replace('W/', '', $tag)), explode(',', $ifNoneMatch)), true)) {
            $response->set_status(304);
            $response->set_data(null);
        }

        return $response;
    }

    public function serveNotModified(bool $served, mixed $result, mixed $request, mixed $server): bool
    {
        return $served || ($result instanceof \WP_HTTP_Response && $result->get_status() === 304
            && $request instanceof \WP_REST_Request && str_starts_with($request->get_route(), '/'.self::NAMESPACE.'/'));
    }
}
