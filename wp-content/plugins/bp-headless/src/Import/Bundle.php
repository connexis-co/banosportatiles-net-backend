<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Import;

use BanosPortatiles\Headless\Support\Arr;

/**
 * Seed bundle (JSON) produced by the front from frontend/src/content/seed/:
 * {version: 1, site, ciudades[], categorias[], faqs[], redirects[], pages[], posts[], equipos[]}.
 * pages/posts/equipos items = seed frontmatter + uri (+ parentUri for pages) + contentHtml.
 */
final class Bundle
{
    public const VERSION = 1;

    public const LISTS = ['ciudades', 'categorias', 'faqs', 'redirects', 'pages', 'posts', 'equipos'];

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function __construct(private readonly array $data) {}

    public static function fromFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new \RuntimeException("No se puede leer el bundle: {$path}");
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('El bundle no es JSON válido: '.$e->getMessage(), 0, $e);
        }

        if (! is_array($data)) {
            throw new \RuntimeException('El bundle debe ser un objeto JSON.');
        }

        return new self($data);
    }

    /** @return array<array-key, mixed> */
    public function site(): array
    {
        return Arr::array($this->data, 'site');
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public function list(string $key): array
    {
        return Arr::rows($this->data, $key);
    }

    /**
     * Structural validation; the import aborts before writing anything when this is not empty.
     *
     * @return list<string>
     */
    public function errors(): array
    {
        $errors = [];
        if (Arr::int($this->data, 'version') !== self::VERSION) {
            $errors[] = 'version: se esperaba '.self::VERSION.'.';
        }
        foreach (self::LISTS as $key) {
            if (array_key_exists($key, $this->data) && ! is_array($this->data[$key])) {
                $errors[] = "{$key}: debe ser una lista.";
            }
        }

        $required = [
            'ciudades' => ['slug', 'name'],
            'categorias' => ['slug', 'name'],
            'faqs' => ['id', 'q', 'a'],
            'redirects' => ['from', 'to'],
            'pages' => ['uri', 'title', 'template'],
            'posts' => ['slug', 'title'],
            'equipos' => ['slug', 'title'],
        ];
        foreach ($required as $key => $fields) {
            foreach ($this->list($key) as $index => $item) {
                foreach ($fields as $field) {
                    if (Arr::string($item, $field) === '') {
                        $errors[] = sprintf('%s[%d]: falta «%s».', $key, $index, $field);
                    }
                }
            }
        }

        $uris = [];
        foreach ($this->list('pages') as $index => $page) {
            $uri = Arr::string($page, 'uri');
            if ($uri !== '' && (! str_starts_with($uri, '/') || ! str_ends_with($uri, '/'))) {
                $errors[] = sprintf('pages[%d]: la uri «%s» debe empezar y terminar en «/».', $index, $uri);
            }
            if (isset($uris[$uri])) {
                $errors[] = sprintf('pages[%d]: uri duplicada «%s».', $index, $uri);
            }
            $uris[$uri] = true;
        }

        return $errors;
    }
}
