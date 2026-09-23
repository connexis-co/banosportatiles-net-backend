<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Html;

/**
 * ASCII slugs for heading anchors: "¿Qué es un pozo séptico?" → "que-es-un-pozo-septico".
 */
final class Slugger
{
    private const FALLBACK_MAP = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'â' => 'a', 'ê' => 'e',
        'î' => 'i', 'ô' => 'o', 'û' => 'u', 'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o',
        'ç' => 'c', 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'ø' => 'o', 'å' => 'a',
    ];

    public static function slugify(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_strtolower($text, 'UTF-8');

        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $text = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
            }
        }

        $text = strtr($text, self::FALLBACK_MAP);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);

        return trim($text, '-');
    }
}
