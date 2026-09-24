<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Seo;

/**
 * The part of Rank Math that bp-headless reads (settings and %variables%). Abstracted for unit tests.
 */
interface RankMathApi
{
    /** Rank Math is active AND operational (its registration step was skipped or completed). */
    public function active(): bool;

    /** Setting by path, e.g. "titles.pt_page_title" or "general.headless_support". */
    public function setting(string $key): mixed;

    /** Resolves %title% %sep% %sitename%… for a post. */
    public function replaceVars(string $template, \WP_Post $post): string;
}
