<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Rest\Controllers;

use BanosPortatiles\Headless\Deploy\PublishMonitor;

/**
 * GET /bp/v1/status — publication state, never cached (the admin polls it every 15 s while a deploy is queued or
 * running). Light: options + build.json at most once every 20 s (transient).
 */
final class StatusController
{
    public function __construct(private readonly PublishMonitor $monitor) {}

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $response = new \WP_REST_Response($this->monitor->toApi());
        $response->header('Cache-Control', 'no-store, max-age=0');

        return $response;
    }
}
