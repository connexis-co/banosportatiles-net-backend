<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Import;

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Content\PostTypes;
use BanosPortatiles\Headless\Content\Taxonomies;
use BanosPortatiles\Headless\Fields\FieldBuilder;
use BanosPortatiles\Headless\Redirects\RedirectionRepository;
use BanosPortatiles\Headless\Routing\UriResolver;
use BanosPortatiles\Headless\Seo\RankMathSeoWriter;
use BanosPortatiles\Headless\Support\Arr;

/**
 * Idempotent seed import (upsert by "_bp_seed_key"; unchanged items are skipped by content hash).
 *
 * Pass 1: site options → ciudades → categorías → FAQs → redirects → equipos → posts → pages (by depth).
 * Pass 2: relations that need every object to exist (sections, FAQ refs, blog relations, category pillars).
 */
final class SeedImporter implements SeedLookup
{
    public const SEED_KEY = '_bp_seed_key';

    public const SEED_HASH = '_bp_seed_hash';

    /** @var array<string, int> uri → page ID */
    private array $pages = [];

    /** @var array<string, int> slug → post ID */
    private array $posts = [];

    /** @var array<string, int> */
    private array $equipos = [];

    /** @var array<string, int> seed id → faq ID */
    private array $faqs = [];

    /** @var array<string, int> slug → term ID */
    private array $ciudades = [];

    /** @var array<string, int> */
    private array $categories = [];

    private ImportReport $report;

    private MediaImporter $media;

    private FieldValueMapper $mapper;

    private bool $dryRun = false;

    private bool $force = false;

    public function __construct(
        private readonly UriResolver $uris,
        private readonly RedirectionRepository $redirects,
        private readonly RankMathSeoWriter $rankMath,
    ) {
        $this->report = new ImportReport;
        $this->media = new MediaImporter(null, true, $this->report);
        $this->mapper = new FieldValueMapper($this);
    }

    public function run(Bundle $bundle, ?string $assetsDir, bool $dryRun, bool $force, ImportReport $report): void
    {
        $errors = $bundle->errors();
        if ($errors !== []) {
            throw new \RuntimeException("Bundle inválido:\n- ".implode("\n- ", $errors));
        }

        $this->report = $report;
        $this->dryRun = $dryRun;
        $this->force = $force;
        $this->media = new MediaImporter($assetsDir, $dryRun, $report);
        $this->mapper = new FieldValueMapper($this, $report->warn(...));
        $this->pages = $this->posts = $this->equipos = $this->faqs = $this->ciudades = $this->categories = [];

        $this->importCiudades($bundle->list('ciudades'));
        $this->importCategorias($bundle->list('categorias'));
        $this->importFaqs($bundle->list('faqs'));
        $this->importSite($bundle->site());
        $this->importRedirects($bundle->list('redirects'));
        $this->importEquipos($bundle->list('equipos'));
        $this->importPosts($bundle->list('posts'));
        $this->importPages($bundle->list('pages'));

        if ($this->dryRun) {
            return;
        }

        $this->linkPages($bundle->list('pages'));
        $this->linkPosts($bundle->list('posts'));
        $this->linkCategorias($bundle->list('categorias'));
        $this->setFrontPage();
    }

    // --- SeedLookup -------------------------------------------------------------------------

    public function equipoId(string $slug): ?int
    {
        return $this->equipos[$slug] ?? $this->findPostId(PostTypes::EQUIPO, $slug);
    }

    public function pageId(string $uri): ?int
    {
        $uri = UriResolver::normalize($uri);

        return $this->pages[$uri] ?? $this->uris->resolve($uri, true)?->ID;
    }

    public function postId(string $slug): ?int
    {
        return $this->posts[$slug] ?? $this->findPostId('post', $slug);
    }

    public function ciudadId(string $slug): ?int
    {
        if (isset($this->ciudades[$slug])) {
            return $this->ciudades[$slug];
        }
        $term = get_term_by('slug', $slug, Taxonomies::CIUDAD);

        return $term instanceof \WP_Term ? $term->term_id : null;
    }

