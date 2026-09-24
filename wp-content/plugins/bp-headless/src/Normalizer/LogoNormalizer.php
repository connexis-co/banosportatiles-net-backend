<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Normalizer;

use BanosPortatiles\Headless\Support\Arr;
use BanosPortatiles\Headless\Svg\SvgSanitizer;

/**
 * Brand logo of «Ajustes del sitio → Marca» → Image & {svg?} (contract §3.2). For an SVG attachment the API also
 * returns its markup, sanitized and read from the file (max. 100 KB), so the front inlines it without another
 * request; width/height come from the SVG itself when WordPress does not know them.
 */
final class LogoNormalizer
{
    public const SVG_MIME = 'image/svg+xml';

    public function __construct(
        private readonly ReferenceResolver $refs,
        private readonly SvgSanitizer $sanitizer,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function normalize(mixed $value): ?array
    {
        $id = Arr::ids($value)[0] ?? 0;
        $image = $id > 0 ? $this->refs->image($id) : null;
        if ($image === null) {
            return null;
        }

        $logo = $image;
        if (get_post_mime_type($id) === self::SVG_MIME) {
            $markup = $this->markup($id);
            if ($markup !== null) {
                $logo['svg'] = $markup;
                $size = SvgSanitizer::dimensions($markup);
                if ($size !== null && ($image['width'] <= 1 || $image['height'] <= 1)) {
                    $logo['width'] = $size['width'];
                    $logo['height'] = $size['height'];
                }
            }
        }
        if (! is_int($logo['width']) || ! is_int($logo['height']) || $logo['width'] <= 1 || $logo['height'] <= 1) {
            unset($logo['width'], $logo['height']);
        }

        return $logo;
    }

    private function markup(int $attachmentId): ?string
    {
        $file = get_attached_file($attachmentId);
        if (! is_string($file) || ! is_readable($file) || (int) filesize($file) > SvgSanitizer::MAX_BYTES) {
            return null;
        }
        $contents = file_get_contents($file);

        return is_string($contents) ? $this->sanitizer->sanitize($contents) : null;
    }
}
