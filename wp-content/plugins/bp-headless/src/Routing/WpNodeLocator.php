<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Routing;

final class WpNodeLocator implements NodeLocator
{
    public function __construct(private readonly UriResolver $uris) {}

    public function find(string $uri): ?\WP_Post
    {
        $post = $this->uris->resolve($uri);

        return ($post instanceof \WP_Post && in_array($post->post_type, UriResolver::NODE_TYPES, true) && $post->post_password === '')
            ? $post
            : null;
    }

    public function uriOf(\WP_Post $post): ?string
    {
        return $this->uris->forPost($post);
    }

    public function all(): array
    {
        return array_values(array_filter(get_posts([
            'post_type' => UriResolver::NODE_TYPES,
            'post_status' => 'publish',
            'has_password' => false,
            'numberposts' => -1,
            'orderby' => ['type' => 'ASC', 'menu_order' => 'ASC', 'ID' => 'ASC'],
            'no_found_rows' => true,
        ]), static fn (mixed $post): bool => $post instanceof \WP_Post));
    }
}
