<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Seo;

use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * With Rank Math active, the SCF group «SEO» only keeps the internal fields (search intent and secondary
 * keywords): title, description, keyword, canonical, noindex and social image are edited in Rank Math,
 * so there is a single source of truth. The values stay stored (fallback if Rank Math is deactivated).
 */
final class ScfSeoFields implements Hookable
{
    /** SCF fields that Rank Math replaces (keys field_bp_seo_{name}). */
    public const MANAGED = ['title', 'description', 'keyword', 'canonical', 'noindex', 'og_image'];

    public function __construct(private readonly RankMathApi $rankMath) {}

    public function register(): void
    {
        foreach (self::MANAGED as $name) {
            add_filter('acf/prepare_field/key=field_bp_seo_'.$name, [$this, 'hide']);
        }
        add_filter('acf/prepare_field/key=field_bp_seo', [$this, 'describe']);
    }

    /**
     * @param  array<string, mixed>|false  $field
     * @return array<string, mixed>|false
     */
    public function hide(array|false $field): array|false
    {
        return $this->rankMath->active() ? false : $field;
    }

    /**
     * @param  array<string, mixed>|false  $field
     * @return array<string, mixed>|false
     */
    public function describe(array|false $field): array|false
    {
        if ($field !== false && $this->rankMath->active()) {
            $field['instructions'] = 'El título, la descripción, la keyword, el canonical, el noindex y la imagen para redes se editan en la caja de Rank Math. Aquí quedan los datos internos del contenido.';
        }

        return $field;
    }
}
