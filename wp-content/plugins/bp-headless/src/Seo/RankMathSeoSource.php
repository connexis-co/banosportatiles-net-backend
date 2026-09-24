<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Seo;

use BanosPortatiles\Headless\Normalizer\ReferenceResolver;
use BanosPortatiles\Headless\Normalizer\SeoNormalizer;

/**
 * Rank Math meta (rank_math_*) → Node "seo" (contract §3.1), with the same fallbacks Rank Math applies on a
 * front end: custom title/description → post type templates (titles.pt_{type}_title / _description) →
 * %variables% resolved by Rank Math. Robots come from the post or its type/global defaults, never from the
 * noindex of the CMS host itself (blog_public = 0), which Rank Math would otherwise add to every page.
 * The canonical is published on the public domain and omitted when it points to the node itself.
 */
final class RankMathSeoSource implements SeoSource
{
    public const SOURCE = 'rankmath';

    public const DEFAULT_TITLE = '%title% %sep% %sitename%';

    public const DEFAULT_DESCRIPTION = '%excerpt%';

    public const DESCRIPTION_LENGTH = 160;

    public const IMAGE_PREVIEWS = ['none', 'standard', 'large'];

    public function __construct(
        private readonly RankMathApi $rankMath,
        private readonly ReferenceResolver $refs,
        private readonly SeoContext $context,
    ) {}

    public function available(): bool
    {
        return $this->rankMath->active();
    }

    public function normalize(\WP_Post $post, string $title, string $excerpt, string $uri): array
    {
        $id = $post->ID;
        $type = $post->post_type;

        $seoTitle = $this->vars(self::meta($id, 'title'), $post);
        if ($seoTitle === '') {
            $seoTitle = $this->vars($this->template("pt_{$type}_title", self::DEFAULT_TITLE), $post);
        }

        $description = $this->vars(self::meta($id, 'description'), $post);
        if ($description === '') {
            $description = self::clean($post->post_excerpt);
        }
        if ($description === '') {
            $description = SeoNormalizer::truncate($this->vars($this->template("pt_{$type}_description", self::DEFAULT_DESCRIPTION), $post), self::DESCRIPTION_LENGTH);
        }

        $directives = $this->directives($post);
        $robots = self::robotsObject($directives, $this->advancedRobots($post));
        $keywords = self::keywords(self::meta($id, 'focus_keyword'));
        $useFacebook = self::meta($id, 'twitter_use_facebook') !== 'off';

        return array_filter([
            'title' => $seoTitle !== '' ? $seoTitle : $title,
            'description' => $description !== '' ? $description : SeoNormalizer::truncate($excerpt, SeoNormalizer::DESCRIPTION_LENGTH),
            'canonical' => $this->context->canonical(self::meta($id, 'canonical_url'), $uri),
            'noindex' => in_array('noindex', $directives, true),
            'nofollow' => in_array('nofollow', $directives, true) ? true : null,
            'robots' => $robots !== [] ? $robots : null,
            'ogTitle' => $this->vars(self::meta($id, 'facebook_title'), $post) ?: null,
            'ogDescription' => $this->vars(self::meta($id, 'facebook_description'), $post) ?: null,
            'ogImage' => $this->image($id, 'facebook'),
            'twitterTitle' => $useFacebook ? null : ($this->vars(self::meta($id, 'twitter_title'), $post) ?: null),
            'twitterDescription' => $useFacebook ? null : ($this->vars(self::meta($id, 'twitter_description'), $post) ?: null),
            'twitterImage' => $useFacebook ? null : $this->image($id, 'twitter'),
            'twitterCard' => self::card(self::meta($id, 'twitter_card_type') ?: self::string($this->rankMath->setting('titles.twitter_card_type'))),
            'keyword' => $keywords[0] ?? null,
            'keywords' => $keywords !== [] ? $keywords : null,
            'breadcrumbTitle' => self::meta($id, 'breadcrumb_title') ?: null,
            'source' => self::SOURCE,
        ], static fn (mixed $v): bool => $v !== null);
    }

    public function noindex(\WP_Post $post): bool
    {
        return in_array('noindex', $this->directives($post), true);
    }

