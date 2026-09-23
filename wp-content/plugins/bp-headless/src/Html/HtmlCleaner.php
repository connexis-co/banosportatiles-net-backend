<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Html;

/**
 * Pure-PHP post-processing of content HTML for the headless front:
 * - drops unsafe elements/attributes (scripts, event handlers, javascript: URLs, non-allowlisted iframes);
 * - rewrites internal links (CMS host or public host) to relative URIs with a trailing slash;
 * - keeps CMS assets (/wp-content/…) absolute on the CMS origin;
 * - adds unique, slugified ids to h2/h3 (table of contents anchors);
 * - removes empty paragraphs and adds lazy loading to images.
 */
final class HtmlCleaner
{
    private const DROP_ELEMENTS = [
        'script', 'style', 'noscript', 'object', 'embed', 'applet', 'form', 'input', 'button', 'select',
        'textarea', 'option', 'link', 'meta', 'base', 'frame', 'frameset', 'template', 'svg', 'math',
    ];

    private const IFRAME_HOSTS = ['www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com', 'youtube-nocookie.com', 'player.vimeo.com'];

    private const URL_ATTRIBUTES = ['href', 'src', 'poster', 'cite', 'action', 'formaction', 'data', 'xlink:href'];

    private const ASSET_PREFIXES = ['/wp-content/', '/wp-includes/'];

    private const CMS_ONLY_PREFIXES = ['/wp-admin', '/wp-json', '/wp-login.php'];

    /** @var list<string> host[:port] of the public site */
    private array $frontHosts;

    private string $cmsHost;

    private string $cmsOrigin;

    /** @var (\Closure(string): ?string)|null */
    private ?\Closure $pathMapper;

    /**
     * @param  list<string>  $frontHosts  host[:port] of the public site (links to them become relative).
     * @param  string  $cmsOrigin  Origin of this CMS, e.g. "https://api.banosportatiles.net".
     * @param  (callable(string): ?string)|null  $pathMapper  Maps a CMS path ("/mi-post/") to its public URI ("/blog/mi-post/").
     */
    public function __construct(array $frontHosts, string $cmsOrigin, ?callable $pathMapper = null)
    {
        $this->frontHosts = array_values(array_unique(array_map('strtolower', $frontHosts)));
        $this->cmsOrigin = rtrim($cmsOrigin, '/');
        $this->cmsHost = self::authority($this->cmsOrigin);
        $this->pathMapper = $pathMapper !== null ? \Closure::fromCallable($pathMapper) : null;
    }

    /** "https://Example.com:8080/x" → "example.com:8080" (port only when explicit). */
    public static function authority(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $port = parse_url($url, PHP_URL_PORT);

        return $host === '' ? '' : $host.(is_int($port) ? ':'.$port : '');
    }

    public function clean(string $html): string
    {
        $html = (string) preg_replace('/<!--.*?-->/s', '', $html);
        if (trim($html) === '') {
            return '';
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div data-bp-root="1">'
            .$html.'</div></body></html>',
            LIBXML_NONET | LIBXML_COMPACT
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $nodes = (new \DOMXPath($document))->query('//div[@data-bp-root="1"]');
        $root = $nodes !== false ? $nodes->item(0) : null;
        if (! $root instanceof \DOMElement) {
            return '';
        }

        $this->dropUnsafeElements($root);
        $this->processElements($root);
        $this->addHeadingIds($root);
        $this->removeEmptyParagraphs($root);

        $output = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $output .= (string) $document->saveHTML($child);
        }

        return trim($output);
    }

