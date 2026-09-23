<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Normalizer;

use BanosPortatiles\Headless\Content\PostTypes;
use BanosPortatiles\Headless\Html\ContentRenderer;
use BanosPortatiles\Headless\Routing\UriResolver;

final class WpReferenceResolver implements ReferenceResolver
{
    public function __construct(
        private readonly UriResolver $uris,
        private readonly ContentRenderer $renderer,
    ) {}

    public function slug(int $postId): ?string
    {
        $post = $this->published($postId);

        return $post?->post_name;
    }

    public function uri(int $postId): ?string
    {
        $post = $this->published($postId);

        return $post !== null ? $this->uris->forPost($post) : null;
    }

    public function termSlug(int $termId): ?string
    {
        $term = get_term($termId);

        return $term instanceof \WP_Term ? $term->slug : null;
    }

    public function image(int $attachmentId): ?array
    {
        $source = wp_get_attachment_image_src($attachmentId, 'full');
        if (! is_array($source) || ! is_string($source[0] ?? null)) {
            return null;
        }

        return [
            'src' => $source[0],
            'width' => (int) $source[1],
            'height' => (int) $source[2],
            'alt' => trim((string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true)),
        ];
    }

    public function faq(int $postId): ?array
    {
        $post = $this->published($postId);
        if ($post === null || $post->post_type !== PostTypes::FAQ) {
            return null;
        }

        $answer = $this->renderer->fragment($post->post_content);

        return ($post->post_title !== '' && $answer !== '') ? ['q' => $post->post_title, 'a' => $answer] : null;
    }

    public function fileUrl(int $attachmentId): ?string
    {
        $url = wp_get_attachment_url($attachmentId);

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function published(int $postId): ?\WP_Post
    {
        $post = get_post($postId);

        return ($post instanceof \WP_Post && $post->post_status === 'publish' && $post->post_password === '') ? $post : null;
    }
}
