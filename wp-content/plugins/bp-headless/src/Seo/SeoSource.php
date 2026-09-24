<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Seo;

/**
 * Where the SEO of a node comes from: Rank Math (RankMathSeoSource) or the SCF group «SEO» (ScfSeoSource).
 */
interface SeoSource
{
    public function available(): bool;

    /**
     * Node "seo" object (contract §3.1).
     *
     * @return array<string, mixed>
     */
    public function normalize(\WP_Post $post, string $title, string $excerpt, string $uri): array;

    public function noindex(\WP_Post $post): bool;
}