    public function faqId(string $id): ?int
    {
        return $this->faqs[$id] ?? $this->findPostId(PostTypes::FAQ, $id);
    }

    public function imageId(mixed $image): ?int
    {
        return $this->media->import($image);
    }

    // --- Pass 1 -------------------------------------------------------------------------------

    /** @param list<array<array-key, mixed>> $items */
    private function importCiudades(array $items): void
    {
        $builder = new FieldBuilder('ciudad');
        foreach ($items as $item) {
            $result = $this->upsertTerm(Taxonomies::CIUDAD, $item, Arr::string($item, 'descripcion'), 'ciudad');
            if ($result !== null) {
                $this->ciudades[Arr::string($item, 'slug')] = $result['id'];
                if ($result['changed']) {
                    $this->writeFields($builder, $this->mapper->ciudad($item), 'term_'.$result['id']);
                }
            }
        }
    }

    /** @param list<array<array-key, mixed>> $items */
    private function importCategorias(array $items): void
    {
        foreach ($items as $item) {
            $result = $this->upsertTerm('category', $item, Arr::string($item, 'description'), 'categoria');
            if ($result !== null) {
                $this->categories[Arr::string($item, 'slug')] = $result['id'];
            }
        }
    }

    /** @param list<array<array-key, mixed>> $items */
    private function importFaqs(array $items): void
    {
        foreach ($items as $index => $item) {
            $seedId = Arr::string($item, 'id');
            $id = $this->upsertPost(PostTypes::FAQ, 'faq:'.$seedId, $item, [
                'post_title' => Arr::string($item, 'q'),
                'post_name' => sanitize_title($seedId),
                'post_content' => Arr::string($item, 'a'),
                'menu_order' => ($index + 1) * 10,
            ], $this->findPostId(PostTypes::FAQ, $seedId), null, false);

            if ($id === null) {
                continue;
            }
            $this->faqs[$seedId] = $id;
            if (! $this->dryRun) {
                $termIds = [];
                foreach (Arr::strings($item, 'temas') as $tema) {
                    $termId = $this->ensureTerm(Taxonomies::TEMA_FAQ, sanitize_title($tema), ucfirst(str_replace('-', ' ', $tema)));
                    if ($termId !== null) {
                        $termIds[] = $termId;
                    }
                }
                wp_set_object_terms($id, $termIds, Taxonomies::TEMA_FAQ);
            }
        }
    }

    /** @param array<array-key, mixed> $site */
    private function importSite(array $site): void
    {
        if ($site === []) {
            return;
        }
        $hash = md5(serialize($site));
        if (! $this->force && get_option('bp_headless_seed_site_hash') === $hash) {
            $this->report->count('ajustes', 'unchanged');

            return;
        }
        $this->report->count('ajustes', 'updated');
        if ($this->dryRun) {
            return;
        }

        $this->writeFields(new FieldBuilder('site'), $this->mapper->site($site), Config::OPTIONS_ID);
        $this->importSaleBanner(Arr::array($site, 'sale_banner'));
        $name = Arr::string(Arr::array($site, 'brand'), 'name');
        if ($name !== '') {
            update_option('blogname', $name);
        }
        $tagline = Arr::string(Arr::array($site, 'brand'), 'tagline');
        if ($tagline !== '') {
            update_option('blogdescription', $tagline);
        }
        $seo = Arr::array($site, 'seo');
        $siteName = Arr::string($seo, 'siteName');
        $this->rankMath->writeSite($siteName !== '' ? $siteName : $name, Arr::string($seo, 'separator'));
        update_option('bp_headless_seed_site_hash', $hash, false);
    }

    /**
     * site.sale_banner → option of the bp-sitio-en-venta plugin (the single source for /site → sale_banner).
     *
     * @param  array<array-key, mixed>  $banner
     */
    private function importSaleBanner(array $banner): void
    {
        if ($banner === []) {
            return;
        }
        $result = apply_filters('bp_sitio_en_venta/import', null, $banner);
        if (! is_array($result)) {
            $this->report->warn('site.sale_banner omitido: activa el plugin «Sitio en venta» (bp-sitio-en-venta).');

            return;
        }
        foreach ([...Arr::array($result, 'errors'), ...Arr::array($result, 'warnings')] as $field => $message) {
            $this->report->warn('sale_banner.'.$field.': '.(is_scalar($message) ? (string) $message : ''));
        }
        $this->report->count('aviso de venta', Arr::array($result, 'errors') === [] ? 'updated' : 'error');
    }