    public function rewriteHref(string $href): string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#') || preg_match('#^(mailto|tel|sms|whatsapp):#i', $href) === 1) {
            return $href;
        }

        $parts = parse_url($href);
        if ($parts === false) {
            return $href;
        }

        $host = strtolower($parts['host'] ?? '');
        $hostWithPort = $host.(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = $parts['path'] ?? '';
        $suffix = (isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');

        if ($host === '') {
            if (isset($parts['scheme']) || ! str_starts_with($path, '/')) {
                return $href;
            }

            return $this->isAsset($path) ? $this->cmsOrigin.$path.$suffix : self::withTrailingSlash($path).$suffix;
        }

        if ($this->cmsHost !== '' && $hostWithPort === $this->cmsHost) {
            if ($this->isAsset($path) || $this->isCmsOnly($path)) {
                return $href;
            }
            $path = $path === '' ? '/' : $path;
            $mapped = $this->pathMapper !== null ? ($this->pathMapper)($path) : null;

            return ($mapped ?? self::withTrailingSlash($path)).$suffix;
        }

        if (in_array($hostWithPort, $this->frontHosts, true)) {
            return self::withTrailingSlash($path === '' ? '/' : $path).$suffix;
        }

        return $href;
    }

    public static function withTrailingSlash(string $path): string
    {
        if ($path === '' || str_ends_with($path, '/')) {
            return $path === '' ? '/' : $path;
        }
        $last = substr($path, (int) strrpos($path, '/') + 1);

        return str_contains($last, '.') ? $path : $path.'/';
    }

    private function dropUnsafeElements(\DOMElement $root): void
    {
        foreach (self::DROP_ELEMENTS as $tag) {
            foreach (iterator_to_array($root->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        foreach (iterator_to_array($root->getElementsByTagName('iframe')) as $iframe) {
            $host = strtolower((string) parse_url($iframe->getAttribute('src'), PHP_URL_HOST));
            $scheme = strtolower((string) parse_url($iframe->getAttribute('src'), PHP_URL_SCHEME));
            if ($scheme !== 'https' || ! in_array($host, self::IFRAME_HOSTS, true)) {
                $iframe->parentNode?->removeChild($iframe);

                continue;
            }
            $iframe->setAttribute('loading', 'lazy');
        }
    }

    private function processElements(\DOMElement $root): void
    {
        foreach (iterator_to_array($root->getElementsByTagName('*')) as $element) {
            if (! $element instanceof \DOMElement) {
                continue;
            }

            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->nodeName);
                $unsafe = str_starts_with($name, 'on') || in_array($name, ['style', 'srcdoc'], true)
                    || (in_array($name, self::URL_ATTRIBUTES, true) && self::isDangerousUrl((string) $attribute->nodeValue));
                if ($unsafe) {
                    $element->removeAttribute($attribute->nodeName);
                }
            }

            $tag = strtolower($element->tagName);
            if ($tag === 'a' && $element->hasAttribute('href')) {
                $href = $this->rewriteHref($element->getAttribute('href'));
                $element->setAttribute('href', $href);
                if (strtolower($element->getAttribute('target')) === '_blank') {
                    $rel = array_filter(explode(' ', strtolower($element->getAttribute('rel'))));
                    $element->setAttribute('rel', implode(' ', array_unique([...$rel, 'noopener', 'noreferrer'])));
                }
            }

            if (in_array($tag, ['img', 'source', 'video', 'audio', 'track'], true)) {
                foreach (['src', 'poster'] as $attribute) {
                    if ($element->hasAttribute($attribute)) {
                        $element->setAttribute($attribute, $this->absolutizeAsset($element->getAttribute($attribute)));
                    }
                }
                if ($element->hasAttribute('srcset')) {
                    $element->setAttribute('srcset', $this->absolutizeSrcset($element->getAttribute('srcset')));
                }
            }

            if ($tag === 'img') {
                if (! $element->hasAttribute('loading')) {
                    $element->setAttribute('loading', 'lazy');
                }
                if (! $element->hasAttribute('decoding')) {
                    $element->setAttribute('decoding', 'async');
                }
            }
        }
    }

    private function addHeadingIds(\DOMElement $root): void
    {
        $used = [];
        foreach (iterator_to_array($root->getElementsByTagName('*')) as $element) {
            if ($element instanceof \DOMElement && $element->getAttribute('id') !== '') {
                $used[$element->getAttribute('id')] = true;
            }
        }

        $xpath = new \DOMXPath($root->ownerDocument ?? new \DOMDocument);
        $headings = $xpath->query('.//h2|.//h3', $root);
        if ($headings === false) {
            return;
        }

        foreach (iterator_to_array($headings) as $heading) {
            if (! $heading instanceof \DOMElement || $heading->getAttribute('id') !== '') {
                continue;
            }
            $base = Slugger::slugify($heading->textContent);
            $base = $base !== '' ? $base : 'seccion';
            $id = $base;
            for ($i = 2; isset($used[$id]); $i++) {
                $id = $base.'-'.$i;
            }
            $used[$id] = true;
            $heading->setAttribute('id', $id);
        }
    }

    private function removeEmptyParagraphs(\DOMElement $root): void
    {
        foreach (iterator_to_array($root->getElementsByTagName('p')) as $paragraph) {
            $text = trim(str_replace("\u{00A0}", ' ', $paragraph->textContent));
            $hasMedia = false;
            foreach ($paragraph->getElementsByTagName('*') as $child) {
                if ($child instanceof \DOMElement && strtolower($child->tagName) !== 'br') {
                    $hasMedia = true;
                    break;
                }
            }
            if ($text === '' && ! $hasMedia) {
                $paragraph->parentNode?->removeChild($paragraph);
            }
        }
    }

    private function absolutizeAsset(string $url): string
    {
        $url = trim($url);
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//') && $this->isAsset((string) parse_url($url, PHP_URL_PATH))) {
            return $this->cmsOrigin.$url;
        }

        return $url;
    }

    private function absolutizeSrcset(string $srcset): string
    {
        $candidates = [];
        foreach (explode(',', $srcset) as $candidate) {
            $pieces = preg_split('/\s+/', trim($candidate), 2) ?: [];
            if (($pieces[0] ?? '') === '') {
                continue;
            }
            $candidates[] = trim($this->absolutizeAsset($pieces[0]).' '.($pieces[1] ?? ''));
        }

        return implode(', ', $candidates);
    }

    private function isAsset(string $path): bool
    {
        foreach (self::ASSET_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isCmsOnly(string $path): bool
    {
        foreach (self::CMS_ONLY_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function isDangerousUrl(string $url): bool
    {
        $normalized = strtolower((string) preg_replace('/[\x00-\x20]+/', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return str_starts_with($normalized, 'javascript:') || str_starts_with($normalized, 'vbscript:') || str_starts_with($normalized, 'data:');
    }
}
