<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Normalizer;

use BanosPortatiles\Headless\Content\PageTemplates;
use BanosPortatiles\Headless\Content\PostTypes;
use BanosPortatiles\Headless\Content\Taxonomies;
use BanosPortatiles\Headless\Fields\FieldReader;
use BanosPortatiles\Headless\Html\ContentRenderer;
use BanosPortatiles\Headless\Routing\UriResolver;
use BanosPortatiles\Headless\Support\Arr;

/**
 * WP_Post (page | post | equipo) → the "Node" object of the API contract (plan §5).
 */
final class NodeNormalizer
{
    public const WORDS_PER_MINUTE = 200;

    public const DEFAULT_AUTHOR = 'Equipo editorial BañosPortátiles.net';

    /** @var array<string, array{name: string, uri: string}> */
    private array $listingCrumbs = [];

    public function __construct(
        private readonly FieldReader $fields,
        private readonly ReferenceResolver $refs,
        private readonly UriResolver $uris,
        private readonly ContentRenderer $renderer,
        private readonly SeoNormalizer $seo,
        private readonly HeroNormalizer $hero,
        private readonly SectionsNormalizer $sections,
        private readonly FaqNormalizer $faqs,
        private readonly CiudadNormalizer $ciudades,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function normalize(\WP_Post $post, bool $preview = false): array
    {
        $id = $post->ID;
        $type = $post->post_type;
        $title = self::decode($post->post_title);
        $contentHtml = $this->renderer->render($post->post_content);
        $excerpt = self::excerpt($post->post_excerpt, $contentHtml);
        $uri = $this->uris->forPost($post, $preview) ?? '/';

        $node = [
            'id' => $id,
            'type' => $type,
            'template' => PageTemplates::slugFor($post),
            'uri' => $uri,
            'slug' => $post->post_name !== '' ? $post->post_name : basename($uri),
            'title' => $title,
            'excerpt' => $excerpt,
            'order' => $post->menu_order,
            'contentHtml' => $contentHtml,
            'seo' => $this->seo->normalize($this->fields->get('seo', $id), $title, $excerpt),
        ];

        if ($type === 'page') {
            $hero = $this->hero->normalize($this->fields->get('hero', $id), $title);
            if ($hero !== null) {
                $node['hero'] = $hero;
            }
        }

        $node['sections'] = $type === 'page' ? $this->sections->normalize($this->fields->get('sections', $id)) : [];
        $node['faqs'] = $this->faqs->normalize($this->fields->get('faqs', $id), $this->fields->get('faq_refs', $id));
        $node['breadcrumbs'] = $this->breadcrumbs($post, $title, $uri);

        $parent = $this->parent($post);
        if ($parent !== null) {
            $node['parent'] = $parent;
        }

        $node['children'] = $this->children($post);
        $node['terms'] = $this->terms($post);

        $image = $this->image($post, $node['hero'] ?? null);
        if ($image !== null) {
            $node['image'] = $image;
        }

        $node['published'] = self::date($post, 'date');
        $node['modified'] = self::date($post, 'modified');

        if ($type === 'post') {
            $node['blog'] = $this->blog($id, $contentHtml);
        }
        if ($type === PostTypes::EQUIPO) {
            $node['equipo'] = $this->equipo($id);
        }
        if ($preview) {
            $node['preview'] = true;
        }

        return $node;
    }

    public static function excerpt(string $excerpt, string $contentHtml): string
    {
        $excerpt = trim(self::decode(strip_tags($excerpt)));
        if ($excerpt !== '') {
            return $excerpt;
        }

        return wp_trim_words(wp_strip_all_tags($contentHtml), 30, '…');
    }

    public static function readingMinutes(string $html): int
    {
        $words = preg_match_all('/[\p{L}\p{N}]+/u', wp_strip_all_tags($html));

        return max(1, (int) ceil(((int) $words) / self::WORDS_PER_MINUTE));
    }

    /**
     * @return list<array{name: string, uri: string}>
     */
    private function breadcrumbs(\WP_Post $post, string $title, string $uri): array
    {
        $crumbs = [['name' => 'Inicio', 'uri' => '/']];
        if ($uri === '/') {
            return $crumbs;
        }

        if ($post->post_type === 'page') {
            foreach (array_reverse(get_post_ancestors($post)) as $ancestorId) {
                $ancestor = get_post($ancestorId);
                $ancestorUri = $ancestor instanceof \WP_Post ? $this->uris->forPost($ancestor, true) : null;
                if ($ancestor instanceof \WP_Post && $ancestorUri !== null && $ancestorUri !== '/') {
                    $crumbs[] = ['name' => self::decode($ancestor->post_title), 'uri' => $ancestorUri];
                }
            }
        } elseif ($post->post_type === 'post') {
            $crumbs[] = $this->listingCrumb('/'.UriResolver::POST_BASE.'/', 'Blog');
            $category = $this->termList($post)['category'][0] ?? null;
            if (is_array($category) && isset($category['uri'])) {
                $crumbs[] = ['name' => $category['name'], 'uri' => $category['uri']];
            }
        } elseif ($post->post_type === PostTypes::EQUIPO) {
            $crumbs[] = $this->listingCrumb('/'.UriResolver::EQUIPO_BASE.'/', 'Equipos');
        }

        $crumbs[] = ['name' => $title, 'uri' => $uri];

        return $crumbs;
    }

    /** @return array{name: string, uri: string} */
    private function listingCrumb(string $uri, string $fallback): array
    {
        if (! isset($this->listingCrumbs[$uri])) {
            $page = $this->uris->resolve($uri);
            $this->listingCrumbs[$uri] = ['name' => $page !== null ? self::decode($page->post_title) : $fallback, 'uri' => $uri];
        }

        return $this->listingCrumbs[$uri];
    }

    /** @return array{id: int, uri: string, title: string}|null */
    private function parent(\WP_Post $post): ?array
    {
        if ($post->post_type !== 'page' || $post->post_parent === 0) {
            return null;
        }
        $parent = get_post($post->post_parent);
        $uri = $parent instanceof \WP_Post ? $this->uris->forPost($parent, true) : null;

        return ($parent instanceof \WP_Post && $uri !== null)
            ? ['id' => $parent->ID, 'uri' => $uri, 'title' => self::decode($parent->post_title)]
            : null;
    }

    /**
     * @return list<array{id: int, uri: string, title: string, excerpt: string}>
     */
    private function children(\WP_Post $post): array
    {
        if ($post->post_type !== 'page') {
            return [];
        }

        $children = [];
        $posts = get_posts([
            'post_type' => 'page',
            'post_parent' => $post->ID,
            'post_status' => 'publish',
            'has_password' => false,
            'numberposts' => -1,
            'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
            'no_found_rows' => true,
        ]);
        foreach ($posts as $child) {
            $uri = $this->uris->forPost($child);
            if ($uri !== null) {
                $children[] = [
                    'id' => $child->ID,
                    'uri' => $uri,
                    'title' => self::decode($child->post_title),
                    'excerpt' => self::excerpt($child->post_excerpt, $child->post_content),
                ];
            }
        }

        return $children;
    }

    /**
     * JSON object even when empty ({} instead of []): the contract types "terms" as an object.
     *
     * @return array<string, list<array<string, mixed>>>|\stdClass
     */
    private function terms(\WP_Post $post): array|\stdClass
    {
        $terms = $this->termList($post);

        return $terms === [] ? new \stdClass : $terms;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function termList(\WP_Post $post): array
    {
        $terms = [];
        foreach ([Taxonomies::CIUDAD, 'category'] as $taxonomy) {
            if (! is_object_in_taxonomy($post->post_type, $taxonomy)) {
                continue;
            }
            $list = get_the_terms($post, $taxonomy);
            if (! is_array($list)) {
                continue;
            }
            foreach ($list as $term) {
                if ($taxonomy === 'category' && $term->term_id === (int) get_option('default_category')) {
                    continue;
                }
                $terms[$taxonomy][] = $taxonomy === Taxonomies::CIUDAD
                    ? $this->ciudades->term($term)
                    : ['id' => $term->term_id, 'slug' => $term->slug, 'name' => self::decode($term->name)]
                        + Arr::withoutEmpty(['uri' => $this->uris->forTerm($term)]);
            }
        }

        return $terms;
    }

    /**
     * @param  mixed  $hero  Normalized hero (fallback image for pages without a featured image).
     * @return array{src: string, width: int, height: int, alt: string}|null
     */
    private function image(\WP_Post $post, mixed $hero): ?array
    {
        $thumbnailId = (int) get_post_thumbnail_id($post);
        if ($thumbnailId > 0) {
            return $this->refs->image($thumbnailId);
        }
        $image = is_array($hero) ? ($hero['image'] ?? null) : null;

        return is_array($image) && isset($image['src'], $image['width'], $image['height'], $image['alt'])
            ? ['src' => (string) $image['src'], 'width' => (int) $image['width'], 'height' => (int) $image['height'], 'alt' => (string) $image['alt']]
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function blog(int $id, string $contentHtml): array
    {
        $pillarParent = Arr::ids($this->fields->get('pillar_parent', $id))[0] ?? 0;
        $sources = [];
        foreach (Arr::rows(['s' => $this->fields->get('sources', $id)], 's') as $row) {
            if (Arr::string($row, 'url') !== '' || Arr::string($row, 'title') !== '') {
                $sources[] = ['title' => Arr::string($row, 'title'), 'url' => Arr::string($row, 'url')];
            }
        }
        $author = $this->fields->get('author', $id);

        return array_filter([
            'pillar' => (bool) $this->fields->get('pillar', $id),
            'pillarUri' => $pillarParent > 0 ? $this->refs->uri($pillarParent) : null,
            'keyPoints' => Arr::strings(['k' => $this->fields->get('key_points', $id)], 'k'),
            'sources' => $sources,
            'readingMinutes' => self::readingMinutes($contentHtml),
            'related' => $this->uris(Arr::ids($this->fields->get('related_posts', $id))),
            'relatedServices' => $this->uris(Arr::ids($this->fields->get('related_services', $id))),
            'author' => is_string($author) && trim($author) !== '' ? trim($author) : self::DEFAULT_AUTHOR,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function equipo(int $id): array
    {
        $specs = [];
        foreach (Arr::rows(['s' => $this->fields->get('specs', $id)], 's') as $row) {
            if (Arr::string($row, 'k') !== '') {
                $specs[] = ['k' => Arr::string($row, 'k'), 'v' => Arr::string($row, 'v')];
            }
        }
        $gallery = [];
        foreach (Arr::ids($this->fields->get('gallery', $id)) as $imageId) {
            $image = $this->refs->image($imageId);
            if ($image !== null) {
                $gallery[] = $image;
            }
        }
        $pdfId = Arr::ids($this->fields->get('ficha_pdf', $id))[0] ?? 0;

        return array_filter([
            'specs' => $specs,
            'modalidad' => Arr::strings(['m' => $this->fields->get('modalidad', $id)], 'm'),
            'usos' => Arr::strings(['u' => $this->fields->get('usos', $id)], 'u'),
            'gallery' => $gallery,
            'fichaPdf' => $pdfId > 0 ? $this->refs->fileUrl($pdfId) : null,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function uris(array $ids): array
    {
        return array_values(array_filter(array_map($this->refs->uri(...), $ids), static fn (?string $u): bool => $u !== null));
    }

    private static function date(\WP_Post $post, string $field): string
    {
        $date = get_post_datetime($post, $field === 'modified' ? 'modified' : 'date');

        return $date !== false ? $date->format(DATE_ATOM) : current_datetime()->format(DATE_ATOM);
    }

    private static function decode(string $text): string
    {
        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
