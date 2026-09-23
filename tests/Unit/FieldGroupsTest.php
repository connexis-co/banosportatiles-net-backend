<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Fields\FieldBuilder;
use BanosPortatiles\Headless\Fields\FieldGroups;
use BanosPortatiles\Headless\Normalizer\SectionsNormalizer;

/**
 * @param  array<string, mixed>  $node
 * @return list<string>
 */
function collectKeys(array $node): array
{
    $keys = isset($node['key']) && is_string($node['key']) ? [$node['key']] : [];
    foreach (['fields', 'sub_fields', 'layouts'] as $child) {
        foreach ($node[$child] ?? [] as $item) {
            $keys = [...$keys, ...collectKeys($item)];
        }
    }

    return $keys;
}

it('registers field groups with unique, stable, prefixed keys', function (): void {
    $keys = [];
    foreach (FieldGroups::all() as $group) {
        $keys = [...$keys, ...collectKeys($group)];
    }

    expect($keys)->toHaveCount(count(array_unique($keys)))
        ->and(count($keys))->toBeGreaterThan(100);
    foreach ($keys as $key) {
        expect($key)->toMatch('/^(group|field|layout)_bp_[a-z0-9_]+$/');
    }
});

it('derives keys from the field path', function (): void {
    $builder = new FieldBuilder;

    expect($builder->key('seo'))->toBe('field_bp_seo')
        ->and($builder->child('seo')->key('title'))->toBe('field_bp_seo_title')
        ->and((new FieldBuilder('site'))->key('deploy'))->toBe('field_bp_site_deploy');
});

it('exposes every section layout of the seed contract', function (): void {
    $flexible = FieldGroups::sections()['fields'][0];
    $layouts = array_map(fn (array $layout): string => $layout['name'], array_values($flexible['layouts']));

    expect($flexible['type'])->toBe('flexible_content')
        ->and($layouts)->toBe(SectionsNormalizer::LAYOUTS);
});

it('keeps the hero field names identical to the seed keys', function (): void {
    $hero = FieldGroups::hero()['fields'][0];
    $names = array_map(fn (array $field): string => $field['name'], $hero['sub_fields']);

    expect($names)->toBe(['eyebrow', 'h1', 'lead', 'bullets', 'image', 'cta_primario', 'cta_secundario', 'mostrar_formulario']);
});
