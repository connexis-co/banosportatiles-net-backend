<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Seo;

use BanosPortatiles\Headless\Fields\FieldReader;
use BanosPortatiles\Headless\Normalizer\ReferenceResolver;
use BanosPortatiles\Headless\Normalizer\SeoNormalizer;
use BanosPortatiles\Headless\Support\Arr;

/**
 * SCF group «SEO» → Node "seo" (the fallback when Rank Math is not active):
 * {title, description, canonical?, noindex, ogImage?, keyword?, keywords?, source: "bp"}.
 */
final class ScfSeoSource implements SeoSource
{
    public const SOURCE = 'bp';

    public function __construct(
        private readonly FieldReader $fields,
        private readonly ReferenceResolver $refs,
        private readonly SeoContext $context,
    ) {}

    public function available(): bool
    {
        return true;
    }

    public function normalize(\WP_Post $post, string $title, string $excerpt, string $uri): array
    {
        return $this->fromValues($this->fields->get('seo', $post->ID), $title, $excerpt, $uri);
    }

    public function noindex(\WP_Post $post): bool
    {
        $seo = $this->fields->get('seo', $post->ID);

        return is_array($seo) && Arr::bool($seo, 'noindex');
    }

    /**
     * Pure part: SCF values → contract object.
     *
     * @return array<string, mixed>
     */
    public function fromValues(mixed $seo, string $fallbackTitle, string $fallbackDescription, string $uri = ''): array
    {
        $seo = is_array($seo) ? $seo : [];
        $ogImageId = Arr::ids($seo['og_image'] ?? null)[0] ?? 0;
        $title = Arr::string($seo, 'title');
        $description = Arr::string($seo, 'description');
        $keyword = Arr::string($seo, 'keyword');

        return array_filter([
            'title' => $title !== '' ? $title : $fallbackTitle,
            'description' => $description !== '' ? $description : SeoNormalizer::truncate($fallbackDescription, SeoNormalizer::DESCRIPTION_LENGTH),
            'canonical' => $this->context->canonical(Arr::string($seo, 'canonical'), $uri),
            'noindex' => Arr::bool($seo, 'noindex'),
            'ogImage' => $ogImageId > 0 ? $this->refs->image($ogImageId) : null,
            'keyword' => $keyword !== '' ? $keyword : null,
            'keywords' => $keyword !== '' ? [$keyword] : null,
            'source' => self::SOURCE,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
