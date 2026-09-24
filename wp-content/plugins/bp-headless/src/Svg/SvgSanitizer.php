<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Svg;

use enshrined\svgSanitize\Sanitizer;

/**
 * SVG markup that is safe to inline in the public site (the brand logo of /site → brand.logo.svg).
 *
 * 1. Safe SVG's sanitizer (enshrined\svgSanitize) when the plugin is active, with remote references removed.
 * 2. Always, a DOM allowlist of its own: only drawing elements survive (no script, foreignObject, iframe,
 *    animation or <a>), no on* handlers, href/xlink:href only to "#fragments" (or raster data: images inside
 *    <image>), no url(...) to anything but "#id" in attributes or <style>, no @import/expression/javascript:.
 *    No DOCTYPE entities (XXE) and at most 100 KB.
 */
final class SvgSanitizer
{
    public const MAX_BYTES = 102400;

    private const ELEMENTS = [
        'svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'textpath',
        'defs', 'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask', 'pattern', 'symbol', 'use', 'marker',
        'title', 'desc', 'style', 'image', 'filter', 'feblend', 'fecolormatrix', 'fecomponenttransfer', 'fecomposite',
        'feflood', 'fegaussianblur', 'femerge', 'femergenode', 'femorphology', 'feoffset', 'fedropshadow', 'fefunca',
        'fefuncb', 'fefuncg', 'fefuncr',
    ];

    private const RASTER_DATA = '#^data:image/(png|jpe?g|gif|webp);base64,[a-z0-9+/=\s]+$#i';

    private const DANGEROUS = '#(javascript|vbscript|livescript)\s*:|expression\s*\(|@import|-moz-binding|behavior\s*:#i';

    public function sanitize(string $svg): ?string
    {
        if ($svg === '' || strlen($svg) > self::MAX_BYTES || stripos($svg, '<!ENTITY') !== false) {
            return null;
        }
        if (class_exists(Sanitizer::class)) {
            $sanitizer = new Sanitizer;
            $sanitizer->removeRemoteReferences(true);
            $clean = $sanitizer->sanitize($svg);
            if (! is_string($clean) || $clean === '') {
                return null;
            }
            $svg = $clean;
        }

        $svg = (string) preg_replace('/<!DOCTYPE[^>\[]*(\[[^\]]*\])?>/i', '', $svg);
        $doc = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($svg, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->documentElement;
        if (! $loaded || ! $root instanceof \DOMElement || self::name($root) !== 'svg') {
            return null;
        }
        $this->clean($root);

        $markup = $doc->saveXML($root);

        return is_string($markup) && $markup !== '' ? $markup : null;
    }

    /**
     * Intrinsic size from width/height (px or unitless) or from the viewBox.
     *
     * @return array{width: int, height: int}|null
     */
    public static function dimensions(string $svg): ?array
    {
        if (preg_match('/<svg\b[^>]*>/i', $svg, $tag) !== 1) {
            return null;
        }
        $attr = static fn (string $name): ?string => preg_match('/\s'.$name.'\s*=\s*["\']([^"\']*)["\']/i', $tag[0], $m) === 1 ? trim($m[1]) : null;
        $width = $attr('width');
        $height = $attr('height');
        if ($width !== null && $height !== null && preg_match('/^(\d+(?:\.\d+)?)(px)?$/', $width, $w) === 1 && preg_match('/^(\d+(?:\.\d+)?)(px)?$/', $height, $h) === 1) {
            return self::size((float) $w[1], (float) $h[1]);
        }
        $box = preg_split('/[\s,]+/', (string) $attr('viewBox'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count($box) === 4 && is_numeric($box[2]) && is_numeric($box[3]) ? self::size((float) $box[2], (float) $box[3]) : null;
    }

    /** @return array{width: int, height: int}|null */
    private static function size(float $width, float $height): ?array
    {
        $size = ['width' => (int) round($width), 'height' => (int) round($height)];

        return $size['width'] > 0 && $size['height'] > 0 ? $size : null;
    }

    private function clean(\DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            if ($attribute instanceof \DOMAttr && ! $this->allowedAttribute($element, $attribute)) {
                $element->removeAttributeNode($attribute);
            }
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                $name = self::name($child);
                if (! in_array($name, self::ELEMENTS, true) || ($name === 'style' && preg_match(self::DANGEROUS, $child->textContent) === 1)) {
                    $element->removeChild($child);

                    continue;
                }
                if ($name === 'style') {
                    $child->textContent = (string) preg_replace('/url\(\s*(?![\'"]?#)[^)]*\)/i', 'none', $child->textContent);
                }
                $this->clean($child);
            } elseif ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $element->removeChild($child);
            }
        }
    }

    private static function name(\DOMElement $element): string
    {
        return strtolower($element->localName ?? $element->tagName);
    }

    private function allowedAttribute(\DOMElement $element, \DOMAttr $attribute): bool
    {
        $name = strtolower($attribute->localName ?? $attribute->name);
        $value = trim($attribute->value);

        if (str_starts_with($name, 'on') || $name === 'base' || preg_match(self::DANGEROUS, $value) === 1) {
            return false;
        }
        if ($name === 'href') {
            return str_starts_with($value, '#')
                || (self::name($element) === 'image' && preg_match(self::RASTER_DATA, $value) === 1);
        }
        if (stripos($value, 'url(') !== false) {
            return preg_match('/url\(\s*(?![\'"]?#)/i', $value) !== 1;
        }

        return stripos($value, 'data:') === false || $name === 'd';
    }
}
