<?php

declare(strict_types=1);

namespace BanosPortatiles\SitioEnVenta\Settings;

final readonly class SanitizeResult
{
    /**
     * @param  array<string, mixed>  $settings  Clean settings, ready to store.
     * @param  array<string, string>  $errors  Rejected values (the previous value was kept).
     * @param  array<string, string>  $warnings  Accepted values that were adjusted.
     */
    public function __construct(public array $settings, public array $errors = [], public array $warnings = []) {}
}
