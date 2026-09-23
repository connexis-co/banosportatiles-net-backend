<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Normalizer;

use BanosPortatiles\Headless\Support\Arr;

/**
 * SCF "seo" group → {title, description, canonical?, noindex, ogImage?, keyword?}.
 */
final class SeoNormalizer
{
    public const DESCRIPTION_LENGTH = 155;

    public function __construct(private readonly ReferenceResolver $refs) {}

    /**
     * @return array<string, mixed>
     */
    public function normalize(mixed $seo, string $fallbackTitle, string $fallbackDescription): array
    {
        $seo = is_array($seo) ? $seo : [];
        $ogImageId = Arr::ids($seo['og_image'] ?? null)[0] ?? 0;
        $title = Arr::string($seo, 'title');
        $description = Arr::string($seo, 'description');

        return array_filter([
            'title' => $title !== '' ? $title : $fallbackTitle,
            'description' => $description !== '' ? $description : self::truncate($fallbackDescription, self::DESCRIPTION_LENGTH),
            'canonical' => Arr::string($seo, 'canonical') ?: null,
            'noindex' => Arr::bool($seo, 'noindex'),
            'ogImage' => $ogImageId > 0 ? $this->refs->image($ogImageId) : null,
            'keyword' => Arr::string($seo, 'keyword') ?: null,
        ], static fn (mixed $v): bool => $v !== null);
    }

    public static function truncate(string $text, int $length): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $cut = mb_substr($text, 0, $length - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false ? mb_substr($cut, 0, $space) : $cut, ' ,.;:').'…';
    }
}
