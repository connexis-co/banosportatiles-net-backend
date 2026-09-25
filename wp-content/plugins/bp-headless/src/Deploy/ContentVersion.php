<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Deploy;

use BanosPortatiles\Headless\Cache\ContentChangeListener;
use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * Version of the public content: changes on every edit that the public site must publish (content, settings,
 * redirects, the «sitio en venta» notice, approved/removed reviews and seed imports). The front writes the
 * version it built into build.json; the admin compares both to tell whether the site is up to date.
 *
 * It is not the API cache version on purpose: that one also changes on pending reviews and manual flushes, which
 * would show «Cambios sin publicar» with nothing to publish.
 */
final class ContentVersion implements Hookable
{
    public const OPTION = 'bp_headless_content_version';

    /** For public changes that do not go through ContentChangeListener: do_action(ContentVersion::TOUCH, $reason). */
    public const TOUCH = 'bp_headless/public_content_changed';

    /** @var array{version: string, at: int}|null */
    private ?array $current = null;

    private bool $bumped = false;

    public function register(): void
    {
        // Priority 1: before the API cache flush (5), so no response is rebuilt with the previous version.
        add_action(ContentChangeListener::ACTION, [$this, 'bump'], 1, 0);
        add_action(self::TOUCH, [$this, 'bump'], 1, 0);
        add_action('bp_sitio_en_venta/updated', [$this, 'bump'], 1, 0);
    }

    /** A new version, once per request (a save that touches many fields is one change). */
    public function bump(): void
    {
        if ($this->bumped) {
            return;
        }
        $this->bumped = true;
        $this->current = ['version' => self::newVersion(), 'at' => time()];
        update_option(self::OPTION, $this->current, true);
    }

    /**
     * @return array{version: string, at: int}
     */
    public function current(): array
    {
        if ($this->current !== null) {
            return $this->current;
        }
        $stored = self::fromOption(get_option(self::OPTION));
        if ($stored === null) {
            $stored = ['version' => self::newVersion(), 'at' => time()];
            update_option(self::OPTION, $stored, true);
        }

        return $this->current = $stored;
    }

    /**
     * @return array{version: string, at: int}|null
     */
    public static function fromOption(mixed $value): ?array
    {
        if (! is_array($value) || ! is_string($value['version'] ?? null) || $value['version'] === '') {
            return null;
        }

        return ['version' => $value['version'], 'at' => is_numeric($value['at'] ?? null) ? (int) $value['at'] : 0];
    }

    /** Opaque and unique: "<unix time in base 36>-<6 hex>" (compare only for equality). */
    public static function newVersion(): string
    {
        return base_convert((string) time(), 10, 36).'-'.bin2hex(random_bytes(3));
    }
}
