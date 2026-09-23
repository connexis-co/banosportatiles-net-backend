<?php

declare(strict_types=1);

use BanosPortatiles\SitioEnVenta\Settings\Defaults;
use BanosPortatiles\SitioEnVenta\Support\Contrast;

it('computes WCAG contrast ratios', function (string $fg, string $bg, float $expected): void {
    expect(Contrast::ratio($fg, $bg))->toBe($expected);
})->with([
    'black on white' => ['#000000', '#ffffff', 21.0],
    'order does not matter' => ['#ffffff', '#000000', 21.0],
    'same color' => ['#25d366', '#25d366', 1.0],
    'shorthand hex' => ['#fff', '#000', 21.0],
]);

it('applies the AA thresholds (4.5:1 text, 3:1 UI)', function (): void {
    expect(Contrast::ratio('#767676', '#ffffff'))->toBeGreaterThanOrEqual(Contrast::AA_TEXT)
        ->and(Contrast::ratio('#777777', '#ffffff'))->toBeLessThan(Contrast::AA_TEXT);
});

it('reports every pair of the banner and passes with the default palette', function (): void {
    $report = Contrast::report(Defaults::COLORS);

    expect(array_column($report, 'pair'))->toBe(['text/bg', 'accent_text/accent', 'accent/bg'])
        ->and(array_column($report, 'ok'))->toBe([true, true, true])
        ->and(array_column($report, 'minimum'))->toBe([4.5, 4.5, 3.0]);
});

it('flags a palette that fails AA', function (): void {
    $report = Contrast::report(['bg' => '#ffffff', 'text' => '#bbbbbb', 'accent' => '#25d366', 'accent_text' => '#ffffff']);

    expect(array_column($report, 'ok'))->toBe([false, false, false]);
});

it('normalizes hex colors', function (): void {
    expect(Contrast::normalizeHex('#ABC'))->toBe('#aabbcc')
        ->and(Contrast::normalizeHex(' #0F172A '))->toBe('#0f172a')
        ->and(Contrast::normalizeHex('red'))->toBeNull()
        ->and(Contrast::normalizeHex('#12345'))->toBeNull();
});
