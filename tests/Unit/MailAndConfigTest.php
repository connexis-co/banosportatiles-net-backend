<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Mail\SmtpMailer;

afterEach(function (): void {
    foreach (['BP_LEADS_EMAIL', 'BP_SMTP_HOST', 'BP_SMTP_PORT', 'BP_SMTP_SECURE', 'BP_SMTP_FROM_NAME'] as $name) {
        putenv($name);
    }
});

it('sends lead emails by default and can be disabled with BP_LEADS_EMAIL', function (?string $value, bool $expected): void {
    $value === null ? putenv('BP_LEADS_EMAIL') : putenv('BP_LEADS_EMAIL='.$value);

    expect((new Config)->leadsEmailEnabled())->toBe($expected);
})->with([
    'unset' => [null, true],
    'empty' => ['', true],
    'true' => ['true', true],
    '1' => ['1', true],
    'false' => ['false', false],
    'FALSE' => ['FALSE', false],
    '0' => ['0', false],
    'off' => ['off', false],
    'no' => ['no', false],
]);

it('reads the SMTP settings (Brevo defaults to port 587)', function (): void {
    putenv('BP_SMTP_HOST=smtp-relay.brevo.com');
    putenv('BP_SMTP_SECURE=TLS');
    putenv('BP_SMTP_FROM_NAME=BañosPortátiles.net');

    expect((new Config)->smtp())->toMatchArray([
        'host' => 'smtp-relay.brevo.com',
        'port' => 587,
        'secure' => 'tls',
        'from_name' => 'BañosPortátiles.net',
    ]);
});

it('infers the SMTP encryption from BP_SMTP_SECURE or the port', function (string $secure, int $port, string $expected): void {
    expect(SmtpMailer::encryption($secure, $port))->toBe($expected);
})->with([
    'brevo 587 → STARTTLS' => ['', 587, 'tls'],
    '465 → implicit TLS' => ['', 465, 'ssl'],
    'other port → opportunistic' => ['', 2525, 'auto'],
    'explicit starttls' => ['starttls', 1025, 'tls'],
    'explicit ssl' => ['SSL', 587, 'ssl'],
    'explicit none' => ['none', 587, 'none'],
]);
