<?php

declare(strict_types=1);

use BanosPortatiles\SitioEnVenta\Support\Phone;

it('normalizes WhatsApp numbers to E.164', function (string $input, ?string $expected): void {
    expect(Phone::normalize($input))->toBe($expected);
})->with([
    'international with spaces' => ['+57 300 123 4567', '+573001234567'],
    'dashes and parentheses' => ['+57 (300) 123-4567', '+573001234567'],
    'Colombian mobile, 10 digits' => ['300 123 4567', '+573001234567'],
    'country code without plus' => ['573001234567', '+573001234567'],
    '00 prefix' => ['0057 300 123 4567', '+573001234567'],
    'other country' => ['+1 415 555 2671', '+14155552671'],
    'empty' => ['  ', ''],
    'too short' => ['+57 123', null],
    'landline without country code' => ['601 234 5678', null],
    'letters' => ['+57 300 ABC 4567', null],
    'leading zero country code' => ['+0 300 123 4567', null],
    'two plus signs' => ['++573001234567', null],
    'too long' => ['+57 300 123 4567 8901 23', null],
]);

it('validates, formats and links E.164 numbers', function (): void {
    expect(Phone::isE164('+573001234567'))->toBeTrue()
        ->and(Phone::isE164('573001234567'))->toBeFalse()
        ->and(Phone::display('+573001234567'))->toBe('+57 300 123 4567')
        ->and(Phone::display('+14155552671'))->toBe('+14155552671')
        ->and(Phone::waLink('+573001234567', 'Hola, ¿está en venta?'))->toBe('https://wa.me/573001234567?text=Hola%2C%20%C2%BFest%C3%A1%20en%20venta%3F');
});
