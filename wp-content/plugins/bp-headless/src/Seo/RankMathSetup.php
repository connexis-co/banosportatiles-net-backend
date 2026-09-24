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
 * - separator "|" and the brand as website name the first time only: from then on they belong to the editor
 *   (Rank Math → Títulos y meta) and to the seed import (site.seo), which marks them as applied too,
 * - title/description templates for the "equipo" type (only when missing).
 */
final class RankMathSetup
{
    public const MODULES_ON = ['seo-analysis', 'acf'];

    public const MODULES_OFF = [
        'sitemap', 'rich-snippet', 'redirections', '404-monitor', 'instant-indexing', 'analytics',
        'image-seo', 'link-counter', 'llms-txt', 'ai-visibility',
    ];

    public const SEPARATOR = '|';

    public const TITLES_OPTION = 'rank-math-options-titles';

    /** Site-wide title settings that the setup only seeds once (see DEFAULTS_OPTION). */
    public const SITE_KEYS = ['title_separator', 'website_name'];

    /** SITE_KEYS already applied by the setup or written by the seed import: never overwritten by the setup. */
    public const DEFAULTS_OPTION = 'bp_headless_rankmath_defaults';

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

        $current = get_option(self::TITLES_OPTION, []);
        $titles = self::titleDefaults($siteName, self::appliedDefaults(), is_array($current) ? $current : [], $postTypes);
        $rows[] = $this->group(self::TITLES_OPTION, $titles);
        self::markApplied(array_keys(array_intersect_key($titles, array_flip(self::SITE_KEYS))));

        return $rows;
    }

    /**
     * Title settings to write: separator and website name unless already applied (an empty name is skipped, so a
     * later run with the brand imported sets it), and the post type templates that do not exist yet.
     *
     * @param  list<string>  $applied  SITE_KEYS already applied.
     * @param  array<array-key, mixed>  $current  Stored "rank-math-options-titles".
     * @param  list<string>  $postTypes
     * @return array<string, mixed>
     */
    public static function titleDefaults(string $siteName, array $applied, array $current, array $postTypes): array
    {
        $titles = [];
        if (! in_array('title_separator', $applied, true)) {
            $titles['title_separator'] = self::SEPARATOR;
        }
        $siteName = trim($siteName);
        if ($siteName !== '' && ! in_array('website_name', $applied, true)) {
            $titles['website_name'] = $siteName;
        }
        foreach ($postTypes as $type) {
            foreach (self::POST_TYPE_DEFAULTS as $key => $value) {
                if (! array_key_exists("pt_{$type}_{$key}", $current)) {
                    $titles["pt_{$type}_{$key}"] = $value;
                }
            }
        }

        return $titles;
    }

    /** @return list<string> */
    public static function appliedDefaults(): array
    {
        $applied = get_option(self::DEFAULTS_OPTION, []);

        return is_array($applied) ? array_values(array_intersect(self::SITE_KEYS, $applied)) : [];
    }

    /** @param list<string> $keys */
    public static function markApplied(array $keys): void
    {
        $applied = self::appliedDefaults();
        $merged = array_values(array_intersect(self::SITE_KEYS, array_merge($applied, $keys)));
        if ($merged !== $applied) {
            update_option(self::DEFAULTS_OPTION, $merged, false);
        }
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
        $changed = false;
        foreach ($values as $key => $value) {
            $changed = $changed || ! array_key_exists($key, $stored) || $stored[$key] !== $value;
        }
        if ($changed) {
            update_option($option, $values + $stored);
        }
        if ($values === []) {
            return ['ajuste' => $option, 'valor' => '(ya configurado)', 'estado' => 'sin cambios'];
        }
        $summary = [];
        foreach ($values as $key => $value) {
            $summary[] = $key.'='.(is_array($value) ? '[]' : (is_scalar($value) ? (string) $value : ''));
        }

        return ['ajuste' => $option, 'valor' => implode(', ', $summary), 'estado' => $changed ? 'actualizado' : 'sin cambios'];
    }
}
