<?php

declare(strict_types=1);

namespace BanosPortatiles\SitioEnVenta\Support;

/**
 * Paths where the notice is hidden. One rule per line:
 *   /cotizar/    exact page (trailing slash optional, query and fragment ignored)
 *   /blog/*      the section and everything below it
 * Full URLs are reduced to their path.
 */
final class PathRules
{
    public const MAX_RULES = 50;

    /**
     * @param  string|array<array-key, mixed>  $input  Textarea (one rule per line) or list.
     * @return list<string>
     */
    public static function normalizeList(string|array $input): array
    {
        $lines = is_array($input) ? $input : (preg_split('/\R/u', $input) ?: []);
        $rules = [];
        foreach ($lines as $line) {
            $rule = is_scalar($line) ? self::normalize((string) $line) : null;
            if ($rule !== null) {
                $rules[$rule] = true;
            }
        }

        return array_slice(array_keys($rules), 0, self::MAX_RULES);
    }

    public static function normalize(string $rule): ?string
    {
        $rule = trim($rule);
        if ($rule === '' || str_starts_with($rule, '#')) {
            return null;
        }
        if (preg_match('#^https?://#i', $rule) === 1) {
            $rule = (string) (parse_url($rule, PHP_URL_PATH) ?? '/');
        }
        $rule = (string) preg_replace('/[?#].*$/s', '', $rule);
        $wildcard = str_ends_with($rule, '*');
        $path = self::path(rtrim($rule, '*'));

        return $wildcard ? $path.'*' : $path;
    }

    /**
     * @param  list<string>  $rules
     */
    public static function matches(string $path, array $rules): bool
    {
        $path = self::path((string) preg_replace('/[?#].*$/s', '', $path));
        foreach ($rules as $rule) {
            if (str_ends_with($rule, '*') ? str_starts_with($path, substr($rule, 0, -1)) : $path === $rule) {
                return true;
            }
        }

        return false;
    }

    /** "/a//b" → "/a/b/"; files keep no trailing slash; always absolute. */
    private static function path(string $path): string
    {
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== '' && $s !== '.' && $s !== '..'));
        if ($segments === []) {
            return '/';
        }
        $last = $segments[count($segments) - 1];

        return '/'.implode('/', $segments).(str_contains($last, '.') ? '' : '/');
    }
}
