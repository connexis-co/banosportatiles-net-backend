<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Security\PreviewToken;

it('issues URL-safe tokens that verify until they expire', function (): void {
    $tokens = new PreviewToken('preview-secret', 900);
    $token = $tokens->issue(42, 1000);

    expect($token)->toMatch('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/')
        ->and($tokens->verify($token, 1000))->toBe(42)
        ->and($tokens->verify($token, 1900))->toBe(42)
        ->and($tokens->verify($token, 1901))->toBeNull();
});

it('rejects tampered, foreign and malformed tokens', function (): void {
    $tokens = new PreviewToken('preview-secret');
    $token = $tokens->issue(42, 1000);
    [$payload, $signature] = explode('.', $token);
    $forgedPayload = rtrim(strtr(base64_encode('{"p":1,"e":9999999999}'), '+/', '-_'), '=');

    expect($tokens->verify($forgedPayload.'.'.$signature, 1000))->toBeNull()
        ->and((new PreviewToken('otro'))->verify($token, 1000))->toBeNull()
        ->and($tokens->verify('garbage', 1000))->toBeNull()
        ->and($tokens->verify('', 1000))->toBeNull()
        ->and($tokens->verify($payload.'.', 1000))->toBeNull();
});
