<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Tests\Fakes;

use BanosPortatiles\Headless\Routing\NodeLocator;

/**
 * Published nodes keyed by public URI.
 */
final class FakeNodeLocator implements NodeLocator
{
    /**
     * @param  array<string, \WP_Post>  $nodes
     */
    public function __construct(public array $nodes = []) {}

    public function find(string $uri): ?\WP_Post
    {
        return $this->nodes[$uri] ?? null;
    }

    public function uriOf(\WP_Post $post): ?string
    {
        $uri = array_search($post, $this->nodes, true);

        return is_string($uri) ? $uri : null;
    }

    public function all(): array
    {
        return array_values($this->nodes);
    }
}
