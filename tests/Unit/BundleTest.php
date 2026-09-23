<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Import\Bundle;

it('accepts the sample bundle', function (): void {
    $bundle = new Bundle(sampleBundle());

    expect($bundle->errors())->toBe([])
        ->and($bundle->list('pages'))->toHaveCount(4)
        ->and($bundle->list('faqs'))->toHaveCount(3)
        ->and($bundle->list('redirects'))->toHaveCount(2);
});

it('reports structural problems before anything is written', function (): void {
    $errors = (new Bundle([
        'version' => 2,
        'faqs' => 'no-es-lista',
        'pages' => [
            ['uri' => '/a/', 'title' => 'A', 'template' => 'landing'],
            ['uri' => '/a/', 'title' => 'A bis', 'template' => 'landing'],
            ['uri' => 'sin-slash', 'title' => 'B', 'template' => ''],
        ],
        'posts' => [['title' => 'Sin slug']],
    ]))->errors();

    expect($errors)->toContain('version: se esperaba 1.')
        ->toContain('faqs: debe ser una lista.')
        ->toContain('pages[1]: uri duplicada «/a/».')
        ->toContain('pages[2]: falta «template».')
        ->toContain('pages[2]: la uri «sin-slash» debe empezar y terminar en «/».')
        ->toContain('posts[0]: falta «slug».');
});

it('fails clearly on unreadable or invalid files', function (): void {
    expect(fn () => Bundle::fromFile('/no/existe.json'))->toThrow(RuntimeException::class, 'No se puede leer');

    $file = tempnam(sys_get_temp_dir(), 'bundle');
    file_put_contents((string) $file, '{nope');
    expect(fn () => Bundle::fromFile((string) $file))->toThrow(RuntimeException::class, 'JSON');
    unlink((string) $file);
});
