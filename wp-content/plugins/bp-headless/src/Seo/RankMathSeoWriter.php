<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Seo;

use BanosPortatiles\Headless\Support\Arr;

/**
 * Seed import with Rank Math active: the SEO of the seed is written to the Rank Math meta too (exact copy;
 * an empty value in the seed clears the Rank Math field), so the node's SEO matches the seed.
 */
final class RankMathSeoWriter
{
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
}
