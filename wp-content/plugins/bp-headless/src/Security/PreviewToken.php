<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Security;

/**
 * Short-lived, stateless preview tokens: base64url({"p":postId,"e":expires}) "." base64url(hmac).
 */
final class PreviewToken
{
    public const TTL = 900;

    public function __construct(
        #[\SensitiveParameter] private readonly string $secret,
        private readonly int $ttl = self::TTL,
    ) {}

    public function issue(int $postId, int $now): string
    {
        $payload = self::encode((string) json_encode(['p' => $postId, 'e' => $now + $this->ttl]));

        return $payload.'.'.self::encode(hash_hmac('sha256', $payload, $this->secret, true));
    }

    /** Returns the post ID when the token is authentic and not expired. */
    public function verify(string $token, int $now): ?int
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 2 || $this->secret === '') {
            return null;
        }
        [$payload, $signature] = $parts;
        if (! hash_equals(self::encode(hash_hmac('sha256', $payload, $this->secret, true)), $signature)) {
            return null;
        }

        $data = json_decode(self::decode($payload), true);
        if (! is_array($data) || ! is_int($data['p'] ?? null) || ! is_int($data['e'] ?? null)) {
            return null;
        }

        return ($data['e'] >= $now && $data['p'] > 0) ? $data['p'] : null;
    }

    private static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function decode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
