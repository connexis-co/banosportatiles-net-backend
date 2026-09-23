<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Tests\Fakes;

use BanosPortatiles\Headless\Import\SeedLookup;

/**
 * Deterministic lookup for importer tests: every known reference gets a stable fake ID.
 */
final class FakeSeedLookup implements SeedLookup
{
    /**
     * @param  array<string, int>  $equipos
     * @param  array<string, int>  $pages
     * @param  array<string, int>  $posts
     * @param  array<string, int>  $ciudades
     * @param  array<string, int>  $faqs
     */
    public function __construct(
        public array $equipos = [],
        public array $pages = [],
        public array $posts = [],
        public array $ciudades = [],
        public array $faqs = [],
    ) {}

    public function equipoId(string $slug): ?int
    {
        return $this->equipos[$slug] ?? null;
    }

    public function pageId(string $uri): ?int
    {
        return $this->pages[$uri] ?? null;
    }

    public function postId(string $slug): ?int
    {
        return $this->posts[$slug] ?? null;
    }

    public function ciudadId(string $slug): ?int
    {
        return $this->ciudades[$slug] ?? null;
    }

    public function faqId(string $id): ?int
    {
        return $this->faqs[$id] ?? null;
    }

    public function imageId(mixed $image): ?int
    {
        return is_array($image) && ($image['src'] ?? '') !== '' ? 900 : null;
    }
}
