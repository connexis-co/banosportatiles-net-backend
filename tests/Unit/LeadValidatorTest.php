<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Leads\LeadValidator;

it('accepts the minimal valid payload', function (): void {
    $result = (new LeadValidator)->validate(['nombre' => 'Ana Pérez', 'telefono' => '300 123 4567', 'consentimiento' => true]);

    expect($result->isValid())->toBeTrue()
        ->and($result->lead?->telefono)->toBe('3001234567')
        ->and($result->lead?->email)->toBeNull();
});

it('sanitizes and normalizes every field', function (): void {
    $result = (new LeadValidator)->validate([
        'nombre' => "  <b>Ana</b>\t Pérez ",
        'telefono' => '+57 (300) 123-4567',
        'email' => 'Ana@Example.COM',
        'ciudad' => 'medellin',
        'servicio' => 'Alquiler para evento',
        'mensaje' => "Línea 1\r\n\r\n\r\n\r\nLínea 2 <script>x</script>",
        'fecha_evento' => '2026-10-15',
        'cantidad' => '4',
        'pagina' => '/alquiler-de-banos-portatiles/medellin/',
        'utm' => ['source' => 'google', 'medium' => 'cpc', 'gclid' => 'abc', 'evil' => 'x', 'utm_campaign' => 'pozos'],
        'consentimiento' => 'true',
        'desconocido' => 'ignorado',
    ]);
    $lead = $result->lead;

    expect($result->isValid())->toBeTrue()
        ->and($lead?->nombre)->toBe('Ana Pérez')
        ->and($lead?->telefono)->toBe('+573001234567')
        ->and($lead?->email)->toBe('ana@example.com')
        ->and($lead?->mensaje)->toBe("Línea 1\n\nLínea 2 x")
        ->and($lead?->cantidad)->toBe(4)
        ->and($lead?->utm)->toBe(['source' => 'google', 'medium' => 'cpc', 'campaign' => 'pozos', 'gclid' => 'abc'])
        ->and($lead?->toArray())->toHaveKeys(['nombre', 'telefono', 'utm', 'consentimiento']);
});

it('reports field errors in Spanish', function (array $patch, string $field): void {
    $base = ['nombre' => 'Ana', 'telefono' => '3001234567', 'consentimiento' => true];
    $result = (new LeadValidator)->validate(array_merge($base, $patch));

    expect($result->isValid())->toBeFalse()
        ->and($result->lead)->toBeNull()
        ->and($result->errors)->toHaveKey($field);
})->with([
    'short name' => [['nombre' => 'A'], 'nombre'],
    'phone letters' => [['telefono' => 'llámame'], 'telefono'],
    'phone too short' => [['telefono' => '12345'], 'telefono'],
    'email' => [['email' => 'no-es-email'], 'email'],
    'date format' => [['fecha_evento' => '15/10/2026'], 'fecha_evento'],
    'impossible date' => [['fecha_evento' => '2026-02-30'], 'fecha_evento'],
    'quantity' => [['cantidad' => 0], 'cantidad'],
    'page must be relative' => [['pagina' => 'https://evil.example/'], 'pagina'],
    'no consent' => [['consentimiento' => false], 'consentimiento'],
    'missing consent' => [['consentimiento' => null], 'consentimiento'],
    'long message' => [['mensaje' => str_repeat('a', 2001)], 'mensaje'],
]);

it('returns every error at once', function (): void {
    $result = (new LeadValidator)->validate([]);

    expect(array_keys($result->errors))->toBe(['nombre', 'telefono', 'consentimiento']);
});
