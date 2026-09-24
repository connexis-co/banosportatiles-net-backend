<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Seo;

use BanosPortatiles\Headless\Support\Arr;

/**
 * Seed import with Rank Math active: the SEO of the seed is written to the Rank Math meta too (exact copy;
 * an empty value in the seed clears the Rank Math field), so the node's SEO matches the seed. site.seo
 * (site name and separator) goes to «Títulos y meta», the source of /site → seo.
 */
final class RankMathSeoWriter
{
    public const MAX_SEPARATOR_LENGTH = 5;

    public function __construct(private readonly RankMathApi $rankMath) {}

    /**
     * @param  array<string, mixed>  $seo  SCF "seo" values mapped from the seed (og_image = attachment id or "").
     */
    public function write(int $postId, array $seo): bool
    {
        if (! $this->rankMath->active()) {
            return false;
        }
        $ogImageId = Arr::ids($seo['og_image'] ?? null)[0] ?? 0;
        $url = $ogImageId > 0 ? wp_get_attachment_url($ogImageId) : false;

        foreach (RankMathMigration::fromScf($seo, is_string($url) ? $url : '') as $key => $value) {
            RankMathMigration::isEmpty($value) ? delete_post_meta($postId, $key) : update_post_meta($postId, $key, $value);
        }

        return true;
    }

    /**
     * site.seo → Rank Math website name and separator (empty values are skipped: never blanks a setting). Marks
     * them as applied so `wp bp setup rankmath` does not replace them afterwards.
     */
    public function writeSite(string $siteName, string $separator): bool
    {
        if (! $this->rankMath->active()) {
            return false;
        }
        $values = self::siteValues($siteName, $separator);
        if ($values === []) {
            return false;
        }
        $stored = get_option(RankMathSetup::TITLES_OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $changed = array_filter($values, static fn (string $value, string $key): bool => ($stored[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);
        if ($changed !== []) {
            update_option(RankMathSetup::TITLES_OPTION, $changed + $stored);
        }
        RankMathSetup::markApplied(array_keys($values));

        return $changed !== [];
    }

    /**
     * @return array<string, string> website_name and title_separator (1–5 characters) when present.
     */
    public static function siteValues(string $siteName, string $separator): array
    {
        $values = [];
        $siteName = trim($siteName);
        if ($siteName !== '') {
            $values['website_name'] = $siteName;
        }
        $separator = trim($separator);
        if ($separator !== '' && mb_strlen($separator) <= self::MAX_SEPARATOR_LENGTH) {
            $values['title_separator'] = $separator;
        }

        return $values;
    }
}
