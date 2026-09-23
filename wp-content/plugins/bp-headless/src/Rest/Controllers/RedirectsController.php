<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Rest\Controllers;

use BanosPortatiles\Headless\Cache\ResponseCache;
use BanosPortatiles\Headless\Redirects\RedirectionRepository;

/**
 * GET /bp/v1/redirects → [{from, to, code}] for the Worker's _redirects.
 */
final class RedirectsController
{
    public function __construct(
        private readonly ResponseCache $cache,
        private readonly RedirectionRepository $redirects,
    ) {}

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response($this->cache->remember('redirects', $this->redirects->all(...)));
    }
}
