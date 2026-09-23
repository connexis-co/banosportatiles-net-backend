<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Headless;

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Contracts\Hookable;
use BanosPortatiles\Headless\Routing\UriResolver;
use BanosPortatiles\Headless\Security\PreviewToken;

/**
 * "Preview" buttons open the Astro front: {FRONTEND}/api/preview?uri=…&token=… (HMAC token, 15 min).
 * The front calls GET /bp/v1/node?token=… server-side to render the draft.
 */
final class PreviewLinks implements Hookable
{
    public function __construct(
        private readonly Config $config,
        private readonly UriResolver $uris,
        private readonly PreviewToken $tokens,
    ) {}

    public function register(): void
    {
        add_filter('preview_post_link', [$this, 'filter'], 20, 2);
    }

    public function filter(string $link, \WP_Post $post): string
    {
        return in_array($post->post_type, UriResolver::NODE_TYPES, true) ? $this->urlFor($post) : $link;
    }

    public function urlFor(\WP_Post $post): string
    {
        $uri = $this->uris->forPost($post, true) ?? '/';

        return $this->config->frontendUrl().'/api/preview?'.http_build_query([
            'uri' => $uri,
            'token' => $this->tokens->issue($post->ID, time()),
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
