<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Normalizer;

use BanosPortatiles\Headless\Support\Arr;

/**
 * SCF group "price" → Node "price" (contract §3.1), pure. The price exists only when the owner turned
 * "Mostrar precio" on and it is coherent: integer COP amounts above zero and min < max in ranges. Anything
 * else is omitted (never guessed). The seed carries no prices: they are set by the owner in WordPress.
 *
 *   {mode, amount? | min?, max?, currency: "COP", unit?: {code, label}, taxIncluded, validUntil?, updated?, note?, availability}
 */
final class PriceNormalizer
{
    public const MODES = ['from' => 'Desde (precio mínimo)', 'fixed' => 'Precio fijo', 'range' => 'Rango (mínimo y máximo)'];

    /** Select value → [UN/CEFACT code, visible label]. C62 has two labels: service or unit. */
    public const UNITS = [
        'DAY' => ['DAY', 'por día'],
        'WEE' => ['WEE', 'por semana'],
        'MON' => ['MON', 'por mes'],
        'HUR' => ['HUR', 'por hora'],
        'C62' => ['C62', 'por servicio'],
        'C62_unidad' => ['C62', 'por unidad'],
        'MTQ' => ['MTQ', 'por m³'],
        'LTR' => ['LTR', 'por litro'],
        'MTK' => ['MTK', 'por m²'],
        'MTR' => ['MTR', 'por metro'],
    ];

    public const AVAILABILITY = [
        'InStock' => 'Disponible',
        'LimitedAvailability' => 'Disponibilidad limitada',
        'PreOrder' => 'Bajo pedido',
        'OutOfStock' => 'Agotado',
    ];

    public const SCHEMA_TYPES = [
        'auto' => 'Automático: Product + Service si hay precio o valoraciones; si no, Service',
        'service' => 'Service',
        'product' => 'Product',
    ];

    /** Node templates with a price and a schema type (PageTemplates::slugFor). */
    public const TEMPLATES = ['hub-servicio', 'servicio', 'ciudad', 'equipo'];

    public const CURRENCY = 'COP';

    public const MAX_NOTE = 200;

    /** @return array<string, string> select choices: "por día (DAY)"… */
    public static function unitChoices(): array
    {
        $choices = [];
        foreach (self::UNITS as $value => [$code, $label]) {
            $choices[$value] = $label.' ('.$code.')';
        }

        return $choices;
    }

    public static function supports(string $template): bool
    {
        return in_array($template, self::TEMPLATES, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function normalize(mixed $price): ?array
    {
        $price = is_array($price) ? $price : [];
        if (! Arr::bool($price, 'show')) {
            return null;
        }

        $mode = Arr::string($price, 'mode') ?: 'from';
        $out = ['mode' => $mode];
        if ($mode === 'range') {
            $min = self::amount($price['min'] ?? null);
            $max = self::amount($price['max'] ?? null);
            if ($min === null || $max === null || $min >= $max) {
                return null;
            }
            $out += ['min' => $min, 'max' => $max];
        } elseif (isset(self::MODES[$mode])) {
            $amount = self::amount($price['amount'] ?? null);
            if ($amount === null) {
                return null;
            }
            $out['amount'] = $amount;
        } else {
            return null;
        }

        $out['currency'] = self::CURRENCY;
        $unit = self::UNITS[Arr::string($price, 'unit')] ?? null;
        if ($unit !== null) {
            $out['unit'] = ['code' => $unit[0], 'label' => $unit[1]];
        }
        $out['taxIncluded'] = Arr::bool($price, 'tax_included');

        $out += Arr::withoutEmpty([
            'validUntil' => self::date(Arr::string($price, 'valid_until')),
            'updated' => self::date(Arr::string($price, 'updated')),
            'note' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags(Arr::string($price, 'note')))), 0, self::MAX_NOTE),
        ]);

        $availability = Arr::string($price, 'availability');
        $out['availability'] = isset(self::AVAILABILITY[$availability]) ? $availability : 'InStock';

        return $out;
    }

    public static function schemaType(mixed $value): string
    {
        return is_string($value) && isset(self::SCHEMA_TYPES[$value]) ? $value : 'auto';
    }

    /** Integer amount above zero: 150000, "150000" or 150000.0; decimals, zero and text are rejected. */
    public static function amount(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_float($value)) {
            return ($value > 0 && floor($value) === $value && $value <= PHP_INT_MAX) ? (int) $value : null;
        }
        if (is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1) {
            $int = (int) trim($value);

            return $int > 0 ? $int : null;
        }

        return null;
    }

    /** "2026-12-31" or SCF's raw "20261231" → "2026-12-31"; invalid dates → null. */
    public static function date(string $value): ?string
    {
        if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})$/', trim($value), $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $m[1].'-'.$m[2].'-'.$m[3];
    }
}
