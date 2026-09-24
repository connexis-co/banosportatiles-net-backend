<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Normalizer;

use BanosPortatiles\Headless\Seo\RankMathSeoSource;
use BanosPortatiles\Headless\Seo\ScfSeoSource;
use BanosPortatiles\Headless\Seo\SeoSource;

/**
 * Node "seo": Rank Math when it is active (seo.source = "rankmath"), else the SCF group «SEO» ("bp").
 * If reading Rank Math fails (e.g. it changed its meta), the node falls back to SCF instead of breaking the API.
 */
final class SeoNormalizer
{
    public const DESCRIPTION_LENGTH = 155;

    public function __construct(
        private readonly RankMathSeoSource $rankMath,
        private readonly ScfSeoSource $scf,
    ) {}

    public function source(): SeoSource
    {
        return $this->rankMath->available() ? $this->rankMath : $this->scf;
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(\WP_Post $post, string $fallbackTitle, string $fallbackDescription, string $uri): array
    {
        if ($this->rankMath->available()) {
            try {
                return $this->rankMath->normalize($post, $fallbackTitle, $fallbackDescription, $uri);
            } catch (\Throwable $e) {
                self::report($e, $post);
            }
        }

        return $this->scf->normalize($post, $fallbackTitle, $fallbackDescription, $uri);
    }

    public function noindex(\WP_Post $post): bool
    {
        if ($this->rankMath->available()) {
            try {
                return $this->rankMath->noindex($post);
            } catch (\Throwable $e) {
                self::report($e, $post);
            }
        }

        return $this->scf->noindex($post);
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

    private static function report(\Throwable $e, \WP_Post $post): void
    {
        error_log(sprintf('[bp-headless] Rank Math SEO failed for post %d, using SCF: %s', $post->ID, $e->getMessage()));
    }
}
