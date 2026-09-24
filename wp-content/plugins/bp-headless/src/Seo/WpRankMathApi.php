<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Seo;

use RankMath\Helper;
use RankMath\Replace_Variables\Replacer;

/**
 * Rank Math 1.0.x through RankMath\Helper (get_settings, replace_vars). Variables are resolved the way Rank
 * Math's own get_seo_meta() does it (post context + variables set up), but its per-string replacement cache is
 * cleared for each post: in a batch (/content) a cached %parent_title% would leak between posts.
 */
final class WpRankMathApi implements RankMathApi
{
    public function active(): bool
    {
        return class_exists(Helper::class) && function_exists('rank_math') && isset(rank_math()->variables);
    }

    public function setting(string $key): mixed
    {
        return Helper::get_settings($key);
    }

    public function replaceVars(string $template, \WP_Post $post): string
    {
        if (! str_contains($template, '%')) {
            return self::decode($template);
        }

        $previous = $GLOBALS['post'] ?? null;
        $GLOBALS['post'] = $post;
        setup_postdata($post);
        try {
            rank_math()->variables->setup();
            if (class_exists(Replacer::class)) {
                Replacer::$replacements_cache = [];
            }

            return self::decode(Helper::replace_vars($template, $post));
        } finally {
            $GLOBALS['post'] = $previous;
            if ($previous instanceof \WP_Post) {
                setup_postdata($previous);
            } else {
                wp_reset_postdata();
            }
        }
    }

    private static function decode(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
