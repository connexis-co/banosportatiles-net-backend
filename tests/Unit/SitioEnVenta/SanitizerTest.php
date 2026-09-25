<?php

declare(strict_types=1);

use BanosPortatiles\SitioEnVenta\Settings\Defaults;
use BanosPortatiles\SitioEnVenta\Settings\Sanitizer;

/** @param array<string, mixed> $input */
function sanitizeVenta(array $input, bool $partial = false, ?array $current = null): BanosPortatiles\SitioEnVenta\Settings\SanitizeResult
{
    return (new Sanitizer)->sanitize($input, $current ?? Defaults::settings(), $partial);
}

it('sanitizes a complete form submission', function (): void {
    $result = sanitizeVenta([
        'enabled' => '1',
        'modo' => 'alquiler',
        'headline' => '  <b>Alquila</b>   este sitio ',
        'message' => "Línea uno\nlínea dos",
        'whatsapp_number' => '300 123 4567',
        'whatsapp_message' => "Hola {sitio}\n{url}",
        'show_whatsapp' => '1',
        'cta_whatsapp_label' => 'Escríbenos',
        'show_secondary' => '0',
        'secondary_label' => '',
        'secondary_url' => 'https://banosportatiles.net/sitio-en-venta/',
        'colors' => ['bg' => '#FFF', 'text' => '#111111', 'accent' => '#0A7', 'accent_text' => '#ffffff', 'whatsapp_bg' => '#128C7E', 'whatsapp_text' => '#FFF'],
        'placements' => ['sidebar_card', 'top_bar', 'inventada'],
        'dismissible' => '1',
        'dismiss_days' => '14',
        'exclude_paths' => "/cotizar/\n/gracias",
        'desconocido' => 'x',
    ]);

    expect($result->errors)->toBe([])
        ->and($result->settings)->toMatchArray([
            'enabled' => true,
            'modo' => 'alquiler',
            'headline' => 'Alquila este sitio',
            'message' => 'Línea uno línea dos',
            'whatsapp_number' => '+573001234567',
            'whatsapp_message' => "Hola {sitio}\n{url}",
            'show_whatsapp' => true,
            'show_secondary' => false,
            'secondary_label' => Defaults::texts('alquiler')['secondary_label'],
            'secondary_url' => 'https://banosportatiles.net/sitio-en-venta/',
            'colors' => ['bg' => '#ffffff', 'text' => '#111111', 'accent' => '#00aa77', 'accent_text' => '#ffffff', 'whatsapp_bg' => '#128c7e', 'whatsapp_text' => '#ffffff'],
            'placements' => ['top_bar', 'sidebar_card'],
            'dismiss_days' => 14,
            'exclude_paths' => ['/cotizar/', '/gracias/'],
        ])
        ->and($result->settings)->not->toHaveKey('desconocido');
});

it('treats missing checkboxes as off in the admin form and keeps them in partial updates', function (): void {
    $current = ['enabled' => true, 'dismissible' => true] + Defaults::settings();

    expect(sanitizeVenta([], false, $current)->settings)->toMatchArray(['enabled' => false, 'dismissible' => false, 'show_secondary' => false])
        ->and(sanitizeVenta(['headline' => 'Nuevo'], true, $current)->settings)->toMatchArray(['enabled' => true, 'dismissible' => true, 'headline' => 'Nuevo']);
});

it('falls back to the texts of the selected mode', function (string $modo): void {
    $settings = sanitizeVenta(['modo' => $modo])->settings;

    expect($settings['headline'])->toBe(Defaults::texts($modo)['headline'])
        ->and($settings['whatsapp_message'])->toContain('{sitio}')->toContain('{url}');
})->with(array_keys(Defaults::MODES));

it('rejects invalid values, keeps the current ones and explains why', function (): void {
    $current = ['whatsapp_number' => '+573001234567', 'headline' => 'Actual'] + Defaults::settings();
    $result = sanitizeVenta([
        'modo' => 'regalo',
        'headline' => str_repeat('a', Sanitizer::HEADLINE_MAX + 1),
        'message' => str_repeat('b', Sanitizer::MESSAGE_MAX + 1),
        'whatsapp_number' => 'llámame',
        'secondary_url' => 'javascript:alert(1)',
        'colors' => ['bg' => 'rojo'],
        'dismiss_days' => 'mucho',
        'show_whatsapp' => '1',
    ], false, $current);

    expect(array_keys($result->errors))->toBe(['modo', 'headline', 'message', 'whatsapp_number', 'secondary_url', 'colors.bg', 'dismiss_days'])
        ->and($result->settings)->toMatchArray([
            'modo' => 'venta',
            'headline' => 'Actual',
            'whatsapp_number' => '+573001234567',
            'secondary_url' => Defaults::SECONDARY_URL,
            'dismiss_days' => 7,
        ])
        ->and($result->settings['colors']['bg'])->toBe(Defaults::COLORS['bg']);
});

it('adjusts values with warnings: hidden WhatsApp without number, clamped days, low contrast', function (): void {
    $result = sanitizeVenta([
        'show_whatsapp' => '1',
        'whatsapp_number' => '',
        'dismiss_days' => '90',
        'colors' => ['bg' => '#ffffff', 'text' => '#cccccc'],
    ]);

    expect($result->errors)->toBe([])
        ->and($result->settings['show_whatsapp'])->toBeFalse()
        ->and($result->settings['dismiss_days'])->toBe(30)
        ->and(array_keys($result->warnings))->toContain('show_whatsapp', 'dismiss_days', 'contrast.text/bg');
});

it('accepts relative and absolute secondary URLs only', function (string $url, bool $valid): void {
    expect(sanitizeVenta(['secondary_url' => $url])->errors === [])->toBe($valid);
})->with([
    ['/sitio-en-venta/', true],
    ['https://banosportatiles.net/venta/', true],
    ['//evil.example/', false],
    ['ftp://example.com/', false],
    ['/con espacio/', false],
]);
