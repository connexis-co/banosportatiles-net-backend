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

it('keeps the default texts of every mode within their limits and explicit about the WhatsApp', function (string $modo): void {
    $texts = Defaults::texts($modo);

    expect(mb_strlen($texts['headline']))->toBeLessThanOrEqual(Sanitizer::HEADLINE_MAX)
        ->and(mb_strlen($texts['message']))->toBeLessThanOrEqual(Sanitizer::MESSAGE_MAX)
        ->and(mb_strlen($texts['cta_whatsapp_label']))->toBeLessThanOrEqual(Sanitizer::LABEL_MAX)
        ->and(mb_strlen($texts['cta_whatsapp_short']))->toBeLessThanOrEqual(Sanitizer::SHORT_LABEL_MAX)
        ->and(mb_strlen($texts['whatsapp_note']))->toBeLessThanOrEqual(Sanitizer::NOTE_MAX)
        ->and(mb_strlen($texts['whatsapp_message']))->toBeLessThanOrEqual(Sanitizer::WHATSAPP_MESSAGE_MAX)
        ->and(mb_strlen($texts['secondary_label']))->toBeLessThanOrEqual(Sanitizer::LABEL_MAX)
        ->and($texts['message'])->toContain('El WhatsApp es solo para negociar el sitio')
        ->and($texts['whatsapp_message'])->toContain('No es para cotizar baños portátiles')
        ->and($texts['whatsapp_note'])->toContain('Para cotizar baños portátiles usa el formulario');
})->with(array_keys(Defaults::MODES));

it('uses the requested texts for «venta o alquiler»', function (): void {
    expect(Defaults::texts('venta_o_alquiler'))->toBe([
        'headline' => 'Este sitio web está en venta o alquiler',
        'message' => '¿Tienes una empresa de baños portátiles o saneamiento? Compra o arrienda este sitio web: dominio, contenido y posicionamiento. El WhatsApp es solo para negociar el sitio.',
        'whatsapp_message' => 'Hola, me interesa comprar o alquilar el sitio web {sitio} (dominio, contenido y posicionamiento). No es para cotizar baños portátiles. Lo vi en {url}',
        'cta_whatsapp_label' => 'WhatsApp: comprar este sitio',
        'cta_whatsapp_short' => 'Comprar sitio',
        'whatsapp_note' => 'Solo para comprar o alquilar este sitio web. Para cotizar baños portátiles usa el formulario.',
        'secondary_label' => 'Ver detalles de la venta',
    ]);
});

it('validates the short label (≤ 18) and the note (≤ 140), with the mode defaults when empty', function (): void {
    $ok = sanitizeVenta(['modo' => 'alquiler', 'cta_whatsapp_short' => ' Arrendar web ', 'whatsapp_note' => 'Solo para el sitio web.']);
    $long = sanitizeVenta(['cta_whatsapp_short' => str_repeat('x', 19), 'whatsapp_note' => str_repeat('y', 141)]);
    $empty = sanitizeVenta(['modo' => 'alquiler', 'cta_whatsapp_short' => '', 'whatsapp_note' => '']);

    expect($ok->errors)->toBe([])
        ->and($ok->settings)->toMatchArray(['cta_whatsapp_short' => 'Arrendar web', 'whatsapp_note' => 'Solo para el sitio web.'])
        ->and(array_keys($long->errors))->toBe(['cta_whatsapp_short', 'whatsapp_note'])
        ->and($long->settings['cta_whatsapp_short'])->toBe(Defaults::texts('venta')['cta_whatsapp_short'])
        ->and($empty->settings)->toMatchArray(['cta_whatsapp_short' => 'Alquilar sitio', 'whatsapp_note' => Defaults::texts('alquiler')['whatsapp_note']]);
});

it('imports the new fields from the seed (site.yaml → sale_banner, partial update)', function (): void {
    $seed = [
        'enabled' => true, 'modo' => 'venta_o_alquiler', 'whatsapp_number' => '+573002888757', 'show_whatsapp' => true,
        'cta_whatsapp_short' => 'Comprar sitio', 'whatsapp_note' => 'Solo para comprar o alquilar este sitio web.',
        'placements' => ['top_bar' => true, 'sidebar_card' => true],
    ];
    $result = (new Sanitizer)->sanitize($seed, Defaults::settings('venta_o_alquiler'), true);

    expect($result->errors)->toBe([])
        ->and($result->settings)->toMatchArray([
            'cta_whatsapp_short' => 'Comprar sitio',
            'whatsapp_note' => 'Solo para comprar o alquilar este sitio web.',
            'headline' => 'Este sitio web está en venta o alquiler',
            'placements' => ['top_bar', 'sidebar_card'],
        ]);
});
