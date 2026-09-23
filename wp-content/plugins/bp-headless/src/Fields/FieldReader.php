<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Fields;

/**
 * Reads formatted SCF values. Abstracted so normalizers can be unit-tested without SCF.
 */
interface FieldReader
{
    /**
     * @param  int|string  $objectId  Post ID, "term_{id}" or the options id ("bp_site").
     */
    public function get(string $name, int|string $objectId): mixed;
}
