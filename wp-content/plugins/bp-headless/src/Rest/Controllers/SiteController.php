<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Rest\Controllers;

use BanosPortatiles\Headless\Cache\ResponseCache;
use BanosPortatiles\Headless\Deploy\ContentVersion;
use BanosPortatiles\Headless\Normalizer\SiteNormalizer;

/**
 * GET /bp/v1/site. "contentVersion" is added on every response (not cached): the build copies it into
 * build.json, so the admin knows which content the public site shows.
 */
final class SiteController
{
    public function __construct(
        private readonly ResponseCache $cache,
        private readonly SiteNormalizer $normalizer,
        private readonly ContentVersion $version,
    ) {}

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $site = $this->cache->remember('site', $this->normalizer->normalize(...));

        return new \WP_REST_Response(['contentVersion' => $this->version->current()['version']] + $site);
    }
}
