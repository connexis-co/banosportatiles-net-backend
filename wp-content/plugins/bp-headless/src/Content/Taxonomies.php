<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Content;

use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * Taxonomies: ciudad (pages, equipos, leads) and tema_faq (FAQ topics). Blog clusters use core "category".
 */
final class Taxonomies implements Hookable
{
    public const CIUDAD = 'ciudad';

    public const TEMA_FAQ = 'tema_faq';

    public function register(): void
    {
        add_action('init', [$this, 'registerTaxonomies'], 6);
    }

    public function registerTaxonomies(): void
    {
        register_taxonomy(self::CIUDAD, ['page', PostTypes::EQUIPO, PostTypes::LEAD], [
            'labels' => [
                'name' => 'Ciudades',
                'singular_name' => 'Ciudad',
                'search_items' => 'Buscar ciudades',
                'all_items' => 'Todas las ciudades',
                'edit_item' => 'Editar ciudad',
                'add_new_item' => 'Añadir ciudad',
                'not_found' => 'No hay ciudades',
            ],
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_rest' => true,
            'show_admin_column' => true,
            'hierarchical' => false,
            'rewrite' => false,
            'query_var' => false,
        ]);

        register_taxonomy(self::TEMA_FAQ, [PostTypes::FAQ], [
            'labels' => [
                'name' => 'Temas de FAQ',
                'singular_name' => 'Tema de FAQ',
                'all_items' => 'Todos los temas',
                'edit_item' => 'Editar tema',
                'add_new_item' => 'Añadir tema',
            ],
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_rest' => true,
            'show_admin_column' => true,
            'hierarchical' => true,
            'rewrite' => false,
            'query_var' => false,
        ]);
    }
}
