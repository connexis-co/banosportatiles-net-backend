<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Import;

/**
 * Resolves seed references (slugs, URIs, image descriptors) to WordPress IDs during an import.
 */
interface SeedLookup
{
    public function equipoId(string $slug): ?int;

    public function pageId(string $uri): ?int;

    public function postId(string $slug): ?int;

    public function ciudadId(string $slug): ?int;

    public function faqId(string $id): ?int;

    /** @param mixed $image {src, alt} or a path string */
    public function imageId(mixed $image): ?int;
}
