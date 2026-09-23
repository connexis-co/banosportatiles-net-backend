<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Support;

/**
 * Typed accessors for loosely typed arrays (SCF values, JSON payloads, seed frontmatter).
 */
final class Arr
{
    /** @param array<array-key, mixed> $data */
    public static function string(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /** @param array<array-key, mixed> $data */
    public static function int(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /** @param array<array-key, mixed> $data */
    public static function float(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /** @param array<array-key, mixed> $data */
    public static function bool(array $data, string $key, bool $default = false): bool
    {
        $value = $data[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) (is_scalar($value) ? $value : ''))), ['1', 'true', 'on', 'yes', 'si', 'sí'], true);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function array(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * List of associative rows (repeaters, frontmatter lists of objects). Non-array rows are dropped.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<array<array-key, mixed>>
     */
    public static function rows(array $data, string $key): array
    {
        return array_values(array_filter(self::array($data, $key), 'is_array'));
    }

    /**
     * List of non-empty strings. Accepts scalars or single-field rows (["text" => "…"]).
     *
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    public static function strings(array $data, string $key, string $rowField = 'text'): array
    {
        $out = [];
        foreach (self::array($data, $key) as $item) {
            $value = is_array($item) ? self::string($item, $rowField) : (is_scalar($item) ? trim((string) $item) : '');
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  mixed  $value  int, numeric string, WP object with ID/term_id, or a list of those.
     * @return list<int>
     */
    public static function ids(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];
        $ids = [];
        foreach ($items as $item) {
            $id = match (true) {
                is_int($item) => $item,
                is_string($item) && ctype_digit($item) => (int) $item,
                $item instanceof \WP_Post => $item->ID,
                $item instanceof \WP_Term => $item->term_id,
                default => 0,
            };
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Splits a textarea into trimmed, non-empty lines.
     *
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn (string $l): bool => $l !== ''));
    }

    /**
     * Drops keys whose value is null or an empty string (optional properties of the API contract).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withoutEmpty(array $data): array
    {
        return array_filter($data, static fn (mixed $v): bool => $v !== null && $v !== '');
    }
}
