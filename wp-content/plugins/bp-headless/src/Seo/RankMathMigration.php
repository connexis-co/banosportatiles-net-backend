<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Seo;

use BanosPortatiles\Headless\Support\Arr;

/**
 * SCF group «SEO» → Rank Math meta (pure). Used by the seed importer (exact copy) and by
 * `wp bp seo migrate-rankmath` (only the fields that are still empty in Rank Math, unless --force).
 * Secondary keywords become extra focus keywords, up to the 5 of Rank Math's free version.
 */
final class RankMathMigration
{
    public const MAX_KEYWORDS = 5;

    public const META_KEYS = [
        'rank_math_title',
        'rank_math_description',
        'rank_math_focus_keyword',
        'rank_math_canonical_url',
        'rank_math_robots',
        'rank_math_facebook_image_id',
        'rank_math_facebook_image',
    ];

    /**
     * @param  array<array-key, mixed>  $scf  SCF "seo" values (og_image as attachment id).
     * @return array<string, string|list<string>> "" or [] = no value
     */
    public static function fromScf(array $scf, string $ogImageUrl = ''): array
    {
        $ogImageId = Arr::ids($scf['og_image'] ?? null)[0] ?? 0;

        return [
            'rank_math_title' => Arr::string($scf, 'title'),
            'rank_math_description' => Arr::string($scf, 'description'),
            'rank_math_focus_keyword' => self::focusKeywords(Arr::string($scf, 'keyword'), Arr::string($scf, 'keywords_secundarias')),
            'rank_math_canonical_url' => Arr::string($scf, 'canonical'),
            'rank_math_robots' => Arr::bool($scf, 'noindex') ? ['noindex'] : [],
            'rank_math_facebook_image_id' => $ogImageId > 0 ? (string) $ogImageId : '',
            'rank_math_facebook_image' => $ogImageId > 0 ? $ogImageUrl : '',
        ];
    }

    /**
     * Changes for the migration: never blanks a value; without $force it only fills empty Rank Math fields.
     * The social image id and its URL travel together.
     *
     * @param  array<string, string|list<string>>  $target  fromScf()
     * @param  array<string, mixed>  $current  Rank Math meta now
     * @return array<string, string|list<string>>
     */
    public static function plan(array $target, array $current, bool $force): array
    {
        $changes = [];
        foreach ($target as $key => $value) {
            $now = $current[$key] ?? null;
            if (self::isEmpty($value) || (! $force && ! self::isEmpty($now)) || $now === $value) {
                continue;
            }
            $changes[$key] = $value;
        }
        if (isset($changes['rank_math_facebook_image_id']) xor isset($changes['rank_math_facebook_image'])) {
            unset($changes['rank_math_facebook_image_id'], $changes['rank_math_facebook_image']);
        }

        return $changes;
    }

    /** "kw" + "kw 2\nkw 3" → "kw,kw 2,kw 3" (unique, at most 5). */
    public static function focusKeywords(string $keyword, string $secondary): string
    {
        $all = RankMathSeoSource::keywords(implode(',', [$keyword, ...Arr::lines(str_replace(',', "\n", $secondary))]));

        return implode(',', array_slice($all, 0, self::MAX_KEYWORDS));
    }

    public static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || $value === false;
    }
}
