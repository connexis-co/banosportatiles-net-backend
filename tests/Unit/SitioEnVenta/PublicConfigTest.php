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
        ->and($config['colors'])->toHaveKeys(['bg', 'text', 'accent', 'accent_text'])
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
