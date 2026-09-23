<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Normalizer;

use BanosPortatiles\Headless\Fields\FieldReader;
use BanosPortatiles\Headless\Support\Arr;

/**
 * "ciudad" terms (with their SCF term fields) for GET /site → ciudades[] and Node.terms.ciudad[].
 */
final class CiudadNormalizer
{
    public function __construct(private readonly FieldReader $fields) {}

    /**
     * Full city for /site: every key present (strings may be empty), like seed/ciudades.yaml.
     *
     * @return array<string, mixed>
     */
    public function full(\WP_Term $term): array
    {
        $ref = 'term_'.$term->term_id;
        $cercanos = $this->string('cercanos', $ref);

        return array_filter([
            'slug' => $term->slug,
            'name' => self::decode($term->name),
            'departamento' => $this->string('departamento', $ref),
            'autoridad_ambiental' => $this->string('autoridad_ambiental', $ref),
            'lat' => Arr::float(['v' => $this->fields->get('lat', $ref)], 'v'),
            'lng' => Arr::float(['v' => $this->fields->get('lng', $ref)], 'v'),
            'cercanos' => Arr::lines($cercanos),
            'nota' => $this->string('nota', $ref),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Compact term for a Node: {id, slug, name, autoridad_ambiental?} (optional key omitted when empty).
     *
     * @return array<string, mixed>
     */
    public function term(\WP_Term $term): array
    {
        return ['id' => $term->term_id, 'slug' => $term->slug, 'name' => self::decode($term->name)]
            + Arr::withoutEmpty(['autoridad_ambiental' => $this->string('autoridad_ambiental', 'term_'.$term->term_id)]);
    }

    private function string(string $name, string $ref): string
    {
        $value = $this->fields->get($name, $ref);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function decode(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
