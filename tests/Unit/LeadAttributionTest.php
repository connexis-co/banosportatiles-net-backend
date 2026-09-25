<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Leads\LeadAttribution;
use BanosPortatiles\Headless\Leads\LeadData;
use BanosPortatiles\Headless\Leads\LeadEmail;
use BanosPortatiles\Headless\Leads\LeadValidator;

/** @return array<string, mixed> Test-only payload (never seeded). */
function attributedLead(array $patch = []): array
{
    return array_replace([
        'nombre' => 'Ana Pérez',
        'telefono' => '3001234567',
        'consentimiento' => true,
        'servicio' => 'Alquiler de baños portátiles',
        'pagina' => '/alquiler-de-banos-portatiles/eventos/',
        'origen' => 'hero',
        'servicio_uri' => '/alquiler-de-banos-portatiles/',
        'referrer' => 'https://www.google.com/',
        'landing' => '/alquiler-de-banos-portatiles/?utm_source=google&utm_medium=cpc',
        'utm_source' => 'google',
        'utm_medium' => 'cpc',
        'utm_campaign' => 'banos-eventos',
        'utm_term' => 'alquiler baños portátiles',
        'utm_content' => 'anuncio-1',
        'gclid' => 'EAIaIQobChMI-test_123',
        'fbclid' => 'IwAR0test',
    ], $patch);
}

it('accepts the attribution fields and exposes them with their contract names', function (): void {
    $result = (new LeadValidator)->validate(attributedLead());
    $lead = $result->lead;

    expect($result->isValid())->toBeTrue()
        ->and($result->ignored)->toBe([])
        ->and($lead?->origen)->toBe('hero')
        ->and($lead?->servicioUri)->toBe('/alquiler-de-banos-portatiles/')
        ->and($lead?->referrer)->toBe('https://www.google.com/')
        ->and($lead?->landing)->toBe('/alquiler-de-banos-portatiles/?utm_source=google&utm_medium=cpc')
        ->and($lead?->utm)->toBe(['source' => 'google', 'medium' => 'cpc', 'campaign' => 'banos-eventos', 'term' => 'alquiler baños portátiles', 'content' => 'anuncio-1', 'gclid' => 'EAIaIQobChMI-test_123', 'fbclid' => 'IwAR0test'])
        ->and($lead?->attribution())->toBe([
            'origen' => 'hero',
            'servicio_uri' => '/alquiler-de-banos-portatiles/',
            'referrer' => 'https://www.google.com/',
            'landing' => '/alquiler-de-banos-portatiles/?utm_source=google&utm_medium=cpc',
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'banos-eventos',
            'utm_term' => 'alquiler baños portátiles',
            'utm_content' => 'anuncio-1',
            'gclid' => 'EAIaIQobChMI-test_123',
            'fbclid' => 'IwAR0test',
        ]);
});

it('never rejects a lead for its attribution: bad values are dropped and reported', function (): void {
    $result = (new LeadValidator)->validate(attributedLead([
        'origen' => 'Hero Principal',
        'servicio_uri' => 'https://banosportatiles.net/alquiler/',
        'referrer' => 'javascript:alert(1)',
        'landing' => '//evil.example/',
        'gclid' => 'abc<def>"',
        'gbraid' => str_repeat('a', 256),
        'utm_campaign' => str_repeat('c', 180),
    ]));

    expect($result->isValid())->toBeTrue()
        ->and($result->errors)->toBe([])
        ->and($result->ignored)->toBe(['origen', 'servicio_uri', 'referrer', 'landing', 'gclid', 'gbraid'])
        ->and($result->lead?->origen)->toBeNull()
        ->and($result->lead?->utm['campaign'])->toHaveLength(150)
        ->and($result->lead?->utm)->not->toHaveKeys(['gclid', 'gbraid'])
        ->and($result->lead?->attribution())->not->toHaveKeys(['origen', 'servicio_uri', 'referrer', 'landing', 'gclid', 'gbraid']);
});

it('validates each attribution format', function (): void {
    expect(LeadValidator::origen('header_movil'))->toBe('header_movil')
        ->and(LeadValidator::origen('pagina_cotizar'))->toBe('pagina_cotizar')
        ->and(LeadValidator::origen(str_repeat('a', 41)))->toBeNull()
        ->and(LeadValidator::origen('hero-2'))->toBeNull()
        ->and(LeadValidator::path('/pozos-septicos/', 255, false))->toBe('/pozos-septicos/')
        ->and(LeadValidator::path('/pozos-septicos/?x=1', 255, false))->toBeNull()
        ->and(LeadValidator::path('/pozos-septicos/?x=1', 500, true))->toBe('/pozos-septicos/?x=1')
        ->and(LeadValidator::path('/con espacio/', 255, false))->toBeNull()
        ->and(LeadValidator::referrer('https://www.google.com/search?q='.str_repeat('x', 600)))->toBe('https://www.google.com/search')
        ->and(LeadValidator::referrer('android-app://com.google.android.gm/'))->toBeNull()
        ->and(LeadValidator::clickId('Cj0KCQjw.abc~_-9'))->toBe('Cj0KCQjw.abc~_-9');
});

it('keeps reading the legacy nested "utm" object (current front payload)', function (): void {
    $lead = (new LeadValidator)->validate([
        'nombre' => 'Ana', 'telefono' => '3001234567', 'consentimiento' => true,
        'utm' => ['source' => 'facebook', 'utm_medium' => 'paid', 'fbclid' => 'IwAR1'],
        'utm_source' => 'google',
    ])->lead;

    expect($lead?->utm)->toBe(['source' => 'google', 'medium' => 'paid', 'fbclid' => 'IwAR1']);
});

