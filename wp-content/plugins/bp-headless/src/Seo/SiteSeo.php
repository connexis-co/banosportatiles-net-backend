<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Seo;

use BanosPortatiles\Headless\Normalizer\ReferenceResolver;

/**
 * /site → seo: {siteName, separator, defaultOgImage?}. From Rank Math («Títulos y meta») when it is active;
 * otherwise the brand name and "|".
 */
final class SiteSeo
{
    public const DEFAULT_SEPARATOR = '|';

    public function __construct(
        private readonly RankMathApi $rankMath,
        private readonly ReferenceResolver $refs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSite(string $brandName): array
    {
        if (! $this->rankMath->active()) {
            return ['siteName' => $brandName, 'separator' => self::DEFAULT_SEPARATOR];
        }

        $name = self::text($this->rankMath->setting('titles.website_name'));
        $separator = self::text($this->rankMath->setting('titles.title_separator'));
        $imageId = $this->rankMath->setting('titles.open_graph_image_id');
        $image = is_numeric($imageId) && (int) $imageId > 0 ? $this->refs->image((int) $imageId) : null;

        return [
            'siteName' => $name !== '' ? $name : $brandName,
            'separator' => $separator !== '' ? $separator : self::DEFAULT_SEPARATOR,
        ] + ($image !== null ? ['defaultOgImage' => $image] : []);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    }
}
