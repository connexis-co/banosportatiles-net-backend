<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Support;

/**
 * Named MariaDB/MySQL lock (GET_LOCK): serializes concurrent writes on the same key (e.g. two identical votes
 * arriving together) so the "already voted?" check and the insert cannot interleave. Released explicitly or
 * when the connection ends. Without a database (unit tests) it is a no-op that always succeeds.
 */
final class DbLock
{
    public static function acquire(string $key, int $timeout = 5): bool
    {
        global $wpdb;
        if (! $wpdb instanceof \wpdb) {
            return true;
        }
        $sql = $wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::name($key, self::scope($wpdb)), max(0, $timeout));

        return is_string($sql) && (string) $wpdb->get_var($sql) === '1';
    }

    public static function release(string $key): void
    {
        global $wpdb;
        if (! $wpdb instanceof \wpdb) {
            return;
        }
        $sql = $wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::name($key, self::scope($wpdb)));
        if (is_string($sql)) {
            $wpdb->query($sql);
        }
    }

    /** Lock names are limited to 64 characters and shared by the whole database server: prefix + hash of the site. */
    public static function name(string $key, string $scope = ''): string
    {
        return 'bp_'.md5($scope.'|'.$key);
    }

    private static function scope(\wpdb $wpdb): string
    {
        return (defined('DB_NAME') && is_string(DB_NAME) ? DB_NAME : '').'.'.$wpdb->prefix;
    }
}
