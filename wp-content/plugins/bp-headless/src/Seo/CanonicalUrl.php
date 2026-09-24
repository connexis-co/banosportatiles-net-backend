<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Seo;

use BanosPortatiles\Headless\Routing\UriResolver;

/**
 * Canonical of a node as a PUBLIC absolute URL (pure). Paths and URLs of the CMS host or of the public domain
 * (www or not) are rebuilt on the public site; other domains are kept (cross-domain canonical). A canonical
 * that points to the node itself is omitted (contract §3.1: "se omite si es la propia").
 */
final class CanonicalUrl
{
    /**
     * @param  list<string>  $ownHosts  Hosts that mean "this site": the CMS and the public domain (with and without www).
     */
    public static function resolve(string $raw, string $ownUri, string $frontendUrl, array $ownHosts): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $front = rtrim($frontendUrl, '/');

        if (str_starts_with($raw, '/') && ! str_starts_with($raw, '//')) {
            $url = $front.UriResolver::normalize($raw);
        } else {
            $parts = parse_url($raw);
            if (! is_array($parts) || ! isset($parts['host'])) {
                return null;
            }
            $host = strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
            $url = in_array($host, array_map('strtolower', $ownHosts), true)
                ? $front.UriResolver::normalize((string) ($parts['path'] ?? '/'))
                : $raw;
        }

        return $url === $front.UriResolver::normalize($ownUri) ? null : $url;
    }
}