    /** @param list<array<array-key, mixed>> $items */
    private function importRedirects(array $items): void
    {
        if ($items === []) {
            return;
        }
        if (! $this->redirects->available()) {
            $this->report->warn('Redirection no está activo: se omiten '.count($items).' redirecciones.');

            return;
        }
        foreach ($items as $item) {
            $code = Arr::int($item, 'code', 301);
            $code = in_array($code, RedirectionRepository::CODES, true) ? $code : 301;
            $result = $this->dryRun ? 'skipped' : $this->redirects->upsert(Arr::string($item, 'from'), Arr::string($item, 'to'), $code);
            $this->report->count('redirecciones', $result);
        }
    }

    /** @param list<array<array-key, mixed>> $items */
    private function importEquipos(array $items): void
    {
        $builder = new FieldBuilder('equipo');
        foreach ($items as $item) {
            $slug = Arr::string($item, 'slug');
            $id = $this->upsertPost(PostTypes::EQUIPO, 'equipo:'.$slug, $item, [
                'post_title' => Arr::string($item, 'title'),
                'post_name' => $slug,
                'post_excerpt' => Arr::string($item, 'excerpt'),
                'menu_order' => Arr::int($item, 'order'),
            ], $this->findPostId(PostTypes::EQUIPO, $slug), function (int $id) use ($item, $builder): void {
                $this->writeCommon($id, $item);
                $this->writeFields($builder, $this->mapper->equipo($item), $id);
                $this->assignCiudades($id, $item);
            });
            if ($id !== null) {
                $this->equipos[$slug] = $id;
            }
        }
    }

    /** @param list<array<array-key, mixed>> $items */
    private function importPosts(array $items): void
    {
        $builder = new FieldBuilder('blog');
        foreach ($items as $item) {
            $slug = Arr::string($item, 'slug');
            $published = Arr::string($item, 'published');
            $id = $this->upsertPost('post', 'post:'.$slug, $item, array_filter([
                'post_title' => Arr::string($item, 'title'),
                'post_name' => $slug,
                'post_excerpt' => Arr::string($item, 'excerpt'),
                'post_date' => self::isDate($published) ? substr($published, 0, 10).' 08:00:00' : null,
            ], static fn (mixed $v): bool => $v !== null), $this->findPostId('post', $slug), function (int $id) use ($item, $builder): void {
                $this->writeCommon($id, $item);
                $this->writeFields($builder, $this->mapper->blog($item), $id);
                $category = Arr::string($item, 'category');
                if ($category !== '') {
                    $termId = $this->categories[$category] ?? null;
                    $termId !== null ? wp_set_post_categories($id, [$termId]) : $this->report->warn('Post '.Arr::string($item, 'slug').": categoría desconocida «{$category}».");
                }
            });
            if ($id !== null) {
                $this->posts[$slug] = $id;
                if (! $this->dryRun) {
                    $this->setModified($id, Arr::string($item, 'modified'));
                }
            }
        }
    }

