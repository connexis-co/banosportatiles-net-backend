<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Rest\Controllers;

use BanosPortatiles\Headless\Cache\ResponseCache;
use BanosPortatiles\Headless\Normalizer\SiteNormalizer;

final class SiteController
{
    public function __construct(
        private readonly ResponseCache $cache,
        private readonly SiteNormalizer $normalizer,
    ) {}

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response($this->cache->remember('site', $this->normalizer->normalize(...)));
    }
}
