<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Seo;

/**
 * What the SEO sources need to publish URLs: the public site and every host that means "this site"
 * (the CMS, admin.banosportatiles.net, and the public domain with and without www).
 */
final readonly class SeoContext
{
    /**
     * @param  list<string>  $ownHosts
     */
    public function __construct(
        public string $frontendUrl,
        public array $ownHosts,
    ) {}

    public function canonical(string $raw, string $ownUri): ?string
    {
        return CanonicalUrl::resolve($raw, $ownUri, $this->frontendUrl, $this->ownHosts);
    }
}