    /** @param list<array<array-key, mixed>> $items */
    private function importPages(array $items): void
    {
        usort($items, static function (array $a, array $b): int {
            $depth = static fn (array $page): int => substr_count(UriResolver::normalize(Arr::string($page, 'uri')), '/');

            return [$depth($a), Arr::int($a, 'order')] <=> [$depth($b), Arr::int($b, 'order')];
        });

        foreach ($items as $item) {
            $uri = UriResolver::normalize(Arr::string($item, 'uri'));
            $parentUri = Arr::string($item, 'parentUri') !== '' ? UriResolver::normalize(Arr::string($item, 'parentUri')) : self::parentOf($uri);
            $parentId = 0;
            if ($parentUri !== '/') {
                $parentId = $this->pageId($parentUri) ?? 0;
                if ($parentId === 0 && ! $this->dryRun) {
                    $this->report->warn("Página {$uri}: no existe el padre {$parentUri}; se crea en la raíz.");
                }
            }

            $slug = $uri === '/' ? (Arr::string($item, 'slug') ?: 'inicio') : basename(rtrim($uri, '/'));
            $id = $this->upsertPost('page', 'page:'.$uri, $item, [
                'post_title' => Arr::string($item, 'title'),
                'post_name' => $slug,
                'post_parent' => $parentId,
                'post_excerpt' => Arr::string($item, 'excerpt'),
                'menu_order' => Arr::int($item, 'order'),
            ], $this->uris->resolve($uri, true)?->ID, function (int $id) use ($item): void {
                update_post_meta($id, '_wp_page_template', Arr::string($item, 'template'));
                $this->writeCommon($id, $item);
                $hero = Arr::array($item, 'hero');
                if ($hero !== []) {
                    update_field((new FieldBuilder)->key('hero'), $this->mapper->hero($hero), $id);
                }
                $this->assignCiudades($id, $item);
            });

            if ($id !== null) {
                $this->pages[$uri] = $id;
            }
        }
    }

    // --- Pass 2 -------------------------------------------------------------------------------

    /** @param list<array<array-key, mixed>> $items */
    private function linkPages(array $items): void
    {
        $root = new FieldBuilder;
        foreach ($items as $item) {
            $id = $this->pages[UriResolver::normalize(Arr::string($item, 'uri'))] ?? null;
            if ($id === null) {
                continue;
            }
            update_field($root->key('sections'), $this->mapper->sections(Arr::rows($item, 'sections')), $id);
            update_field($root->key('faq_refs'), $this->mapper->faqRefs($item), $id);
        }
    }

    /** @param list<array<array-key, mixed>> $items */
    private function linkPosts(array $items): void
    {
        $builder = new FieldBuilder('blog');
        $root = new FieldBuilder;
        foreach ($items as $item) {
            $id = $this->posts[Arr::string($item, 'slug')] ?? null;
            if ($id !== null) {
                $this->writeFields($builder, $this->mapper->blogRelations($item), $id);
                update_field($root->key('faq_refs'), $this->mapper->faqRefs($item), $id);
            }
        }
    }

    /** @param list<array<array-key, mixed>> $items */
    private function linkCategorias(array $items): void
    {
        $key = (new FieldBuilder('category'))->key('pillar');
        foreach ($items as $item) {
            $termId = $this->categories[Arr::string($item, 'slug')] ?? null;
            $pillar = Arr::string($item, 'pillar');
            if ($termId !== null && $pillar !== '') {
                $postId = $this->postId($pillar);
                $postId !== null ? update_field($key, $postId, 'term_'.$termId) : $this->report->warn('Categoría '.Arr::string($item, 'slug').": pilar desconocido «{$pillar}».");
            }
        }
    }

    private function setFrontPage(): void
    {
        $home = $this->pages['/'] ?? null;
        if ($home !== null) {
            update_option('show_on_front', 'page');
            update_option('page_on_front', $home);
        }
    }

    // --- Helpers ------------------------------------------------------------------------------

