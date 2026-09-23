<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Admin;

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Content\PageTemplates;
use BanosPortatiles\Headless\Contracts\Hookable;
use BanosPortatiles\Headless\Routing\UriResolver;

/**
 * "Plantilla" and "URI pública" columns in the pages list.
 */
final class PageColumns implements Hookable
{
    public function __construct(
        private readonly Config $config,
        private readonly UriResolver $uris,
    ) {}

    public function register(): void
    {
        add_filter('manage_page_posts_columns', [$this, 'columns']);
        add_action('manage_page_posts_custom_column', [$this, 'render'], 10, 2);
    }

    /**
     * @param  array<string, string>  $columns
     * @return array<string, string>
     */
    public function columns(array $columns): array
    {
        $out = [];
        foreach ($columns as $key => $label) {
            $out[$key] = $label;
            if ($key === 'title') {
                $out['bp_template'] = 'Plantilla';
                $out['bp_uri'] = 'URI pública';
            }
        }

        return $out;
    }

    public function render(string $column, int $postId): void
    {
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            return;
        }

        if ($column === 'bp_template') {
            $slug = PageTemplates::slugFor($post);
            printf('%s <code>%s</code>', esc_html(PageTemplates::label($slug)), esc_html($slug));
        }

        if ($column === 'bp_uri') {
            $uri = $this->uris->forPost($post, true);
            if ($uri === null) {
                echo '—';

                return;
            }
            printf(
                '<a href="%s" target="_blank" rel="noopener"><code>%s</code></a>',
                esc_url($this->config->frontendUrl().$uri),
                esc_html($uri)
            );
        }
    }
}
