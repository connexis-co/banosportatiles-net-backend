<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Rest\Controllers;

use BanosPortatiles\Headless\Cache\ResponseCache;
use BanosPortatiles\Headless\Content\PageTemplates;
use BanosPortatiles\Headless\Normalizer\SeoNormalizer;
use BanosPortatiles\Headless\Routing\UriResolver;

/**
 * GET /bp/v1/routes → [{uri, type, id, template, modified, noindex}] (getStaticPaths + sitemap).
 */
final class RoutesController
{
    public function __construct(
        private readonly ResponseCache $cache,
        private readonly UriResolver $uris,
        private readonly SeoNormalizer $seo,
    ) {}

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response($this->cache->remember('routes', $this->routes(...)));
    }

    /**
     * @return list<array{uri: string, type: string, id: int, template: string, modified: string, noindex: bool}>
     */
    private function routes(): array
    {
        $posts = get_posts([
            'post_type' => UriResolver::NODE_TYPES,
            'post_status' => 'publish',
            'has_password' => false,
            'numberposts' => -1,
            'orderby' => ['type' => 'ASC', 'menu_order' => 'ASC', 'ID' => 'ASC'],
            'no_found_rows' => true,
        ]);

        $routes = [];
        foreach ($posts as $post) {
            $uri = $this->uris->forPost($post);
            if ($uri === null) {
                continue;
            }
            $modified = get_post_datetime($post, 'modified');
            $routes[] = [
                'uri' => $uri,
                'type' => $post->post_type,
                'id' => $post->ID,
                'template' => PageTemplates::slugFor($post),
                'modified' => $modified !== false ? $modified->format(DATE_ATOM) : '',
                'noindex' => $this->seo->noindex($post),
            ];
        }

        usort($routes, static fn (array $a, array $b): int => strcmp($a['uri'], $b['uri']));

        return $routes;
    }
}
