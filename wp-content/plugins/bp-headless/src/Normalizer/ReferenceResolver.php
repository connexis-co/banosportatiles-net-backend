<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Normalizer;

/**
 * Resolves SCF relational values (post, term and attachment IDs) into API values.
 * Only published content is resolved; everything else returns null and is skipped.
 */
interface ReferenceResolver
{
    public function slug(int $postId): ?string;

    public function uri(int $postId): ?string;

    public function termSlug(int $termId): ?string;

    /**
     * @return array{src: string, width: int, height: int, alt: string}|null
     */
    public function image(int $attachmentId): ?array;

    /**
     * @return array{q: string, a: string}|null
     */
    public function faq(int $postId): ?array;

    public function fileUrl(int $attachmentId): ?string;
}
