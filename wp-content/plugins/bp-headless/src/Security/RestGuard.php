<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Security;

use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * No user enumeration: /wp/v2/users requires authentication and the oEmbed "embed" route
 * (which exposes author names) is removed.
 */
final class RestGuard implements Hookable
{
    public function register(): void
    {
        add_filter('rest_pre_dispatch', [$this, 'guardUsers'], 10, 3);
        add_filter('rest_endpoints', [$this, 'removeEndpoints']);
    }

    public function guardUsers(mixed $result, mixed $server, mixed $request): mixed
    {
        if ($request instanceof \WP_REST_Request && str_starts_with($request->get_route(), '/wp/v2/users') && ! is_user_logged_in()) {
            return new \WP_Error('rest_forbidden', 'Autenticación requerida.', ['status' => 401]);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $endpoints
     * @return array<string, mixed>
     */
    public function removeEndpoints(array $endpoints): array
    {
        unset($endpoints['/oembed/1.0/embed']);

        return $endpoints;
    }
}
