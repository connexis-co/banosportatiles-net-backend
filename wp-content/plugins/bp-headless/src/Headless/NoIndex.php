<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Headless;

use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * The CMS host must never be indexed: X-Robots-Tag on every PHP response, robots.txt Disallow: /,
 * meta robots noindex and core sitemaps off. (REST responses get the header in Rest\HttpCache.)
 */
final class NoIndex implements Hookable
{
    public const HEADER = 'noindex, nofollow';

    public function register(): void
    {
        add_action('init', [$this, 'sendHeader'], 0);
        add_filter('robots_txt', static fn (): string => "User-agent: *\nDisallow: /\n", 99);
        add_filter('wp_robots', static fn (array $robots): array => ['noindex' => true, 'nofollow' => true] + $robots, 99);
        add_filter('wp_sitemaps_enabled', '__return_false');
    }

    public function sendHeader(): void
    {
        if (! headers_sent()) {
            header('X-Robots-Tag: '.self::HEADER, true);
        }
    }
}
