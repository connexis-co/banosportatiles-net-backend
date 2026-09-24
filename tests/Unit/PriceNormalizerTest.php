<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Fields\FieldGroups;
use BanosPortatiles\Headless\Import\FieldValueMapper;
use BanosPortatiles\Headless\Normalizer\PriceNormalizer;
use BanosPortatiles\Headless\Tests\Fakes\FakeSeedLookup;

// Test data only: the seed and WordPress never get example prices.

it('builds a "from" price with unit, VAT, dates, note and availability', function (): void {
    expect(PriceNormalizer::normalize([
        'show' => true,
        'mode' => 'from',
        'amount' => '180000',
        'unit' => 'DAY',
        'tax_included' => 1,
        'valid_until' => '20261231',
        'updated' => '2026-09-20',
        'note' => "  Incluye   transporte\n en el área metropolitana. ",
        'availability' => 'LimitedAvailability',
    ]))->toBe([
        'mode' => 'from',
        'amount' => 180000,
        'currency' => 'COP',
        'unit' => ['code' => 'DAY', 'label' => 'por día'],
        'taxIncluded' => true,
        'validUntil' => '2026-12-31',
        'updated' => '2026-09-20',
        'note' => 'Incluye transporte en el área metropolitana.',
        'availability' => 'LimitedAvailability',
    ]);
});

it('builds ranges only when min is lower than max', function (): void {
    $range = ['show' => 1, 'mode' => 'range', 'min' => 90000, 'max' => '250000', 'unit' => 'C62_unidad'];

    expect(PriceNormalizer::normalize($range))->toBe([
        'mode' => 'range',
        'min' => 90000,
        'max' => 250000,
        'currency' => 'COP',
        'unit' => ['code' => 'C62', 'label' => 'por unidad'],
        'taxIncluded' => false,
        'availability' => 'InStock',
    ])->and(PriceNormalizer::normalize(['max' => 90000] + $range))->toBeNull()
        ->and(PriceNormalizer::normalize(['min' => 250000] + $range))->toBeNull()
        ->and(PriceNormalizer::normalize(['min' => ''] + $range))->toBeNull();
});

it('omits prices that are hidden, empty or not integer COP', function (mixed $price): void {
    expect(PriceNormalizer::normalize($price))->toBeNull();
})->with([
    'no data' => [null],
    'hidden' => [['show' => false, 'mode' => 'fixed', 'amount' => 100000]],
    'zero' => [['show' => true, 'mode' => 'fixed', 'amount' => 0]],
    'negative' => [['show' => true, 'mode' => 'fixed', 'amount' => -5]],
    'decimal' => [['show' => true, 'mode' => 'fixed', 'amount' => 1500.5]],
    'formatted text' => [['show' => true, 'mode' => 'fixed', 'amount' => '150.000']],
    'unknown mode' => [['show' => true, 'mode' => 'gratis', 'amount' => 1000]],
]);

it('drops unknown units, invalid dates and unknown availability', function (): void {
    $price = PriceNormalizer::normalize(['show' => true, 'mode' => 'fixed', 'amount' => 50000.0, 'unit' => 'XXX', 'valid_until' => '2026-02-30', 'availability' => 'Siempre']);

    expect($price)->toBe(['mode' => 'fixed', 'amount' => 50000, 'currency' => 'COP', 'taxIncluded' => false, 'availability' => 'InStock']);
});

it('defaults the schema type to auto', function (): void {
    expect(PriceNormalizer::schemaType('product'))->toBe('product')
        ->and(PriceNormalizer::schemaType('service'))->toBe('service')
        ->and(PriceNormalizer::schemaType('Organization'))->toBe('auto')
        ->and(PriceNormalizer::schemaType(null))->toBe('auto')
        ->and(PriceNormalizer::supports('ciudad'))->toBeTrue()
        ->and(PriceNormalizer::supports('home'))->toBeFalse();
});

it('offers every UN/CEFACT unit of the contract in the admin', function (): void {
    $codes = array_values(array_unique(array_column(PriceNormalizer::UNITS, 0)));

    expect($codes)->toBe(['DAY', 'WEE', 'MON', 'HUR', 'C62', 'MTQ', 'LTR', 'MTK', 'MTR'])
        ->and(PriceNormalizer::unitChoices()['MTQ'])->toBe('por m³ (MTQ)');
});

it('maps a seed price (camelCase or snake_case) and leaves prices alone when the seed has none', function (): void {
    $warnings = [];
    $mapper = new FieldValueMapper(new FakeSeedLookup, function (string $w) use (&$warnings): void {
        $warnings[] = $w;
    });

    $mapped = $mapper->price(['price' => ['mode' => 'range', 'min' => 90000, 'max' => 250000, 'unit' => ['code' => 'C62', 'label' => 'por unidad'], 'taxIncluded' => true, 'validUntil' => '2026-12-31']]);

    expect($mapper->price(['title' => 'Sin precio']))->toBeNull()
        ->and($mapper->schemaType(['title' => 'x']))->toBeNull()
        ->and($mapper->schemaType(['schemaType' => 'product']))->toBe('product')
        ->and($mapped)->toMatchArray(['show' => 1, 'mode' => 'range', 'min' => 90000, 'max' => 250000, 'unit' => 'C62_unidad', 'tax_included' => 1, 'valid_until' => '2026-12-31', 'availability' => 'InStock'])
        ->and(PriceNormalizer::normalize($mapped))->toMatchArray(['mode' => 'range', 'min' => 90000, 'max' => 250000, 'taxIncluded' => true, 'validUntil' => '2026-12-31'])
        ->and($mapper->price(['price' => ['amount' => 1000, 'unit' => 'GAL']])['unit'])->toBe('')
        ->and($warnings)->toHaveCount(1);
});

it('registers the price group on services, cities and equipos with the contract field names', function (): void {
    $group = FieldGroups::price();
    $names = array_map(static fn (array $field): string => $field['name'], $group['fields'][0]['sub_fields']);
    $values = array_map(static fn (array $rule): string => $rule[0]['value'], $group['location']);

    expect($names)->toBe(['show', 'mode', 'amount', 'min', 'max', 'unit', 'tax_included', 'valid_until', 'updated', 'note', 'availability'])
        ->and($group['fields'][1]['name'])->toBe('schema_type')
        ->and($values)->toBe(['hub-servicio', 'servicio', 'ciudad', 'equipo']);
});
