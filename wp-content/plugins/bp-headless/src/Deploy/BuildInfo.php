<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Deploy;

use BanosPortatiles\Headless\Config;

/**
 * What the public site is serving: {BP_FRONTEND_URL}/build.json = {contentVersion, builtAt, commit?}, written by
 * the Astro build. Read from the server with a short timeout and kept 20 s in a transient (also when it fails), so
 * any number of admin screens costs at most one request every 20 s.
 */
final class BuildInfo
{
    public const TRANSIENT = 'bp_headless_build_info';

    public const TTL = 20;

    public const TIMEOUT = 3;

    public function __construct(private readonly Config $config) {}

    public function url(): string
    {
        return $this->config->frontendUrl().'/build.json';
    }

    /**
     * The cached result, without any request: hit = false when there is nothing cached.
     *
     * @return array{hit: bool, build: array{version: string, builtAt: ?int, commit: ?string}|null}
     */
    public function cached(): array
    {
        $cached = get_transient(self::TRANSIENT);

        return is_array($cached) && array_key_exists('build', $cached)
            ? ['hit' => true, 'build' => self::valid($cached['build'])]
            : ['hit' => false, 'build' => null];
    }

    /**
     * build.json (cached 20 s); null when it cannot be read or is not valid.
     *
     * @return array{version: string, builtAt: ?int, commit: ?string}|null
     */
    public function get(): ?array
    {
        $cached = $this->cached();
        if ($cached['hit']) {
            return $cached['build'];
        }

        $response = wp_remote_get(add_query_arg('t', (string) intdiv(time(), self::TTL), $this->url()), [
            'timeout' => self::TIMEOUT,
            'redirection' => 1,
            'user-agent' => 'bp-headless/'.\BanosPortatiles\Headless\VERSION,
            'reject_unsafe_urls' => ! $this->config->isLocal(),
            'headers' => ['Accept' => 'application/json', 'Cache-Control' => 'no-cache'],
        ]);
        $build = ! is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200
            ? self::parse(wp_remote_retrieve_body($response))
            : null;
        set_transient(self::TRANSIENT, ['build' => $build], self::TTL);

        return $build;
    }

    /**
     * build.json → {version, builtAt (unix), commit}. builtAt accepts an ISO 8601 date or a unix timestamp
     * (seconds or milliseconds).
     *
     * @return array{version: string, builtAt: ?int, commit: ?string}|null
     */
    public static function parse(string $json): ?array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return null;
        }
        $version = $data['contentVersion'] ?? null;
        if (is_int($version)) {
            $version = (string) $version;
        }
        if (! is_string($version) || trim($version) === '' || strlen($version) > 100) {
            return null;
        }
        $commit = $data['commit'] ?? null;

        return [
            'version' => trim($version),
            'builtAt' => self::timestamp($data['builtAt'] ?? null),
            'commit' => is_string($commit) && preg_match('/^[0-9a-f]{7,40}$/i', $commit) === 1 ? strtolower($commit) : null,
        ];
    }

    private static function timestamp(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            $seconds = (int) ($value > 100_000_000_000 ? $value / 1000 : $value);

            return $seconds > 0 ? $seconds : null;
        }
        if (is_string($value) && $value !== '') {
            $time = strtotime($value);

            return $time !== false ? $time : null;
        }

        return null;
    }

    /**
     * @return array{version: string, builtAt: ?int, commit: ?string}|null
     */
    private static function valid(mixed $build): ?array
    {
        if (! is_array($build) || ! is_string($build['version'] ?? null)) {
            return null;
        }

        return [
            'version' => $build['version'],
            'builtAt' => is_int($build['builtAt'] ?? null) ? $build['builtAt'] : null,
            'commit' => is_string($build['commit'] ?? null) ? $build['commit'] : null,
        ];
    }
}
