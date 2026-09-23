<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless;

/**
 * Minimal PSR-4 autoloader so the plugin has no Composer dependency at runtime.
 */
final class Autoloader
{
    public static function register(string $namespace, string $directory): void
    {
        $prefix = rtrim($namespace, '\\').'\\';
        $base = rtrim($directory, '/\\').'/';

        spl_autoload_register(static function (string $class) use ($prefix, $base): void {
            if (! str_starts_with($class, $prefix)) {
                return;
            }

            $file = $base.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

            if (is_file($file)) {
                require_once $file;
            }
        });
    }
}
