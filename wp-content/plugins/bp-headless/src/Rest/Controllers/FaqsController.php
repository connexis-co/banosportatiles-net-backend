<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Rest\Controllers;

use BanosPortatiles\Headless\Cache\ResponseCache;
use BanosPortatiles\Headless\Content\PostTypes;
use BanosPortatiles\Headless\Content\Taxonomies;
use BanosPortatiles\Headless\Html\ContentRenderer;

/**
 * GET /bp/v1/faqs → [{id, q, a, temas[]}] (id = slug, same as faqs.yaml).
 */
final class FaqsController
{
    public function __construct(
        private readonly ResponseCache $cache,
        private readonly ContentRenderer $renderer,
    ) {}

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response($this->cache->remember('faqs', $this->faqs(...)));
    }

    /**
     * @return list<array{id: string, q: string, a: string, temas: list<string>}>
     */
    private function faqs(): array
    {
        $posts = get_posts([
            'post_type' => PostTypes::FAQ,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
            'no_found_rows' => true,
        ]);

        $faqs = [];
        foreach ($posts as $post) {
            if (! $post instanceof \WP_Post) {
                continue;
            }
            $terms = get_the_terms($post, Taxonomies::TEMA_FAQ);
            $faqs[] = [
                'id' => $post->post_name,
                'q' => html_entity_decode($post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'a' => $this->renderer->fragment($post->post_content),
                'temas' => is_array($terms) ? array_values(array_map(static fn (\WP_Term $t): string => $t->slug, $terms)) : [],
            ];
        }

        return $faqs;
    }
}
