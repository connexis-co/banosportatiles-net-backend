<?php

/**
 * Prints the es_CO (or given locale) translation package URLs for WordPress core and plugins.
 * Runs with plain PHP inside the wpcli container (no WordPress bootstrap).
 * Usage: php wporg-l10n.php <locale> <core-version> [<plugin-slug>:<version> ...]
 */

declare(strict_types=1);

[$script, $locale, $coreVersion] = array_pad($argv, 3, '');
$plugins = array_slice($argv, 3);

$find = static function (string $url) use ($locale): ?string {
    $json = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 30]]));
    $data = is_string($json) ? json_decode($json, true) : null;
    foreach ($data['translations'] ?? [] as $translation) {
        if (($translation['language'] ?? '') === $locale) {
            return (string) $translation['package'];
        }
    }

    return null;
};

$core = $find('https://api.wordpress.org/translations/core/1.0/?version='.rawurlencode($coreVersion));
if ($core !== null) {
    echo "core {$core}\n";
}

foreach ($plugins as $plugin) {
    [$slug, $version] = array_pad(explode(':', $plugin, 2), 2, '');
    $url = $find('https://api.wordpress.org/translations/plugins/1.0/?slug='.rawurlencode($slug).'&version='.rawurlencode($version));
    if ($url !== null) {
        echo "plugins {$url}\n";
    }
}
