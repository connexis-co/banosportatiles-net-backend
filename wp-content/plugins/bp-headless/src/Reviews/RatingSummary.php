<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Reviews;

/**
 * Approved records of a node → the Node "rating" object (contract §3.1). Pure: the same records always give
 * the same numbers, so the node, /ratings and the admin column agree.
 *
 *   {stars, reviews, count, average, best: 5, worst: 1, distribution: {"1".."5"}, reviewCount, updated?}
 */
final class RatingSummary
{
    public const BEST = 5;

    public const WORST = 1;

    /**
     * @param  list<ReviewRecord>  $approved  Approved records (votes and reviews) of the node.
     * @return array<string, mixed>
     */
    public static function build(RatingFlags $flags, array $approved): array
    {
        $distribution = array_fill_keys(range(self::WORST, self::BEST), 0);
        $sum = 0;
        $count = 0;
        $withText = 0;
        $updated = null;

        foreach ($approved as $record) {
            if (! $record->approved || ! $record->hasValidRating()) {
                continue;
            }
            $distribution[$record->rating]++;
            $sum += $record->rating;
            $count++;
            if ($record->hasText()) {
                $withText++;
            }
            if ($record->date !== null && ($updated === null || $record->date > $updated)) {
                $updated = $record->date;
            }
        }

        $summary = [
            'stars' => $flags->stars,
            'reviews' => $flags->reviews,
            'count' => $count,
            'average' => $count > 0 ? round($sum / $count, 1) : 0.0,
            'best' => self::BEST,
            'worst' => self::WORST,
            'distribution' => $distribution,
            'reviewCount' => $withText,
        ];
        if ($updated !== null) {
            $summary['updated'] = $updated->format(DATE_ATOM);
        }

        return $summary;
    }

    /**
     * Short label for the admin: "★ 4,7 · 23" (Colombian decimal comma) or "Sin votos".
     *
     * @param  array<string, mixed>  $summary
     */
    public static function label(array $summary): string
    {
        $count = is_int($summary['count'] ?? null) ? $summary['count'] : 0;
        if ($count === 0) {
            return 'Sin votos';
        }
        $average = is_numeric($summary['average'] ?? null) ? (float) $summary['average'] : 0.0;

        return '★ '.number_format($average, 1, ',', '.').' · '.$count;
    }
}
