<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Leads;

/**
 * Fixed-window rate limiter backed by transients (per bucket + client identifier).
 */
final class RateLimiter
{
    public function hit(string $bucket, string $identifier, int $max, int $window, ?int $now = null): RateLimitResult
    {
        $now ??= time();
        $key = self::key($bucket, $identifier);
        $state = $this->state($key, $now);

        if ($state['count'] >= $max) {
            return new RateLimitResult(false, 0, max(1, $state['reset'] - $now));
        }

        $state = ['count' => $state['count'] + 1, 'reset' => $state['reset'] > $now ? $state['reset'] : $now + $window];
        set_transient($key, $state, max(1, $state['reset'] - $now));

        return new RateLimitResult(true, $max - $state['count'], 0);
    }

    public function tooMany(string $bucket, string $identifier, int $max, ?int $now = null): bool
    {
        return $this->state(self::key($bucket, $identifier), $now ?? time())['count'] >= $max;
    }

    public static function key(string $bucket, string $identifier): string
    {
        return 'bp_rl_'.md5($bucket.'|'.$identifier);
    }

    /**
     * @return array{count: int, reset: int}
     */
    private function state(string $key, int $now): array
    {
        $state = get_transient($key);
        if (! is_array($state) || ! is_int($state['count'] ?? null) || ! is_int($state['reset'] ?? null) || $state['reset'] <= $now) {
            return ['count' => 0, 'reset' => 0];
        }

        return ['count' => $state['count'], 'reset' => $state['reset']];
    }
}
