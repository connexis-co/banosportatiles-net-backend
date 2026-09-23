<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Security;

/**
 * HMAC-SHA256 request signing shared with the Astro front:
 *   X-BP-Timestamp: <unix seconds>
 *   X-BP-Signature: sha256=<hex(hmac_sha256(secret, "{timestamp}.{raw body}"))>
 * Binding the timestamp into the signed string makes the ±5 min window tamper-proof.
 */
final class HmacSigner
{
    public const TOLERANCE = 300;

    public function __construct(
        #[\SensitiveParameter] private readonly string $secret,
        private readonly int $tolerance = self::TOLERANCE,
    ) {}

    public function sign(string $body, int $timestamp): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $this->secret);
    }

    public function verify(string $body, ?string $signature, ?string $timestamp, int $now): SignatureStatus
    {
        $signature = strtolower(trim((string) $signature));
        $timestamp = trim((string) $timestamp);
        if ($this->secret === '' || $signature === '' || $timestamp === '') {
            return SignatureStatus::Missing;
        }
        if (! ctype_digit($timestamp) || abs($now - (int) $timestamp) > $this->tolerance) {
            return SignatureStatus::Expired;
        }

        $provided = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;
        $expected = substr($this->sign($body, (int) $timestamp), 7);

        return hash_equals($expected, $provided) ? SignatureStatus::Valid : SignatureStatus::Invalid;
    }
}
