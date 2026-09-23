<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Fields;

final class AcfFieldReader implements FieldReader
{
    public function get(string $name, int|string $objectId): mixed
    {
        if (! function_exists('get_field')) {
            return null;
        }

        return get_field($name, $objectId);
    }
}
