<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Seo;

use RankMath\Helper;

/**
 * `wp bp setup rankmath`: Rank Math for a headless site (idempotent; works right after activation, before
 * Rank Math has finished its own set-up):
 *
 * - no registration/wizard gate (without it Rank Math does not even load its variables),
 * - "Headless CMS support" (/rankmath/v1/getHead) on,
 * - SEO analysis + ACF modules on; sitemap, schema, redirections, 404 monitor, instant indexing, analytics,
 *   image SEO, link counter, llms.txt and AI visibility off (the Astro site does those, Redirection keeps the 301),
 * - separator "|" and the brand as website name, and title/description templates for the "equipo" type.
 */
final class RankMathSetup
{
    public const MODULES_ON = ['seo-analysis', 'acf'];

    public const MODULES_OFF = [
        'sitemap', 'rich-snippet', 'redirections', '404-monitor', 'instant-indexing', 'analytics',
        'image-seo', 'link-counter', 'llms-txt', 'ai-visibility',
    ];

    public const SEPARATOR = '|';

    /** Post type templates created when Rank Math was installed before the type existed. */
    public const POST_TYPE_DEFAULTS = [
        'title' => RankMathSeoSource::DEFAULT_TITLE,
        'description' => RankMathSeoSource::DEFAULT_DESCRIPTION,
        'custom_robots' => 'off',
        'robots' => [],
        'advanced_robots' => [],
        'add_meta_box' => 'on',
        'bulk_editing' => 'editing',
        'ls_use_fk' => 'titles',
    ];

    /**
     * @param  list<string>  $postTypes  Public post types of bp-headless that need templates (e.g. "equipo").
     * @return list<array{ajuste: string, valor: string, estado: string}>
     */
    public function apply(string $siteName, array $postTypes): array
    {
        if (! defined('RANK_MATH_VERSION') && ! class_exists(Helper::class)) {
            throw new \RuntimeException('Rank Math no está activo: wp plugin activate seo-by-rank-math.');
        }

        $rows = [];
        foreach (['rank_math_registration_skip' => '1', 'rank_math_wizard_completed' => '1', 'rank_math_is_configured' => '1'] as $option => $value) {
            $rows[] = $this->option($option, $value);
        }
        delete_transient('_rank_math_activation_redirect');

        $stored = get_option('rank_math_modules', []);
        $modules = self::modules(is_array($stored) ? $stored : []);
        $changed = $modules !== (is_array($stored) ? array_values($stored) : []);
        if ($changed) {
            update_option('rank_math_modules', $modules);
            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions('rank_math/analytics/data_fetch');
            }
        }
        $rows[] = ['ajuste' => 'módulos', 'valor' => implode(', ', $modules), 'estado' => $changed ? 'actualizado' : 'sin cambios'];

        $rows[] = $this->group('rank-math-options-general', ['headless_support' => 'on']);

        $titles = ['title_separator' => self::SEPARATOR, 'website_name' => $siteName];
        $current = get_option('rank-math-options-titles', []);
        foreach ($postTypes as $type) {
            foreach (self::POST_TYPE_DEFAULTS as $key => $value) {
                if (! is_array($current) || ! array_key_exists("pt_{$type}_{$key}", $current)) {
                    $titles["pt_{$type}_{$key}"] = $value;
                }
            }
        }
        $rows[] = $this->group('rank-math-options-titles', $titles);

        return $rows;
    }

    /**
     * Stored module list with the required ones on and the unused ones off (order kept).
     *
     * @param  array<array-key, mixed>  $stored
     * @return list<string>
     */
    public static function modules(array $stored): array
    {
        $modules = array_values(array_filter($stored, 'is_string'));
        foreach (self::MODULES_ON as $module) {
            if (! in_array($module, $modules, true)) {
                $modules[] = $module;
            }
        }

        return array_values(array_diff($modules, self::MODULES_OFF));
    }

    /** @return array{ajuste: string, valor: string, estado: string} */
    private function option(string $name, string $value): array
    {
        $same = (string) get_option($name, '') === $value;
        if (! $same) {
            update_option($name, $value, false);
        }

        return ['ajuste' => $name, 'valor' => $value, 'estado' => $same ? 'sin cambios' : 'actualizado'];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{ajuste: string, valor: string, estado: string}
     */
    private function group(string $option, array $values): array
    {
        $stored = get_option($option, []);
        $stored = is_array($stored) ? $stored : [];
        $merged = $values + $stored;
        $changed = array_intersect_key($stored, $values) !== $values;
        if ($changed) {
            update_option($option, $merged);
        }
        $summary = [];
        foreach ($values as $key => $value) {
            $summary[] = $key.'='.(is_array($value) ? '[]' : (is_scalar($value) ? (string) $value : ''));
        }

        return ['ajuste' => $option, 'valor' => implode(', ', $summary), 'estado' => $changed ? 'actualizado' : 'sin cambios'];
    }
}
