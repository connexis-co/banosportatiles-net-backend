<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Tests\Fakes;

use BanosPortatiles\Headless\Seo\RankMathApi;

/**
 * Rank Math settings as an array and a tiny %variable% resolver (title, sep, sitename, excerpt).
 */
final class FakeRankMathApi implements RankMathApi
{
    /** @var list<string> */
    public array $resolved = [];

    /**
     * @param  array<string, mixed>  $settings  "titles.pt_page_title" => "…"
     */
    public function __construct(
        public array $settings = [],
        public bool $isActive = true,
        public ?\Throwable $failure = null,
    ) {}

    public function active(): bool
    {
        return $this->isActive;
    }

    public function setting(string $key): mixed
    {
        return $this->settings[$key] ?? null;
    }

    public function replaceVars(string $template, \WP_Post $post): string
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->resolved[] = $template;
        $sep = is_string($this->settings['titles.title_separator'] ?? null) ? $this->settings['titles.title_separator'] : '-';

        return trim(str_replace(
            ['%title%', '%sep%', '%sitename%', '%excerpt%'],
            [$post->post_title, $sep, 'BañosPortátiles.net', $post->post_excerpt !== '' ? $post->post_excerpt : 'Extracto automático del contenido.'],
            $template
        ));
    }
}
