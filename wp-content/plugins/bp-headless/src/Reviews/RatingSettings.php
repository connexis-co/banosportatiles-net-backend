<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Reviews;

use BanosPortatiles\Headless\Support\Arr;

/**
 * «Ajustes del sitio → Valoraciones» (SCF group "ratings", snake_case field names) → typed settings and the
 * /site → ratings object (camelCase keys, contract §3.2). Missing values fall back to the defaults below, which
 * are also the SCF default values.
 */
final readonly class RatingSettings
{
    /** Content types of the policy (see RatingPolicy::typeFor). */
    public const TYPES = [
        'servicios' => 'Servicios (hubs y servicios)',
        'ciudades' => 'Ciudades',
        'equipos' => 'Equipos',
        'blog' => 'Blog',
        'otras' => 'Otras páginas (landings y páginas sin plantilla)',
    ];

    /** @var array<string, array{stars: bool, reviews: bool}> */
    public const DEFAULT_TYPES = [
        'servicios' => ['stars' => true, 'reviews' => false],
        'ciudades' => ['stars' => true, 'reviews' => false],
        'equipos' => ['stars' => true, 'reviews' => false],
        'blog' => ['stars' => true, 'reviews' => true],
        'otras' => ['stars' => false, 'reviews' => false],
    ];

    public const DEFAULT_MIN_COUNT_FOR_SCHEMA = 1;

    /** API key (camelCase) → SCF field name (snake_case). */
    public const TEXT_FIELDS = [
        'starsTitle' => 'stars_title',
        'starsHelp' => 'stars_help',
        'firstVote' => 'first_vote',
        'thanks' => 'thanks',
        'reviewsTitle' => 'reviews_title',
        'reviewsEmpty' => 'reviews_empty',
        'formTitle' => 'form_title',
        'consent' => 'consent',
        'pending' => 'pending',
    ];

    /** @var array<string, string> UI texts (same defaults as the seed, site.yaml → ratings.texts). */
    public const DEFAULT_TEXTS = [
        'starsTitle' => 'Califica esta página',
        'starsHelp' => 'Elige de 1 a 5 estrellas.',
        'firstVote' => 'Sé el primero en calificar',
        'thanks' => '¡Gracias! Registramos tu calificación.',
        'reviewsTitle' => 'Comentarios',
        'reviewsEmpty' => 'Todavía no hay comentarios. Cuéntanos tu experiencia o tu duda.',
        'formTitle' => 'Deja tu comentario',
        'consent' => 'Autorizo el tratamiento de mis datos personales para publicar y moderar mi comentario, conforme a la Ley 1581 de 2012.',
        'pending' => 'Recibimos tu comentario. Lo publicaremos cuando lo revisemos.',
    ];

    /**
     * @param  array<string, RatingFlags>  $types
     * @param  array<string, string>  $texts
     */
    public function __construct(
        public bool $enabled,
        public array $types,
        public int $minCountForSchema,
        public bool $autoApproveReviews,
        public array $texts,
    ) {}

    public static function defaults(): self
    {
        return self::fromOption(null);
    }

    /** @param mixed $value SCF value of the "ratings" group (null when SCF is missing or it was never saved). */
    public static function fromOption(mixed $value): self
    {
        $data = is_array($value) ? $value : [];
        $types = [];
        $storedTypes = Arr::array($data, 'types');
        foreach (self::DEFAULT_TYPES as $type => $default) {
            $row = Arr::array($storedTypes, $type);
            $types[$type] = new RatingFlags(
                Arr::bool($row, 'stars', $default['stars']),
                Arr::bool($row, 'reviews', $default['reviews']),
            );
        }

        $storedTexts = Arr::array($data, 'texts');
        $texts = [];
        foreach (self::TEXT_FIELDS as $key => $field) {
            $text = Arr::string($storedTexts, $field) ?: Arr::string($storedTexts, $key);
            $texts[$key] = $text !== '' ? $text : self::DEFAULT_TEXTS[$key];
        }

        $min = self::firstInt($data, ['min_count_for_schema', 'minCountForSchema']);

        return new self(
            enabled: Arr::bool($data, 'enabled', true),
            types: $types,
            minCountForSchema: $min !== null && $min >= 1 ? $min : self::DEFAULT_MIN_COUNT_FOR_SCHEMA,
            autoApproveReviews: Arr::bool($data, 'auto_approve_reviews', Arr::bool($data, 'autoApproveReviews')),
            texts: $texts,
        );
    }

    public function flagsFor(?string $type): RatingFlags
    {
        return ($type !== null && isset($this->types[$type])) ? $this->types[$type] : RatingFlags::off();
    }

    /**
     * /site → ratings. "enabled" is false when the reviews plugin is not available, so the front hides the widgets.
     *
     * @return array<string, mixed>
     */
    public function toSite(bool $available): array
    {
        $types = [];
        foreach ($this->types as $type => $flags) {
            $types[$type] = ['stars' => $flags->stars, 'reviews' => $flags->reviews];
        }

        return [
            'enabled' => $available && $this->enabled,
            'types' => $types,
            'minCountForSchema' => $this->minCountForSchema,
            'autoApproveReviews' => $this->autoApproveReviews,
            'texts' => $this->texts,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $keys
     */
    private static function firstInt(array $data, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (int) $data[$key];
            }
        }

        return null;
    }
}