it('names the acquisition channel and the CTA location', function (): void {
    expect(LeadAttribution::channel(['gclid' => 'x', 'utm_source' => 'google']))->toBe('Google Ads')
        ->and(LeadAttribution::channel(['wbraid' => 'x']))->toBe('Google Ads')
        ->and(LeadAttribution::channel(['fbclid' => 'x']))->toBe('Meta')
        ->and(LeadAttribution::channel(['utm_source' => 'newsletter', 'utm_medium' => 'email']))->toBe('newsletter / email')
        ->and(LeadAttribution::channel(['referrer' => 'https://www.bing.com/search?q=x']))->toBe('bing.com')
        ->and(LeadAttribution::channel([]))->toBe('Directo')
        ->and(LeadAttribution::summary(['origen' => 'header_movil', 'gclid' => 'x']))->toBe('Cabecera en el móvil · Google Ads')
        ->and(LeadAttribution::summary(['origen' => 'nuevo_boton']))->toBe('nuevo_boton · Directo')
        ->and(LeadAttribution::summary([]))->toBe('— · Directo');
});

it('shows the attribution in the lead email, with links to the public site', function (): void {
    $lead = (new LeadValidator)->validate(attributedLead())->lead;
    expect($lead)->toBeInstanceOf(LeadData::class);

    $email = new LeadEmail($lead, [
        'reference' => 'ref-1', 'created' => 1_790_195_400, 'ciudad' => null,
        'origin' => 'https://banosportatiles.net/alquiler-de-banos-portatiles/eventos/',
        'admin' => 'https://admin.banosportatiles.net/wp-admin/post.php?post=7&action=edit',
        'site' => 'BañosPortátiles.net', 'front' => 'https://banosportatiles.net',
    ]);

    expect($email->html())->toContain('Hero de la página')
        ->toContain('href="https://banosportatiles.net/alquiler-de-banos-portatiles/"')
        ->toContain('href="https://banosportatiles.net/alquiler-de-banos-portatiles/?utm_source=google&amp;utm_medium=cpc"')
        ->toContain('href="https://www.google.com/"')
        ->and($email->text())->toContain('Canal: Google Ads')
        ->toContain('Botón: Hero de la página')
        ->toContain('campaign=banos-eventos');

    $plain = (new LeadValidator)->validate(['nombre' => 'Ana', 'telefono' => '3001234567', 'consentimiento' => true])->lead;
    expect($plain)->not->toBeNull();
    expect((new LeadEmail($plain, ['reference' => 'r', 'created' => 1, 'ciudad' => null, 'origin' => null, 'admin' => 'a', 'site' => 's']))->text())
        ->toContain('Canal: Directo')
        ->not->toContain('Botón:')
        ->not->toContain('Llegó desde');
});

it('stores one meta per attribution field and reads old leads from their "utm" JSON', function (): void {
    $meta = [];
    Brain\Monkey\Functions\when('wp_insert_post')->justReturn(41);
    Brain\Monkey\Functions\when('wp_generate_uuid4')->justReturn('ref-41');
    Brain\Monkey\Functions\when('current_time')->justReturn('2026-09-24 10:00:00');
    Brain\Monkey\Functions\when('wp_json_encode')->alias(static fn (mixed $v): string|false => json_encode($v));
    Brain\Monkey\Functions\when('update_post_meta')->alias(static function (int $id, string $key, mixed $value) use (&$meta): bool {
        $meta[$id][$key] = $value;

        return true;
    });
    Brain\Monkey\Functions\when('get_post_meta')->alias(static function (int $id, string $key) use (&$meta): mixed {
        return $meta[$id][$key] ?? '';
    });

    $lead = (new LeadValidator)->validate(attributedLead(['fbclid' => '']))->lead;
    expect($lead)->not->toBeNull();
    $created = (new BanosPortatiles\Headless\Leads\LeadRepository)->create($lead, ['ip_hash' => 'h', 'user_agent' => 'ua']);

    expect($created)->toBe(['id' => 41, 'reference' => 'ref-41'])
        ->and($meta[41])->toMatchArray([
            '_bp_lead_origen' => 'hero',
            '_bp_lead_servicio_uri' => '/alquiler-de-banos-portatiles/',
            '_bp_lead_referrer' => 'https://www.google.com/',
            '_bp_lead_landing' => '/alquiler-de-banos-portatiles/?utm_source=google&utm_medium=cpc',
            '_bp_lead_utm_source' => 'google',
            '_bp_lead_utm_campaign' => 'banos-eventos',
            '_bp_lead_gclid' => 'EAIaIQobChMI-test_123',
        ])
        ->and($meta[41])->not->toHaveKey('_bp_lead_fbclid')
        ->and(array_keys(BanosPortatiles\Headless\Leads\LeadRepository::attribution(41)))->toBe(['origen', 'servicio_uri', 'landing', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid']);

    $meta[7] = ['_bp_lead_utm' => '{"source":"google","medium":"cpc","gclid":"abc"}'];
    expect(BanosPortatiles\Headless\Leads\LeadRepository::attribution(7))->toBe(['utm_source' => 'google', 'utm_medium' => 'cpc', 'gclid' => 'abc'])
        ->and(BanosPortatiles\Headless\Leads\LeadRepository::attribution(8))->toBe([]);
});
