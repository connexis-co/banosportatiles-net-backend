<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Content;

use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * Custom post types: equipo (public catalogue), faq (reusable Q&A bank) and lead (private quote requests).
 */
final class PostTypes implements Hookable
{
    public const EQUIPO = 'equipo';

    public const FAQ = 'faq';

    public const LEAD = 'lead';

    public function register(): void
    {
        add_action('init', [$this, 'registerPostTypes'], 5);
        add_action('init', [$this, 'adjustCoreTypes'], 20);
        add_action('after_setup_theme', static function (): void {
            add_theme_support('post-thumbnails');
        });
    }

    public function registerPostTypes(): void
    {
        register_post_type(self::EQUIPO, [
            'labels' => self::labels('Equipo', 'Equipos', 'Catálogo de equipos'),
            'description' => 'Catálogo de equipos: baños portátiles, lavamanos, duchas, tanques…',
            'public' => true,
            'show_in_rest' => true,
            'rest_base' => 'equipos',
            'has_archive' => false,
            'hierarchical' => false,
            'rewrite' => ['slug' => 'equipos', 'with_front' => false],
            'menu_icon' => 'dashicons-archive',
            'menu_position' => 21,
            'supports' => ['title', 'editor', 'excerpt', 'thumbnail', 'page-attributes', 'revisions'],
        ]);

        register_post_type(self::FAQ, [
            'labels' => self::labels('Pregunta frecuente', 'Preguntas frecuentes', 'Banco de FAQs'),
            'description' => 'Banco reutilizable de preguntas frecuentes. No tiene URL pública propia.',
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_rest' => true,
            'rest_base' => 'faqs',
            'exclude_from_search' => true,
            'has_archive' => false,
            'rewrite' => false,
            'query_var' => false,
            'menu_icon' => 'dashicons-editor-help',
            'menu_position' => 22,
            'supports' => ['title', 'editor', 'page-attributes', 'revisions'],
        ]);

        register_post_type(self::LEAD, [
            'labels' => self::labels('Lead', 'Leads', 'Leads (cotizaciones)'),
            'description' => 'Solicitudes de cotización recibidas desde el sitio público. Datos personales: Ley 1581 de 2012.',
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_rest' => false,
            'show_in_nav_menus' => false,
            'exclude_from_search' => true,
            'has_archive' => false,
            'rewrite' => false,
            'query_var' => false,
            'capability_type' => 'post',
            'capabilities' => ['create_posts' => 'do_not_allow'],
            'map_meta_cap' => true,
            'menu_icon' => 'dashicons-email-alt',
            'menu_position' => 25,
            'supports' => ['title'],
        ]);
    }

    public function adjustCoreTypes(): void
    {
        add_post_type_support('page', 'excerpt');
        // Tags are not part of the content model: categories are the blog clusters.
        unregister_taxonomy_for_object_type('post_tag', 'post');
    }

    /**
     * @return array<string, string>
     */
    private static function labels(string $singular, string $plural, string $menu): array
    {
        return [
            'name' => $plural,
            'singular_name' => $singular,
            'menu_name' => $menu,
            'add_new' => 'Añadir',
            'add_new_item' => 'Añadir '.mb_strtolower($singular),
            'edit_item' => 'Editar '.mb_strtolower($singular),
            'new_item' => 'Nuevo: '.mb_strtolower($singular),
            'view_item' => 'Ver '.mb_strtolower($singular),
            'search_items' => 'Buscar '.mb_strtolower($plural),
            'not_found' => 'No se encontraron '.mb_strtolower($plural),
            'not_found_in_trash' => 'No hay '.mb_strtolower($plural).' en la papelera',
            'all_items' => 'Todos',
        ];
    }
}
