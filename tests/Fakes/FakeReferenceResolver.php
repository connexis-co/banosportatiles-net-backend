<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Tests\Fakes;

use BanosPortatiles\Headless\Normalizer\ReferenceResolver;

/**
 * In-memory resolver: IDs map to slugs/URIs/images; unknown IDs behave like unpublished content.
 */
final class FakeReferenceResolver implements ReferenceResolver
{
    /**
     * @param  array<int, string>  $slugs
     * @param  array<int, string>  $uris
     * @param  array<int, string>  $terms
     * @param  array<int, array{src: string, width: int, height: int, alt: string}>  $images
     * @param  array<int, array{q: string, a: string}>  $faqs
     */
    public function __construct(
        public array $slugs = [],
        public array $uris = [],
        public array $terms = [],
        public array $images = [],
        public array $faqs = [],
    ) {}

    public function slug(int $postId): ?string
    {
        return $this->slugs[$postId] ?? null;
    }

    public function uri(int $postId): ?string
    {
        return $this->uris[$postId] ?? null;
    }

    public function termSlug(int $termId): ?string
    {
        return $this->terms[$termId] ?? null;
    }

    public function image(int $attachmentId): ?array
    {
        return $this->images[$attachmentId] ?? null;
    }

    public function faq(int $postId): ?array
    {
        return $this->faqs[$postId] ?? null;
    }

    public function fileUrl(int $attachmentId): ?string
    {
        return null;
    }
}
