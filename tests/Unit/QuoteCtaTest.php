<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Fields\FieldGroups;
use BanosPortatiles\Headless\Import\FieldValueMapper;
use BanosPortatiles\Headless\Leads\QuoteCta;
use BanosPortatiles\Headless\Leads\QuoteCtaAdmin;
use BanosPortatiles\Headless\Tests\Fakes\FakeSeedLookup;

it('exposes /site → forms with defaults: modal mode, modal texts and the official WhatsApp colors', function (): void {
    expect(QuoteCta::site([]))->toBe([
        'turnstile_site_key' => '',
        'cta_mode' => 'modal',
        'modal' => QuoteCta::MODAL_DEFAULTS,
        'whatsapp' => ['bg' => '#25d366', 'text' => '#ffffff'],
    ])->and(QuoteCta::site([
        'turnstile_site_key' => '0x4AAA',
        'cta_mode' => 'page',
        'modal' => ['eyebrow' => '', 'title' => 'Cotiza tu baño', 'subtitle' => '', 'success' => '¡Listo!'],
        'whatsapp' => ['bg' => '#128C7E', 'text' => 'blanco'],
        'leads_email' => 'no-sale@example.com',
    ]))->toBe([
        'turnstile_site_key' => '0x4AAA',
        'cta_mode' => 'page',
        'modal' => ['eyebrow' => 'Cotización gratuita', 'title' => 'Cotiza tu baño', 'subtitle' => QuoteCta::MODAL_DEFAULTS['subtitle'], 'success' => '¡Listo!'],
        'whatsapp' => ['bg' => '#128c7e', 'text' => '#ffffff'],
    ])->and(QuoteCta::site(['cta_mode' => 'popup'])['cta_mode'])->toBe('modal');
});

it('resolves node.lead from the page group, the template and the site mode', function (): void {
    $self = ['label' => 'Pozos sépticos', 'uri' => '/pozos-septicos/'];
    $chosen = ['label' => 'Alquiler de baños portátiles', 'uri' => '/alquiler-de-banos-portatiles/'];

    expect(QuoteCta::resolve('modal', null, 'hub-servicio', $self, null))->toBe(['service' => $self, 'mode' => 'modal'])
        ->and(QuoteCta::resolve('modal', ['mode' => 'inherit'], 'servicio', $self, $chosen))->toBe(['service' => $chosen, 'mode' => 'modal'])
        ->and(QuoteCta::resolve('page', ['mode' => 'inherit', 'title' => ''], 'ciudad', $self, null))->toBe(['mode' => 'page'])
        ->and(QuoteCta::resolve('page', ['mode' => 'modal', 'title' => 'Cotiza en Cali'], 'ciudad', $self, $chosen))->toBe(['service' => $chosen, 'mode' => 'modal', 'title' => 'Cotiza en Cali'])
        ->and(QuoteCta::resolve('modal', ['mode' => 'page', 'title' => 'Obsoleto'], 'legal', $self, null))->toBe(['mode' => 'modal'])
        ->and(QuoteCta::resolve('', null, 'post', $self, null))->toBe(['mode' => 'modal'])
        ->and(QuoteCta::resolve('modal', ['mode' => 'page'], 'equipo', $self, null))->toBe(['mode' => 'page']);
});

it('accepts the seed spellings of the mode and normalizes colors', function (): void {
    expect(QuoteCta::mode('Página'))->toBe('page')
        ->and(QuoteCta::mode('pagina'))->toBe('page')
        ->and(QuoteCta::mode('MODAL'))->toBe('modal')
        ->and(QuoteCta::mode('inherit'))->toBeNull()
        ->and(QuoteCta::color('#25D366'))->toBe('#25d366')
        ->and(QuoteCta::color('#FFF'))->toBe('#ffffff')
        ->and(QuoteCta::color('rgb(0,0,0)'))->toBeNull();
});

it('limits the service selector to published service pages', function (): void {
    expect(QuoteCtaAdmin::serviceQuery(['s' => 'baños', 'post_type' => ['page', 'post']]))->toMatchArray([
        's' => 'baños',
        'post_type' => 'page',
        'post_status' => 'publish',
        'meta_query' => [['key' => '_wp_page_template', 'value' => ['hub-servicio', 'servicio'], 'compare' => 'IN']],
    ]);
});

it('registers «Cotización» on commercial templates and equipos, in the sidebar', function (): void {
    $group = FieldGroups::lead();
    $values = array_map(static fn (array $rule): string => $rule[0]['value'], $group['location']);

    expect($values)->toBe(['home', 'hub-servicio', 'servicio', 'ciudad', 'equipos', 'landing', 'equipo'])
        ->and($group['position'])->toBe('side')
        ->and(FieldGroups::ratings()['position'])->toBe('side')
        ->and(array_column($group['fields'][0]['sub_fields'], 'name'))->toBe(['service', 'mode', 'title'])
        ->and($group['fields'][0]['sub_fields'][0]['key'])->toBe('field_bp_lead_service');
});

it('imports forms and the per-page lead from the seed with the same keys', function (): void {
    $warnings = [];
    $mapper = new FieldValueMapper(new FakeSeedLookup(pages: ['/alquiler-de-banos-portatiles/' => 11]), function (string $w) use (&$warnings): void {
        $warnings[] = $w;
    });

    $values = $mapper->site(['forms' => [
        'turnstile_site_key' => '',
        'cta_mode' => 'modal',
        'modal' => ['eyebrow' => 'Cotización gratuita', 'title' => 'Cotiza sin compromiso', 'subtitle' => 'Déjanos tu nombre.', 'success' => 'Te contactaremos pronto.'],
        'whatsapp' => ['bg' => '#F5B400', 'text' => '#16221e'],
    ]]);

    expect($values['forms'])->toBe([
        'turnstile_site_key' => '',
        'cta_mode' => 'modal',
        'modal' => ['eyebrow' => 'Cotización gratuita', 'title' => 'Cotiza sin compromiso', 'subtitle' => 'Déjanos tu nombre.', 'success' => 'Te contactaremos pronto.'],
        'whatsapp' => ['bg' => '#f5b400', 'text' => '#16221e'],
    ])->and(QuoteCta::site($values['forms'])['whatsapp'])->toBe(['bg' => '#f5b400', 'text' => '#16221e'])
        ->and($mapper->lead(['title' => 'Sin lead']))->toBeNull()
        ->and($mapper->lead(['lead' => ['service' => '/alquiler-de-banos-portatiles/', 'mode' => 'página', 'title' => 'Cotiza tu evento']]))
        ->toBe(['service' => 11, 'mode' => 'page', 'title' => 'Cotiza tu evento'])
        ->and($mapper->lead(['lead' => ['service' => ['uri' => '/no-existe/'], 'mode' => 'popup']]))
        ->toBe(['service' => '', 'mode' => 'inherit', 'title' => ''])
        ->and($warnings)->toHaveCount(2)
        ->and($mapper->site(['forms' => ['cta_mode' => 'popup', 'whatsapp' => ['bg' => 'verde']]])['forms'])
        ->toBe(['cta_mode' => 'modal', 'whatsapp' => ['bg' => '', 'text' => '']]);
});
