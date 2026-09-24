<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Import;

/**
 * Content fingerprint of the local images a seed item references (image fields and <img src> in HTML). It is part
 * of the item hash, so an image replaced in place (same path, new file) re-imports the item without --force.
 * Items without local images keep the plain item hash.
 */
final class AssetFingerprint
{
    private const IMAGE = '(?!https?:|\/\/|data:)[^\s<>"\'?#]+\.(?:jpe?g|png|webp|avif|gif|svg)';

    /** @var array<string, string> path → sha1 */
    private array $sha1 = [];

    /**
     * @param  \Closure(string): ?string  $resolve  src → absolute path of the local file (null when not found).
     */
    public function __construct(private readonly \Closure $resolve) {}

    /** @param array<array-key, mixed> $item */
    public function hash(array $item): string
    {
        return md5(serialize($item).$this->of($item));
    }

    /** @param array<array-key, mixed> $item */
    public function of(array $item): string
    {
        $parts = [];
        foreach (self::references($item) as $src) {
            $path = ($this->resolve)($src);
            if ($path !== null && is_file($path)) {
                $parts[] = $src.'='.($this->sha1[$path] ??= (string) sha1_file($path));
            }
        }

        return $parts === [] ? '' : sha1(implode("\n", $parts));
    }

    /**
     * Local image references in order: string values that are an image path, and src attributes of <img> tags
     * inside HTML strings (remote URLs and data: URIs are left out).
     *
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    public static function references(array $data): array
    {
        $found = [];
        array_walk_recursive($data, static function (mixed $value) use (&$found): void {
            if (! is_string($value) || $value === '') {
                return;
            }
            $value = trim($value);
            if (preg_match('~^'.self::IMAGE.'$~i', $value) === 1) {
                $found[] = $value;

                return;
            }
            if (stripos($value, '<img') !== false && preg_match_all('~<img\b[^>]*?\ssrc=["\']('.self::IMAGE.')(?:[?#][^"\']*)?["\']~i', $value, $matches) > 0) {
                array_push($found, ...$matches[1]);
            }
        });

        return array_values(array_unique($found));
    }
}
