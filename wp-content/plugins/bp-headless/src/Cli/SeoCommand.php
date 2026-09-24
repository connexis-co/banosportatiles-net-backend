<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Cli;

use BanosPortatiles\Headless\Cache\ResponseCache;
use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Content\PostTypes;
use BanosPortatiles\Headless\Fields\FieldReader;
use BanosPortatiles\Headless\Routing\UriResolver;
use BanosPortatiles\Headless\Seo\RankMathMigration;
use BanosPortatiles\Headless\Seo\RankMathSetup;
use BanosPortatiles\Headless\Support\Arr;

/**
 * wp bp setup rankmath | wp bp seo migrate-rankmath
 */
final class SeoCommand
{
    public function __construct(
        private readonly FieldReader $fields,
        private readonly UriResolver $uris,
        private readonly ResponseCache $cache,
    ) {}

    public static function register(self $command): void
    {
        \WP_CLI::add_command('bp setup rankmath', [$command, 'setup'], [
            'shortdesc' => 'Configura Rank Math para el sitio headless (sin asistente, headless support, módulos, plantillas del CPT equipo y, solo la primera vez, separador y nombre del sitio). Idempotente.',
        ]);
        \WP_CLI::add_command('bp seo migrate-rankmath', [$command, 'migrate'], [
            'shortdesc' => 'Copia el SEO del grupo SCF «SEO» a Rank Math (título, descripción, keywords, canonical, noindex e imagen social).',
            'synopsis' => [
                ['type' => 'flag', 'name' => 'dry-run', 'description' => 'Muestra lo que copiaría sin escribir nada.', 'optional' => true],
                ['type' => 'flag', 'name' => 'force', 'description' => 'Sobrescribe también los campos que ya tienen valor en Rank Math.', 'optional' => true],
            ],
        ]);
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string|bool>  $assoc
     */
    public function setup(array $args, array $assoc): void
    {
        // The brand of «Ajustes del sitio» (not the CMS title): empty before the first seed import, which then
        // writes site.seo → Rank Math itself.
        $brand = $this->fields->get('brand', Config::OPTIONS_ID);
        $name = is_array($brand) ? Arr::string($brand, 'name') : '';

        try {
            $rows = (new RankMathSetup)->apply($name, [PostTypes::EQUIPO]);
        } catch (\RuntimeException $e) {
            \WP_CLI::error($e->getMessage());

            return;
        }
        \WP_CLI\Utils\format_items('table', $rows, ['ajuste', 'valor', 'estado']);
        $this->cache->flush();
        \WP_CLI::success('Rank Math listo para el sitio headless.');
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string|bool>  $assoc
     */
    public function migrate(array $args, array $assoc): void
    {
        $dryRun = (bool) \WP_CLI\Utils\get_flag_value($assoc, 'dry-run', false);
        $force = (bool) \WP_CLI\Utils\get_flag_value($assoc, 'force', false);

        $posts = get_posts([
            'post_type' => UriResolver::NODE_TYPES,
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'numberposts' => -1,
            'orderby' => ['type' => 'ASC', 'ID' => 'ASC'],
            'no_found_rows' => true,
        ]);

        $rows = [];
        $total = 0;
        foreach ($posts as $post) {
            $seo = $this->fields->get('seo', $post->ID);
            if (! is_array($seo)) {
                continue;
            }
            $ogImageId = Arr::ids($seo['og_image'] ?? null)[0] ?? 0;
            $url = $ogImageId > 0 ? wp_get_attachment_url($ogImageId) : false;
            $target = RankMathMigration::fromScf($seo, is_string($url) ? $url : '');
            $current = [];
            foreach (RankMathMigration::META_KEYS as $key) {
                $current[$key] = get_post_meta($post->ID, $key, true);
            }
            $changes = RankMathMigration::plan($target, $current, $force);
            if ($changes === []) {
                continue;
            }
            $total += count($changes);
            $rows[] = [
                'id' => $post->ID,
                'uri' => $this->uris->forPost($post, true) ?? '',
                'campos' => implode(', ', array_map(static fn (string $key): string => substr($key, strlen('rank_math_')), array_keys($changes))),
            ];
            if (! $dryRun) {
                foreach ($changes as $key => $value) {
                    update_post_meta($post->ID, $key, $value);
                }
            }
        }

        if ($rows !== []) {
            \WP_CLI\Utils\format_items('table', $rows, ['id', 'uri', 'campos']);
        }
        if ($dryRun) {
            \WP_CLI::success(sprintf('Simulación: %d campos en %d contenidos (no se escribió nada).', $total, count($rows)));

            return;
        }
        $this->cache->flush();
        \WP_CLI::success(sprintf('%d campos copiados a Rank Math en %d contenidos%s.', $total, count($rows), $force ? ' (--force)' : ''));
    }
}