    /**
     * Upsert by seed key (falls back to adopting an existing object at the same slug/URI).
     *
     * @param  array<array-key, mixed>  $item  Seed item (hashed to skip unchanged content).
     * @param  array<string, mixed>  $postarr
     * @param  (callable(int): void)|null  $afterWrite
     * @param  bool  $withContent  Render item.contentHtml (local images → media library) into post_content.
     */
    private function upsertPost(string $type, string $seedKey, array $item, array $postarr, ?int $fallbackId, ?callable $afterWrite = null, bool $withContent = true): ?int
    {
        $entity = match ($type) {
            'page' => 'páginas',
            'post' => 'posts',
            PostTypes::EQUIPO => 'equipos',
            PostTypes::FAQ => 'faqs',
            default => $type,
        };
        $existing = $this->findBySeedKey($type, $seedKey) ?? $fallbackId;
        $hash = md5(serialize($item));

        if ($existing !== null && ! $this->force && get_post_meta($existing, self::SEED_HASH, true) === $hash) {
            $this->report->count($entity, 'unchanged');

            return $existing;
        }
        if ($this->dryRun) {
            $this->report->count($entity, $existing !== null ? 'updated' : 'created');

            return $existing;
        }

        $postarr += [
            'post_type' => $type,
            'post_status' => in_array(Arr::string($item, 'status'), ['draft', 'private'], true) ? Arr::string($item, 'status') : 'publish',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        ];
        if ($existing !== null) {
            $postarr['ID'] = $existing;
        }
        if ($withContent) {
            $postarr['post_content'] = $this->localizeImages(Arr::string($item, 'contentHtml'), $existing ?? 0);
        }

        $id = wp_insert_post(wp_slash($postarr), true);
        if (is_wp_error($id)) {
            $this->report->warn("{$entity} {$seedKey}: ".$id->get_error_message());
            $this->report->count($entity, 'error');

            return null;
        }

        if ($afterWrite !== null) {
            $afterWrite($id);
        }
        update_post_meta($id, self::SEED_KEY, $seedKey);
        update_post_meta($id, self::SEED_HASH, $hash);
        $this->report->count($entity, $existing !== null ? 'updated' : 'created');

        return $id;
    }

