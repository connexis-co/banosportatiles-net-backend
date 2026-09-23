<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Html;

/**
 * WordPress side of the content pipeline: blocks/autop/shortcodes → wp_kses → HtmlCleaner.
 * (wptexturize, smilies and capital_P_dangit are intentionally not applied.)
 */
final class ContentRenderer
{
    private const BLOCK_TAGS = '/<(p|h[1-6]|ul|ol|table|div|figure|blockquote|section|pre|hr)[\s>\/]/i';

    public function __construct(private readonly HtmlCleaner $cleaner) {}

    public function render(string $content): string
    {
        if (trim($content) === '') {
            return '';
        }

        if (has_blocks($content)) {
            $html = do_blocks($content);
        } else {
            $html = preg_match(self::BLOCK_TAGS, $content) === 1 ? $content : wpautop($content);
        }

        $html = do_shortcode(shortcode_unautop($html));
        $html = wp_filter_content_tags($html, 'bp_headless');
        $html = wp_kses($html, $this->allowedHtml());

        return $this->cleaner->clean($html);
    }

    /** Short rich text (FAQ answers): inline formatting, paragraphs and lists only. */
    public function fragment(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $allowed = [
            'a' => ['href' => true, 'title' => true, 'target' => true, 'rel' => true],
            'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'br' => [], 'p' => [], 'ul' => [], 'ol' => [], 'li' => [], 'code' => [],
        ];

        return $this->cleaner->clean(wp_kses(has_blocks($html) ? do_blocks($html) : $html, $allowed));
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function allowedHtml(): array
    {
        $allowed = wp_kses_allowed_html('post');
        $allowed['iframe'] = [
            'src' => true, 'width' => true, 'height' => true, 'title' => true, 'allow' => true,
            'allowfullscreen' => true, 'loading' => true, 'frameborder' => true, 'referrerpolicy' => true,
        ];
        foreach (['img', 'source'] as $tag) {
            $allowed[$tag] = ($allowed[$tag] ?? []) + ['srcset' => true, 'sizes' => true, 'loading' => true, 'decoding' => true, 'width' => true, 'height' => true];
        }

        return $allowed;
    }
}
