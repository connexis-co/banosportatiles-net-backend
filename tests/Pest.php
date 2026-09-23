<?php

declare(strict_types=1);

use Brain\Monkey;

pest()->beforeEach(function (): void {
    Monkey\setUp();
})->afterEach(function (): void {
    Monkey\tearDown();
})->in('Unit');

/**
 * The seed-sample bundle shipped with the backend (also used by the smoke tests).
 *
 * @return array<string, mixed>
 */
function sampleBundle(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__).'/seed-sample/bundle.json'), true, 512, JSON_THROW_ON_ERROR);
}
