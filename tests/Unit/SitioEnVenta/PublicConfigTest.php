<?php

declare(strict_types=1);

use BanosPortatiles\SitioEnVenta\Settings\Defaults;
use BanosPortatiles\SitioEnVenta\Settings\PublicConfig;

it('exposes a stable REST shape with typed values', function (): void {
    $config = PublicConfig::from(Defaults::settings());

    expect(array_keys($config))->toBe([...PublicConfig::KEYS, 'version'])
        ->and($config['enabled'])->toBeBool()
        ->and($config['modo'])->toBe('venta')
        ->and($config['whatsapp_number'])->toBeString()
        ->and(array_keys($config['colors']))->toBe(['bg', 'text', 'accent', 'accent_text', 'whatsapp_bg', 'whatsapp_text'])
        ->and($config['colors']['whatsapp_bg'])->toBe('#25d366')
        ->and($config['colors']['whatsapp_text'])->toBe('#ffffff')
        ->and($config['placements'])->toBeArray()
        ->and($config['dismiss_days'])->toBeInt()
        ->and($config['exclude_paths'])->toBe(['/cotizar/'])
        ->and($config['version'])->toMatch('/^[0-9a-f]{12}$/');
});

it('changes the version when the notice changes, so dismissals reset', function (): void {
    $base = PublicConfig::from(Defaults::settings());
    $same = PublicConfig::from(Defaults::settings());
    $changed = PublicConfig::from(['headline' => 'Otro titular'] + Defaults::settings());

    expect($same['version'])->toBe($base['version'])
        ->and($changed['version'])->not->toBe($base['version']);
});

it('ignores unknown stored keys and fills missing ones', function (): void {
    $config = PublicConfig::from(['enabled' => true, 'secreto' => 'x']);

    expect($config)->not->toHaveKey('secreto')
        ->and($config['enabled'])->toBeTrue()
        ->and($config['secondary_url'])->toBe(Defaults::SECONDARY_URL);
});

it('never exposes the WhatsApp button without a number', function (): void {
    expect(PublicConfig::from(Defaults::settings())['show_whatsapp'])->toBeFalse()
        ->and(PublicConfig::from(['whatsapp_number' => '+573001234567'] + Defaults::settings())['show_whatsapp'])->toBeTrue()
        ->and(PublicConfig::from(['whatsapp_number' => '+573001234567', 'show_whatsapp' => false] + Defaults::settings())['show_whatsapp'])->toBeFalse();
});

it('gives options saved before 1.0.3 the default WhatsApp colors and keeps the stored ones', function (): void {
    $stored = ['colors' => ['bg' => '#ffffff', 'text' => '#111111', 'accent' => '#0e3b30', 'accent_text' => '#ffffff']] + Defaults::settings();
    $config = PublicConfig::from($stored);

    expect($config['colors'])->toBe([
        'bg' => '#ffffff', 'text' => '#111111', 'accent' => '#0e3b30', 'accent_text' => '#ffffff',
        'whatsapp_bg' => '#25d366', 'whatsapp_text' => '#ffffff',
    ])->and(Defaults::colors(['whatsapp_bg' => '#128c7e', 'otro' => '#000000', 'text' => '']))
        ->toBe(array_replace(Defaults::COLORS, ['whatsapp_bg' => '#128c7e']));
});
