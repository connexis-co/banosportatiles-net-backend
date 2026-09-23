<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Content;

use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * Virtual page templates (no theme files): the front resolves the Astro layout from the "template" slug.
 */
final class PageTemplates implements Hookable
{
    public const TEMPLATES = [
        'home' => 'Inicio',
        'hub-servicio' => 'Hub de servicio',
        'servicio' => 'Servicio (segmento)',
        'ciudad' => 'Ciudad',
        'equipos' => 'Listado de equipos',
        'legal' => 'Legal',
        'landing' => 'Landing genérica',
        'venta-sitio' => 'Sitio en venta',
        'contacto' => 'Contacto',
        'cotizar' => 'Cotizar',
        'blog-index' => 'Índice del blog',
    ];

    public const DEFAULT = 'default';

    public function register(): void
    {
        add_filter('theme_page_templates', [$this, 'addTemplates']);
    }

    /**
     * @param  array<string, string>  $templates
     * @return array<string, string>
     */
    public function addTemplates(array $templates): array
    {
        return self::TEMPLATES + $templates;
    }

    public static function slugFor(\WP_Post $post): string
    {
        return match ($post->post_type) {
            'page' => self::pageTemplate($post),
            default => $post->post_type,
        };
    }

    public static function label(string $slug): string
    {
        return self::TEMPLATES[$slug] ?? 'Predeterminada';
    }

    private static function pageTemplate(\WP_Post $post): string
    {
        $template = (string) get_page_template_slug($post);

        return ($template === '' || $template === 'default') ? self::DEFAULT : $template;
    }
}
