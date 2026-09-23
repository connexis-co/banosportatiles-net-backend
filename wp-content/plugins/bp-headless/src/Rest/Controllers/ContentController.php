<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Rest\Controllers;

use BanosPortatiles\Headless\Cache\ResponseCache;
use BanosPortatiles\Headless\Normalizer\NodeNormalizer;

/**
 * GET /bp/v1/content?type=page|post|equipo&page=N&per_page=50 → Node[] + X-WP-Total / X-WP-TotalPages.
 */
final class ContentController
{
    public const TYPES = ['page', 'post', 'equipo'];

    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly ResponseCache $cache,
        private readonly NodeNormalizer $normalizer,
    ) {}

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $type = (string) $request->get_param('type');
        $page = max(1, (int) $request->get_param('page'));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->get_param('per_page')));

        $result = $this->cache->remember(
            sprintf('content:%s:%d:%d', $type, $page, $perPage),
            fn (): array => $this->query($type, $page, $perPage)
        );

        $response = new \WP_REST_Response($result['items']);
        $response->header('X-WP-Total', (string) $result['total']);
        $response->header('X-WP-TotalPages', (string) $result['pages']);

        return $response;
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, pages: int}
     */
    private function query(string $type, int $page, int $perPage): array
    {
        $query = new \WP_Query([
            'post_type' => $type,
            'post_status' => 'publish',
            'has_password' => false,
            'posts_per_page' => $perPage,
            'paged' => $page,
            'ignore_sticky_posts' => true,
            'orderby' => $type === 'post' ? ['date' => 'DESC', 'ID' => 'DESC'] : ['menu_order' => 'ASC', 'ID' => 'ASC'],
        ]);

        $items = [];
        foreach ($query->posts as $post) {
            if ($post instanceof \WP_Post) {
                $items[] = $this->normalizer->normalize($post);
            }
        }

        return ['items' => $items, 'total' => (int) $query->found_posts, 'pages' => (int) $query->max_num_pages];
    }
}