    /**
     * Featured image, SEO and inline FAQs (shared by pages, posts and equipos). Price, schema type, TOC and the
     * rating override are written only when the seed item declares them: otherwise WordPress keeps its values.
     *
     * @param  array<array-key, mixed>  $item
     */
    private function writeCommon(int $id, array $item): void
    {
        $image = $item['image'] ?? null;
        $imageId = $image !== null ? $this->media->import($image, $id) : null;
        $imageId !== null ? set_post_thumbnail($id, $imageId) : delete_post_thumbnail($id);

        $root = new FieldBuilder;
        $seo = $this->mapper->seo(Arr::array($item, 'seo'));
        update_field($root->key('seo'), $seo, $id);
        $this->rankMath->write($id, $seo);
        update_field($root->key('faqs'), $this->mapper->faqs($item), $id);

        $price = $this->mapper->price($item);
        if ($price !== null) {
            update_field($root->key('price'), $price, $id);
        }
        $schemaType = $this->mapper->schemaType($item);
        if ($schemaType !== null) {
            update_field($root->key('schema_type'), $schemaType, $id);
        }
        $toc = $this->mapper->toc($item);
        if ($toc !== null) {
            update_field($root->key('toc'), $toc, $id);
        }
        $ratings = $this->mapper->ratings($item);
        if ($ratings !== null) {
            update_field($root->key('ratings'), $ratings, $id);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function writeFields(FieldBuilder $builder, array $values, int|string $objectId): void
    {
        if ($this->dryRun) {
            return;
        }
        foreach ($values as $name => $value) {
            update_field($builder->key($name), $value, $objectId);
        }
    }

    /** @param array<array-key, mixed> $item */
    private function assignCiudades(int $id, array $item): void
    {
        $slugs = array_filter([Arr::string($item, 'ciudad'), ...Arr::strings($item, 'ciudades')]);
        $ids = [];
        foreach ($slugs as $slug) {
            $termId = $this->ciudadId($slug);
            $termId !== null ? $ids[] = $termId : $this->report->warn("Ciudad desconocida «{$slug}» (post {$id}).");
        }
        wp_set_object_terms($id, $ids, Taxonomies::CIUDAD);
    }

    /** Replaces local <img src> paths with sideloaded attachments (wp-image-{id}, width, height). */
    private function localizeImages(string $html, int $parentId): string
    {
        if ($html === '' || ! str_contains($html, '<img')) {
            return $html;
        }

        $processor = new \WP_HTML_Tag_Processor($html);
        while ($processor->next_tag(['tag_name' => 'img'])) {
            $src = $processor->get_attribute('src');
            if (! is_string($src) || $src === '' || preg_match('#^(https?:)?//|^data:#i', $src) === 1) {
                continue;
            }
            $alt = $processor->get_attribute('alt');
            $id = $this->media->import(['src' => $src, 'alt' => is_string($alt) ? $alt : ''], $parentId);
            $url = $id !== null ? wp_get_attachment_url($id) : false;
            if ($id === null || ! is_string($url)) {
                continue;
            }
            $processor->set_attribute('src', $url);
            $processor->add_class('wp-image-'.$id);
            $meta = wp_get_attachment_metadata($id);
            if (is_array($meta) && isset($meta['width'], $meta['height'])) {
                $processor->set_attribute('width', (string) $meta['width']);
                $processor->set_attribute('height', (string) $meta['height']);
            }
        }

        return $processor->get_updated_html();
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @return array{id: int, changed: bool}|null
     */
    private function upsertTerm(string $taxonomy, array $item, string $description, string $entity): ?array
    {
        $slug = Arr::string($item, 'slug');
        $name = Arr::string($item, 'name');
        $hash = md5(serialize($item));
        $existing = get_term_by('slug', $slug, $taxonomy);

        if ($existing instanceof \WP_Term && ! $this->force && get_term_meta($existing->term_id, self::SEED_HASH, true) === $hash) {
            $this->report->count($entity, 'unchanged');

            return ['id' => $existing->term_id, 'changed' => false];
        }
        if ($this->dryRun) {
            $this->report->count($entity, $existing instanceof \WP_Term ? 'updated' : 'created');

            return $existing instanceof \WP_Term ? ['id' => $existing->term_id, 'changed' => false] : null;
        }

        $result = $existing instanceof \WP_Term
            ? wp_update_term($existing->term_id, $taxonomy, ['name' => $name, 'description' => $description])
            : wp_insert_term($name, $taxonomy, ['slug' => $slug, 'description' => $description]);

        if (is_wp_error($result)) {
            $this->report->warn("{$entity} {$slug}: ".$result->get_error_message());
            $this->report->count($entity, 'error');

            return null;
        }
        $this->report->count($entity, $existing instanceof \WP_Term ? 'updated' : 'created');
        update_term_meta((int) $result['term_id'], self::SEED_HASH, $hash);

        return ['id' => (int) $result['term_id'], 'changed' => true];
    }

    private function ensureTerm(string $taxonomy, string $slug, string $name): ?int
    {
        $term = get_term_by('slug', $slug, $taxonomy);
        if ($term instanceof \WP_Term) {
            return $term->term_id;
        }
        $result = wp_insert_term($name, $taxonomy, ['slug' => $slug]);

        return is_wp_error($result) ? null : (int) $result['term_id'];
    }

    private function findBySeedKey(string $type, string $seedKey): ?int
    {
        $ids = get_posts([
            'post_type' => $type,
            'post_status' => 'any',
            'meta_key' => self::SEED_KEY,
            'meta_value' => $seedKey,
            'fields' => 'ids',
            'numberposts' => 1,
            'no_found_rows' => true,
        ]);
        $id = $ids[0] ?? null;

        return is_int($id) ? $id : null;
    }

    private function findPostId(string $type, string $slug): ?int
    {
        $ids = get_posts([
            'post_type' => $type,
            'name' => $slug,
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'fields' => 'ids',
            'numberposts' => 1,
            'no_found_rows' => true,
        ]);
        $id = $ids[0] ?? null;

        return is_int($id) ? $id : null;
    }

    private function setModified(int $id, string $modified): void
    {
        global $wpdb;
        if (! self::isDate($modified) || ! $wpdb instanceof \wpdb) {
            return;
        }
        $local = substr($modified, 0, 10).' 08:00:00';
        $wpdb->update($wpdb->posts, ['post_modified' => $local, 'post_modified_gmt' => get_gmt_from_date($local)], ['ID' => $id]);
        clean_post_cache($id);
    }

    private static function parentOf(string $uri): string
    {
        $segments = explode('/', trim($uri, '/'));
        array_pop($segments);

        return $segments === [] ? '/' : '/'.implode('/', $segments).'/';
    }

    private static function isDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1;
    }
}
