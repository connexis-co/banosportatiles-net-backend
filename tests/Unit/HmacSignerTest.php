<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Security\HmacSigner;
use BanosPortatiles\Headless\Security\SignatureStatus;

const SECRET = 'test-secret-0123456789abcdef';
const NOW = 1_790_000_000;

it('accepts a valid signature bound to the timestamp', function (): void {
    $signer = new HmacSigner(SECRET);
    $body = '{"nombre":"Ana"}';
    $signature = $signer->sign($body, NOW);

    expect($signature)->toStartWith('sha256=')
        ->and($signer->verify($body, $signature, (string) NOW, NOW))->toBe(SignatureStatus::Valid)
        ->and($signer->verify($body, strtoupper(substr($signature, 7)), (string) NOW, NOW + 10))->toBe(SignatureStatus::Valid);
});

it('rejects missing, tampered, forged and stale requests', function (): void {
    $signer = new HmacSigner(SECRET);
    $body = '{"nombre":"Ana"}';
    $signature = $signer->sign($body, NOW);

    expect($signer->verify($body, null, (string) NOW, NOW))->toBe(SignatureStatus::Missing)
        ->and($signer->verify($body, $signature, '', NOW))->toBe(SignatureStatus::Missing)
        ->and($signer->verify('{"nombre":"Eva"}', $signature, (string) NOW, NOW))->toBe(SignatureStatus::Invalid)
        ->and($signer->verify($body, $signature, (string) (NOW + 1), NOW))->toBe(SignatureStatus::Invalid)
        ->and((new HmacSigner('otro-secreto'))->verify($body, $signature, (string) NOW, NOW))->toBe(SignatureStatus::Invalid)
        ->and($signer->verify($body, $signature, (string) NOW, NOW + 301))->toBe(SignatureStatus::Expired)
        ->and($signer->verify($body, $signature, (string) NOW, NOW - 301))->toBe(SignatureStatus::Expired)
        ->and($signer->verify($body, $signature, 'abc', NOW))->toBe(SignatureStatus::Expired)
        ->and((new HmacSigner(''))->verify($body, $signature, (string) NOW, NOW))->toBe(SignatureStatus::Missing);
});

it('accepts the ±5 minute boundary', function (): void {
    $signer = new HmacSigner(SECRET);
    $signature = $signer->sign('x', NOW);

    expect($signer->verify('x', $signature, (string) NOW, NOW + 300))->toBe(SignatureStatus::Valid)
        ->and($signer->verify('x', $signature, (string) NOW, NOW - 300))->toBe(SignatureStatus::Valid);
});
