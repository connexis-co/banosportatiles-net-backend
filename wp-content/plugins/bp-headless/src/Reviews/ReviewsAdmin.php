<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Reviews;

use BanosPortatiles\Headless\Content\PostTypes;
use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * Admin: «Valoración» column (★ 4,7 · 23, linked to the reviews of the post in Site Reviews) in pages, posts and
 * equipos, and the live summary inside the «Valoraciones» box of the editor.
 */
final class ReviewsAdmin implements Hookable
{
    public const SUMMARY_FIELD = 'field_bp_ratings_summary';

    public const POST_TYPES = ['page', 'post', PostTypes::EQUIPO];

    public function __construct(private readonly RatingService $ratings) {}

    public function register(): void
    {
        foreach (self::POST_TYPES as $type) {
            add_filter("manage_{$type}_posts_columns", [$this, 'columns']);
            add_action("manage_{$type}_posts_custom_column", [$this, 'render'], 10, 2);
        }
        add_filter('acf/prepare_field/key='.self::SUMMARY_FIELD, [$this, 'prepareSummary']);
    }

    /**
     * @param  array<string, string>  $columns
     * @return array<string, string>
     */
    public function columns(array $columns): array
    {
        $out = [];
        foreach ($columns as $key => $label) {
            if ($key === 'date') {
                $out['bp_rating'] = 'Valoración';
            }
            $out[$key] = $label;
        }
        $out['bp_rating'] ??= 'Valoración';

        return $out;
    }

    public function render(string $column, int $postId): void
    {
        if ($column !== 'bp_rating') {
            return;
        }
        $post = get_post($postId);
        echo $post instanceof \WP_Post ? $this->summaryHtml($post, true) : '—';
    }

    /**
     * @param  array<string, mixed>|false  $field
     * @return array<string, mixed>|false
     */
    public function prepareSummary(array|false $field): array|false
    {
        if ($field === false) {
            return false;
        }
        $post = get_post();
        if ($post instanceof \WP_Post && $post->post_status !== 'auto-draft') {
            $field['message'] = $this->summaryHtml($post, false);
        }

        return $field;
    }

    private function summaryHtml(\WP_Post $post, bool $compact): string
    {
        if (! $this->ratings->available()) {
            return $compact ? '<span title="Site Reviews no está activo">—</span>' : 'Activa el plugin Site Reviews para recibir valoraciones.';
        }
        $flags = $this->ratings->flags($post);
        if (! $flags->any()) {
            return $compact ? '<span title="Valoraciones desactivadas en esta página">—</span>' : 'Sin valoraciones en esta página (según los ajustes).';
        }

        $gateway = $this->ratings->gateway();
        $summary = RatingSummary::build($flags, $gateway->approved($post->ID));
        $pending = $gateway->pendingCount($post->ID);
        $label = esc_html(RatingSummary::label($summary));
        $link = sprintf('<a href="%s">%s</a>', esc_url($gateway->adminUrl($post->ID)), $label);
        $pendingHtml = $pending > 0 ? sprintf(' <span class="awaiting-mod">%d pendiente%s</span>', $pending, $pending === 1 ? '' : 's') : '';

        if ($compact) {
            return $link.$pendingHtml;
        }

        $enabled = array_keys(array_filter(['estrellas' => $flags->stars, 'opiniones con texto' => $flags->reviews]));

        return sprintf(
            '<p>%s%s</p><p><small>Activo: %s. %d opiniones con texto aprobadas.</small></p>',
            $link,
            $pendingHtml,
            esc_html(implode(' y ', $enabled)),
            is_int($summary['reviewCount']) ? $summary['reviewCount'] : 0
        );
    }
}