    /**
     * Robots of the post, else of its post type (when "custom robots" is on), else the global ones.
     *
     * @return list<string>
     */
    public function directives(\WP_Post $post): array
    {
        $own = self::directiveList(get_post_meta($post->ID, 'rank_math_robots', true));
        if ($own !== []) {
            return $own;
        }

        return $this->typeCustom($post->post_type)
            ? self::directiveList($this->rankMath->setting("titles.pt_{$post->post_type}_robots"))
            : self::directiveList($this->rankMath->setting('titles.robots_global'));
    }

    /**
     * @param  list<string>  $directives  noindex, nofollow, noarchive, noimageindex, nosnippet…
     * @param  array<array-key, mixed>  $advanced  {max-snippet, max-image-preview, max-video-preview}
     * @return array<string, int|string|bool>
     */
    public static function robotsObject(array $directives, array $advanced): array
    {
        $robots = [];
        $snippet = $advanced['max-snippet'] ?? null;
        if (is_numeric($snippet)) {
            $robots['maxSnippet'] = (int) $snippet;
        }
        if (in_array('nosnippet', $directives, true)) {
            $robots['maxSnippet'] = 0;
        }
        $image = is_string($advanced['max-image-preview'] ?? null) ? strtolower($advanced['max-image-preview']) : '';
        if (in_array($image, self::IMAGE_PREVIEWS, true)) {
            $robots['maxImagePreview'] = $image;
        }
        $video = $advanced['max-video-preview'] ?? null;
        if (is_numeric($video)) {
            $robots['maxVideoPreview'] = (int) $video;
        }
        foreach (['noarchive', 'noimageindex'] as $flag) {
            if (in_array($flag, $directives, true)) {
                $robots[$flag] = true;
            }
        }

        return $robots;
    }

    /**
     * "kw 1, kw 2 ,kw 1" → ["kw 1", "kw 2"].
     *
     * @return list<string>
     */
    public static function keywords(string $value): array
    {
        $keywords = [];
        foreach (explode(',', $value) as $keyword) {
            $keyword = trim($keyword);
            if ($keyword !== '' && ! in_array(mb_strtolower($keyword), array_map('mb_strtolower', $keywords), true)) {
                $keywords[] = $keyword;
            }
        }

        return $keywords;
    }

    public static function card(string $type): ?string
    {
        return match ($type) {
            'summary_large_image' => 'summary_large_image',
            'summary_card', 'summary' => 'summary',
            default => null,
        };
    }

    /**
     * @return array<array-key, mixed>
     */
    private function advancedRobots(\WP_Post $post): array
    {
        $own = get_post_meta($post->ID, 'rank_math_advanced_robots', true);
        if (is_array($own) && $own !== []) {
            return $own;
        }
        $fallback = $this->typeCustom($post->post_type)
            ? $this->rankMath->setting("titles.pt_{$post->post_type}_advanced_robots")
            : $this->rankMath->setting('titles.advanced_robots_global');

        return is_array($fallback) ? $fallback : [];
    }

    private function typeCustom(string $type): bool
    {
        $custom = $this->rankMath->setting("titles.pt_{$type}_custom_robots");

        return $custom === true || $custom === 'on' || $custom === '1' || $custom === 1;
    }

    /** @return array{src: string, width: int, height: int, alt: string}|null */
    private function image(int $postId, string $network): ?array
    {
        $id = (int) get_post_meta($postId, "rank_math_{$network}_image_id", true);
        if ($id <= 0) {
            $url = self::meta($postId, "{$network}_image");
            $id = $url !== '' ? attachment_url_to_postid($url) : 0;
        }

        return $id > 0 ? $this->refs->image($id) : null;
    }

    private function template(string $key, string $default): string
    {
        $template = self::string($this->rankMath->setting('titles.'.$key));

        return $template !== '' ? $template : $default;
    }

    /** Rank Math replaces %seo_title% / %seo_description% in meta fields before resolving variables. */
    private function vars(string $value, \WP_Post $post): string
    {
        if ($value === '') {
            return '';
        }
        $value = str_replace(['%seo_title%', '%seo_description%'], ['%title%', '%excerpt%'], $value);

        return self::clean($this->rankMath->replaceVars($value, $post));
    }

    private static function meta(int $postId, string $key): string
    {
        $value = get_post_meta($postId, 'rank_math_'.$key, true);

        return is_string($value) ? trim($value) : '';
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return list<string>
     */
    private static function directiveList(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $value = [$value];
        }

        return is_array($value)
            ? array_values(array_unique(array_filter(array_map(static fn (mixed $v): string => is_string($v) ? strtolower(trim($v)) : '', $value))))
            : [];
    }

    private static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
