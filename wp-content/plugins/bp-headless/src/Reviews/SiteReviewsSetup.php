<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Reviews;

use GeminiLabs\SiteReviews\Database\OptionManager;

/**
 * `wp bp setup reviews`: Site Reviews configured for a headless site (idempotent). Moderation on, admin email
 * notifications, no verification emails, avatars or geolocation (privacy), no duplicate/limit checks of its own
 * (bp-headless deduplicates per voter) and the two categories. Front assets and JSON-LD are switched off in
 * code (ReviewsWriteGuard), since the CMS front is never rendered.
 */
final class SiteReviewsSetup
{
    /** @var array<string, string|int|list<string>> */
    public const SETTINGS = [
        'settings.general.require.approval' => 'yes',
        'settings.general.require.approval_for' => 5,
        'settings.general.notifications' => ['admin'],
        'settings.general.request_verification' => 'no',
        'settings.reviews.avatars' => 'no',
        'settings.reviews.geolocation' => 'no',
        'settings.forms.prevent_duplicates' => 'no',
        'settings.forms.limit' => '',
    ];

    public function __construct(private readonly SiteReviewsGateway $gateway) {}

    /**
     * @return list<array{ajuste: string, valor: string, estado: string}>
     */
    public function apply(): array
    {
        if (! SiteReviewsGateway::isActive() || ! function_exists('glsr')) {
            throw new \RuntimeException('Site Reviews no está activo: wp plugin activate site-reviews.');
        }
        $options = glsr(OptionManager::class);
        if (! $options instanceof OptionManager) {
            throw new \RuntimeException('No se pudo leer la configuración de Site Reviews.');
        }

        $rows = [];
        foreach (self::SETTINGS as $path => $value) {
            $current = $options->get($path);
            $same = is_array($value) ? (is_array($current) && array_values($current) === $value) : (string) (is_scalar($current) ? $current : '') === (string) $value;
            if (! $same) {
                $options->set($path, $value);
            }
            $rows[] = ['ajuste' => $path, 'valor' => is_array($value) ? implode(',', $value) : (string) $value, 'estado' => $same ? 'sin cambios' : 'actualizado'];
        }

        foreach (SiteReviewsGateway::TERMS as $slug => $name) {
            $existed = get_term_by('slug', $slug, SiteReviewsGateway::TAXONOMY) instanceof \WP_Term;
            $ids = $this->gateway->termIds([$slug]);
            $rows[] = ['ajuste' => 'categoría «'.$name.'»', 'valor' => $slug, 'estado' => $existed ? 'sin cambios' : ($ids !== [] ? 'creada' : 'error')];
        }

        return $rows;
    }
}
