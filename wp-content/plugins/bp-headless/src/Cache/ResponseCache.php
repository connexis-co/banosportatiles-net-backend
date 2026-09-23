<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Cache;

/**
 * Transient cache for API payloads. Keys are namespaced by a version number that is bumped on every
 * content change, so invalidation is O(1) and also works with a persistent object cache.
 */
final class ResponseCache
{
    public const TTL = DAY_IN_SECONDS;

    private const VERSION_OPTION = 'bp_headless_cache_version';

    private const PREFIX = 'bp_api_';

    /**
     * @template T
     *
     * @param  callable(): T  $producer
     * @return T
     */
    public function remember(string $key, callable $producer, int $ttl = self::TTL): mixed
    {
        if (! (bool) apply_filters('bp_headless/cache_enabled', true, $key)) {
            return $producer();
        }

        $transient = self::PREFIX.md5($this->version().'|'.$key);
        $cached = get_transient($transient);
        if ($cached !== false) {
            /** @var T $cached */
            return $cached;
        }

        $value = $producer();
        set_transient($transient, $value, $ttl);

        return $value;
    }

    public function version(): string
    {
        $version = get_option(self::VERSION_OPTION);
        if (! is_string($version) || $version === '') {
            $version = (string) time();
            update_option(self::VERSION_OPTION, $version, true);
        }

        return $version;
    }

    public function flush(): void
    {
        global $wpdb;

        update_option(self::VERSION_OPTION, str_replace('.', '', uniqid('', true)), true);

        if (! wp_using_ext_object_cache() && $wpdb instanceof \wpdb) {
            $sql = $wpdb->prepare(
                'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
                $wpdb->options,
                $wpdb->esc_like('_transient_'.self::PREFIX).'%',
                $wpdb->esc_like('_transient_timeout_'.self::PREFIX).'%'
            );
            if (is_string($sql)) {
                $wpdb->query($sql);
            }
        }
    }
}
