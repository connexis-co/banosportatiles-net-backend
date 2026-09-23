<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Tests\Fakes;

use BanosPortatiles\Headless\Fields\FieldReader;

/**
 * Array-backed SCF reader: [objectId => [fieldName => formattedValue]].
 */
final class FakeFieldReader implements FieldReader
{
    /**
     * @param  array<int|string, array<string, mixed>>  $values
     */
    public function __construct(private readonly array $values = []) {}

    public function get(string $name, int|string $objectId): mixed
    {
        return $this->values[$objectId][$name] ?? null;
    }
}
