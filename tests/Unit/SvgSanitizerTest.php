<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Svg\SvgSanitizer;

const LOGO = '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">'
    .'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 240 48" width="240" height="48">'
    .'<title>BañosPortátiles.net</title><defs><linearGradient id="g"><stop offset="0" stop-color="#0a2a22"/></linearGradient></defs>'
    .'<style>.a{fill:url(#g)} .b{fill:url(https://evil.example/x.svg#p)}</style>'
    .'<g class="a" onclick="alert(1)"><path d="M0 0h48v48H0z" fill="url(#g)" onload="alert(2)"/></g>'
    .'<use xlink:href="#g"/><use href="https://evil.example/sprite.svg#icon"/>'
    .'<script>alert(3)</script><foreignObject><div>html</div></foreignObject>'
    .'<a xlink:href="javascript:alert(4)"><text>clic</text></a>'
    .'<image href="data:image/png;base64,iVBORw0KGgo=" width="1" height="1"/><image href="data:image/svg+xml;base64,PHN2Zz4=" />'
    .'<rect width="10" height="10" style="background:url(http://evil.example/a.png)" fill="#fff"/><!-- comentario -->'
    .'<animate attributeName="href" to="javascript:alert(5)"/></svg>';

it('keeps the drawing and removes scripts, handlers, foreignObject, links and remote references', function (): void {
    $clean = (new SvgSanitizer)->sanitize(LOGO);

    expect($clean)->toBeString()
        ->toStartWith('<svg')
        ->toContain('viewBox="0 0 240 48"')
        ->toContain('<path d="M0 0h48v48H0z" fill="url(#g)"/>')
        ->toContain('<title>BañosPortátiles.net</title>')
        ->toContain('<use xlink:href="#g"/>')
        ->toContain('data:image/png;base64')
        ->not->toContain('<script')
        ->not->toContain('alert')
        ->not->toContain('onclick')
        ->not->toContain('onload')
        ->not->toContain('foreignObject')
        ->not->toContain('javascript:')
        ->not->toContain('evil.example')
        ->not->toContain('image/svg+xml')
        ->not->toContain('<animate')
        ->not->toContain('<!--')
        ->not->toContain('DOCTYPE');
});

it('rejects entities (XXE), non-SVG documents, broken XML and files over 100 KB', function (string $svg): void {
    expect((new SvgSanitizer)->sanitize($svg))->toBeNull();
})->with([
    'entity' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>'],
    'html root' => ['<html><body><svg></svg></body></html>'],
    'broken' => ['<svg xmlns="http://www.w3.org/2000/svg"><g></svg>'],
    'empty' => [''],
    'too big' => ['<svg xmlns="http://www.w3.org/2000/svg">'.str_repeat('<g/>', 30000).'</svg>'],
]);

it('reads the intrinsic size from width/height or the viewBox', function (): void {
    expect(SvgSanitizer::dimensions('<svg width="240px" height="48" viewBox="0 0 10 10"></svg>'))->toBe(['width' => 240, 'height' => 48])
        ->and(SvgSanitizer::dimensions('<svg viewBox="0 0 512.4 128"></svg>'))->toBe(['width' => 512, 'height' => 128])
        ->and(SvgSanitizer::dimensions('<svg width="100%" height="100%" viewBox="0,0,300,60"></svg>'))->toBe(['width' => 300, 'height' => 60])
        ->and(SvgSanitizer::dimensions('<svg></svg>'))->toBeNull();
});
