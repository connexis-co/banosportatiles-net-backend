<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Leads\LeadData;
use BanosPortatiles\Headless\Leads\LeadEmail;
use BanosPortatiles\Headless\Leads\LeadValidator;
use Brain\Monkey\Functions;

/** @param array<string, mixed> $overrides */
function leadEmail(array $overrides = [], ?string $ciudad = 'Medellín'): LeadEmail
{
    $lead = new LeadData(...array_merge([
        'nombre' => 'Ana Pérez',
        'telefono' => '+573001234567',
        'email' => 'ana@example.com',
        'ciudad' => 'medellin',
        'servicio' => 'Alquiler para evento',
        'mensaje' => "4 baños\npara 300 personas",
        'fechaEvento' => '2026-10-15',
        'cantidad' => 4,
        'pagina' => '/alquiler-de-banos-portatiles/medellin/',
        'utm' => ['source' => 'google', 'medium' => 'cpc'],
        'consentimientoComercial' => true,
    ], $overrides));

    return new LeadEmail($lead, [
        'reference' => '0b1c2d3e-aaaa-4bbb-8ccc-123456789abc',
        'created' => 1_790_195_400, // 2026-09-23 20:30 UTC
        'ciudad' => $ciudad,
        'origin' => 'https://banosportatiles.net/alquiler-de-banos-portatiles/medellin/',
        'admin' => 'https://admin.banosportatiles.net/wp-admin/post.php?post=7&action=edit',
        'site' => 'BañosPortátiles.net',
    ]);
}

it('builds the subject «Nueva cotización: <servicio> en <ciudad> — <nombre>»', function (): void {
    expect(leadEmail()->subject())->toBe('Nueva cotización: Alquiler para evento en Medellín — Ana Pérez')
        ->and(leadEmail(['servicio' => null, 'ciudad' => null], null)->subject())->toBe('Nueva cotización: solicitud de cotización — Ana Pérez')
        ->and(leadEmail([], null)->subject())->toBe('Nueva cotización: Alquiler para evento en medellin — Ana Pérez');
});

it('renders every field in the HTML body, escaped, with links and the Colombian time', function (): void {
    $html = leadEmail(['nombre' => 'Ana <script>alert(1)</script>'])->html();

    expect($html)->not->toContain('<script>')
        ->toContain('Ana &lt;script&gt;')
        ->toContain('href="tel:+573001234567"')
        ->toContain('href="https://wa.me/573001234567"')
        ->toContain('href="mailto:ana@example.com"')
        ->toContain('4 baños<br>')
        ->toContain('2026-10-15')
        ->toContain('href="https://banosportatiles.net/alquiler-de-banos-portatiles/medellin/"')
        ->toContain('source=google, medium=cpc')
        ->toContain('Comunicaciones comerciales')
        ->toContain('Sí, acepta')
        ->toContain('23 de septiembre de 2026, 3:30 p. m. (hora de Colombia)')
        ->toContain('0b1c2d3e-aaaa-4bbb-8ccc-123456789abc');
});

it('provides a plain-text alternative and marks declined marketing consent', function (): void {
    $text = leadEmail(['consentimientoComercial' => false, 'email' => null])->text();

    expect($text)->toStartWith('Nueva cotización: Alquiler para evento en Medellín — Ana Pérez')
        ->toContain('Comunicaciones comerciales: No')
        ->toContain('Email: —')
        ->toContain('Tratamiento de datos: Aceptado (Ley 1581 de 2012)');
});

it('formats dates in America/Bogota whatever the server timezone', function (): void {
    expect(LeadEmail::formatDate(1_790_195_400 - 13 * 3600))->toBe('23 de septiembre de 2026, 2:30 a. m. (hora de Colombia)')
        ->and(LeadEmail::formatDate(1_790_195_400 - 20 * 3600))->toBe('22 de septiembre de 2026, 7:30 p. m. (hora de Colombia)');
});

it('accepts the optional marketing consent', function (mixed $value, bool $expected): void {
    $lead = (new LeadValidator)->validate(['nombre' => 'Ana', 'telefono' => '3001234567', 'consentimiento' => true, 'consentimiento_comercial' => $value])->lead;

    expect($lead?->consentimientoComercial)->toBe($expected);
})->with([[true, true], ['1', true], ['on', true], [false, false], [null, false], ['no', false]]);

describe('recipients', function (): void {
    beforeEach(function (): void {
        Functions\when('get_option')->justReturn('');
    });
    afterEach(function (): void {
        putenv('BP_LEADS_EMAIL_TO');
        putenv('BP_LEADS_EMAIL_CC');
    });

    it('defaults to contacto@ with a copy to Connexis', function (): void {
        expect((new Config)->leadsEmailTo())->toBe(['contacto@banosportatiles.net'])
            ->and((new Config)->leadsEmailCc())->toBe(['connexis.co@gmail.com']);
    });

    it('reads comma separated constants, drops invalid and repeated addresses', function (): void {
        putenv('BP_LEADS_EMAIL_TO=ventas@example.com, no-es-email');
        putenv('BP_LEADS_EMAIL_CC=otro@example.com; VENTAS@example.com ,otro@example.com');

        expect((new Config)->leadsEmailTo())->toBe(['ventas@example.com'])
            ->and((new Config)->leadsEmailCc())->toBe(['otro@example.com']);
    });

    it('disables the copy with BP_LEADS_EMAIL_CC=none', function (): void {
        putenv('BP_LEADS_EMAIL_CC=none');

        expect((new Config)->leadsEmailCc())->toBe([]);
    });

    it('falls back to the options page before the default', function (): void {
        Functions\when('get_option')->alias(fn (string $name): string => $name === 'bp_site_forms_leads_email' ? 'leads@example.com' : '');

        expect((new Config)->leadsEmailTo())->toBe(['leads@example.com']);
    });
});
