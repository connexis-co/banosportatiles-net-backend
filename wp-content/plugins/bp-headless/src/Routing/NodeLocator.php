<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Routing;

/**
 * Published nodes (page, post, equipo) by public URI. Abstracted so the endpoints can be unit-tested.
 */
interface NodeLocator
{
    public function find(string $uri): ?\WP_Post;

    public function uriOf(\WP_Post $post): ?string;

    /**
     * Every published node without password, ordered by type and menu order.
     *
     * @return list<\WP_Post>
     */
    public function all(): array;
}
