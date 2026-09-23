<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Rest\Controllers;

use BanosPortatiles\Headless\Cache\ResponseCache;
use BanosPortatiles\Headless\Normalizer\NodeNormalizer;
use BanosPortatiles\Headless\Routing\UriResolver;
use BanosPortatiles\Headless\Security\PreviewToken;

/**
 * GET /bp/v1/node?uri=/ruta/ → one Node. Drafts/private content only with a valid preview token
 * (?token=…, issued by the preview link) or an authenticated user who can edit the post.
 */
final class NodeController
{
    public function __construct(
        private readonly ResponseCache $cache,
        private readonly NodeNormalizer $normalizer,
        private readonly UriResolver $uris,
        private readonly PreviewToken $tokens,
    ) {}

    public function handle(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $uri = trim((string) $request->get_param('uri'));
        $id = (int) $request->get_param('id');
        $token = trim((string) $request->get_param('token'));

        if ($token !== '') {
            $previewId = $this->tokens->verify($token, time());
            if ($previewId === null) {
                return new \WP_Error('bp_invalid_preview_token', 'El token de preview no es válido o expiró.', ['status' => 401]);
            }

            return $this->preview(get_post($previewId));
        }

        if ($uri === '' && $id === 0) {
            return new \WP_Error('bp_missing_uri', 'Indica el parámetro uri (p. ej. /alquiler-de-banos-portatiles/).', ['status' => 400]);
        }

        $post = $id > 0 ? get_post($id) : $this->uris->resolve($uri, is_user_logged_in());
        if (! $post instanceof \WP_Post || ! in_array($post->post_type, UriResolver::NODE_TYPES, true)) {
            return self::notFound();
        }

        if ($post->post_status !== 'publish' || $post->post_password !== '') {
            return current_user_can('edit_post', $post->ID) ? $this->preview($post) : self::notFound();
        }

        return new \WP_REST_Response($this->cache->remember('node:'.$post->ID, fn (): array => $this->normalizer->normalize($post)));
    }

    private function preview(mixed $post): \WP_REST_Response|\WP_Error
    {
        if (! $post instanceof \WP_Post || ! in_array($post->post_type, UriResolver::NODE_TYPES, true) || $post->post_status === 'trash') {
            return self::notFound();
        }

        $response = new \WP_REST_Response($this->normalizer->normalize($this->withAutosave($post), true));
        $response->header('Cache-Control', 'private, no-store');

        return $response;
    }

    /** Published posts preview their newest autosave (title, content and excerpt). */
    private function withAutosave(\WP_Post $post): \WP_Post
    {
        $autosave = wp_get_post_autosave($post->ID);
        if (! $autosave instanceof \WP_Post || strtotime($autosave->post_modified_gmt) <= strtotime($post->post_modified_gmt)) {
            return $post;
        }

        $clone = clone $post;
        $clone->post_title = $autosave->post_title;
        $clone->post_content = $autosave->post_content;
        $clone->post_excerpt = $autosave->post_excerpt;

        return $clone;
    }

    private static function notFound(): \WP_Error
    {
        return new \WP_Error('bp_not_found', 'No existe contenido publicado en esa URI.', ['status' => 404]);
    }
}
