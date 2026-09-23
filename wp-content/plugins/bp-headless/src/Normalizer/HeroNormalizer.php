<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Normalizer;

use BanosPortatiles\Headless\Support\Arr;

/**
 * SCF "hero" group → Hero object with the seed keys (h1 falls back to the page title).
 */
final class HeroNormalizer
{
    public function __construct(private readonly ReferenceResolver $refs) {}

    /**
     * @return array<string, mixed>|null
     */
    public function normalize(mixed $hero, string $fallbackTitle): ?array
    {
        if (! is_array($hero)) {
            return null;
        }

        $imageId = Arr::ids($hero['image'] ?? null)[0] ?? 0;
        $values = Arr::withoutEmpty([
            'eyebrow' => Arr::string($hero, 'eyebrow'),
            'h1' => Arr::string($hero, 'h1'),
            'lead' => Arr::string($hero, 'lead'),
            'image' => $imageId > 0 ? $this->refs->image($imageId) : null,
            'cta_primario' => SectionsNormalizer::link($hero['cta_primario'] ?? null),
            'cta_secundario' => SectionsNormalizer::link($hero['cta_secundario'] ?? null),
        ]);
        $bullets = Arr::strings($hero, 'bullets');
        $showForm = Arr::bool($hero, 'mostrar_formulario');

        if ($values === [] && $bullets === [] && ! $showForm) {
            return null;
        }

        return array_filter([
            'eyebrow' => $values['eyebrow'] ?? null,
            'h1' => $values['h1'] ?? $fallbackTitle,
            'lead' => $values['lead'] ?? null,
            'bullets' => $bullets,
            'image' => $values['image'] ?? null,
            'cta_primario' => $values['cta_primario'] ?? null,
            'cta_secundario' => $values['cta_secundario'] ?? null,
            'mostrar_formulario' => $showForm,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
