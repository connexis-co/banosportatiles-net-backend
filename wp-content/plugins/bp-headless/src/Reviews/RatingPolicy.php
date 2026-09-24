<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Reviews;

use BanosPortatiles\Headless\Support\Arr;

/**
 * Which rating features a node shows (pure): the global switch, the defaults of its content type and the
 * per-post override of the SCF group «Valoraciones» (inherit | yes | no).
 *
 * Home, legal, contact, quote, site-for-sale and blog index pages have no type, so they are off unless an
 * editor explicitly turns them on (Google does not show self-serving ratings of the business itself).
 */
final class RatingPolicy
{
    public const INHERIT = 'inherit';

    public const OVERRIDES = [
        self::INHERIT => 'Heredar de Ajustes del sitio',
        'yes' => 'Sí',
        'no' => 'No',
    ];

    /** Template slug (PageTemplates::slugFor) → content type of RatingSettings. */
    public const TYPE_BY_TEMPLATE = [
        'hub-servicio' => 'servicios',
        'servicio' => 'servicios',
        'ciudad' => 'ciudades',
        'equipo' => 'equipos',
        'post' => 'blog',
    ];

    /** Off by default (no type): they never inherit ratings. */
    public const EXCLUDED_TEMPLATES = ['home', 'legal', 'contacto', 'cotizar', 'venta-sitio', 'blog-index'];

    public static function typeFor(string $template): ?string
    {
        if (in_array($template, self::EXCLUDED_TEMPLATES, true)) {
            return null;
        }

        return self::TYPE_BY_TEMPLATE[$template] ?? 'otras';
    }

    /** @param mixed $override SCF value of the per-post group: {stars: inherit|yes|no, reviews: inherit|yes|no}. */
    public static function resolve(RatingSettings $settings, string $template, mixed $override): RatingFlags
    {
        if (! $settings->enabled) {
            return RatingFlags::off();
        }

        $base = $settings->flagsFor(self::typeFor($template));
        $override = is_array($override) ? $override : [];

        return new RatingFlags(
            self::apply(Arr::string($override, 'stars'), $base->stars),
            self::apply(Arr::string($override, 'reviews'), $base->reviews),
        );
    }

    private static function apply(string $override, bool $inherited): bool
    {
        return match ($override) {
            'yes' => true,
            'no' => false,
            default => $inherited,
        };
    }
}
