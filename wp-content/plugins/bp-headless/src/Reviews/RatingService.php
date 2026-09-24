<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Reviews;

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Content\PageTemplates;
use BanosPortatiles\Headless\Fields\FieldReader;
use BanosPortatiles\Headless\Support\Arr;

/**
 * Ratings of a node: policy (site settings + per-post override) → gateway records → contract objects.
 * Shared by the Node normalizer, /site, the /ratings and /reviews endpoints, the admin column and WP-CLI.
 */
final class RatingService
{
    /** Deduplication window per (node, voter). */
    public const DEDUP_DAYS = 180;

    /** Reviews embedded in the Node (the rest through GET /reviews). */
    public const LATEST_REVIEWS = 10;

    private ?RatingSettings $settings = null;

    private ?string $owner = null;

    public function __construct(
        private readonly ReviewsGateway $gateway,
        private readonly FieldReader $fields,
    ) {}

    public function available(): bool
    {
        return $this->gateway->available();
    }

    public function gateway(): ReviewsGateway
    {
        return $this->gateway;
    }

    public function settings(): RatingSettings
    {
        return $this->settings ??= RatingSettings::fromOption($this->fields->get('ratings', Config::OPTIONS_ID));
    }

    public function flags(\WP_Post $post): RatingFlags
    {
        if (! $this->available()) {
            return RatingFlags::off();
        }

        return RatingPolicy::resolve($this->settings(), PageTemplates::slugFor($post), $this->fields->get('ratings', $post->ID));
    }

    /**
     * The Node "rating" object, or null when the node has no ratings.
     *
     * @return array<string, mixed>|null
     */
    public function summary(\WP_Post $post, ?RatingFlags $flags = null): ?array
    {
        $flags ??= $this->flags($post);

        return $flags->any() ? RatingSummary::build($flags, $this->gateway->approved($post->ID)) : null;
    }

    /**
     * {uri, id, ...rating} for /ratings (null when the node has no ratings).
     *
     * @return array<string, mixed>|null
     */
    public function entry(\WP_Post $post, string $uri): ?array
    {
        $summary = $this->summary($post);

        return $summary === null ? null : ['uri' => $uri, 'id' => $post->ID] + $summary;
    }

    /**
     * Keys merged into the Node: "rating" (when enabled) and "reviews" (latest approved reviews with text, when
     * text reviews are enabled; possibly empty).
     *
     * @return array<string, mixed>
     */
    public function forNode(\WP_Post $post): array
    {
        $flags = $this->flags($post);
        if (! $flags->any()) {
            return [];
        }

        $records = $this->gateway->approved($post->ID);
        $node = ['rating' => RatingSummary::build($flags, $records)];
        if ($flags->reviews) {
            $node['reviews'] = $this->normalize(array_slice(self::withText($records), 0, self::LATEST_REVIEWS));
        }

        return $node;
    }

    /**
     * One page of approved reviews with text, newest first.
     *
     * @return array{items: list<array<string, mixed>>, total: int, pages: int}
     */
    public function page(\WP_Post $post, int $page, int $perPage): array
    {
        $records = self::withText($this->gateway->approved($post->ID));
        $perPage = max(1, $perPage);
        $total = count($records);

        return [
            'items' => $this->normalize(array_slice($records, (max(1, $page) - 1) * $perPage, $perPage)),
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
        ];
    }

    /** Name on the owner's responses: the brand of «Ajustes del sitio» (or the site title). */
    public function owner(): string
    {
        if ($this->owner === null) {
            $brand = $this->fields->get('brand', Config::OPTIONS_ID);
            $name = is_array($brand) ? Arr::string($brand, 'name') : '';
            $this->owner = $name !== '' ? $name : html_entity_decode((string) get_bloginfo('name'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $this->owner;
    }

    /**
     * @param  list<ReviewRecord>  $records
     * @return list<ReviewRecord>
     */
    public static function withText(array $records): array
    {
        return array_values(array_filter($records, static fn (ReviewRecord $record): bool => $record->approved && $record->hasText() && $record->hasValidRating()));
    }

    /**
     * @param  list<ReviewRecord>  $records
     * @return list<array<string, mixed>>
     */
    private function normalize(array $records): array
    {
        $owner = $this->owner();

        return array_map(static fn (ReviewRecord $record): array => ReviewNormalizer::normalize($record, $owner), $records);
    }
}
