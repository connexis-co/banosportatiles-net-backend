<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Html\Slugger;

it('builds ASCII anchors from Spanish headings', function (string $text, string $slug): void {
    expect(Slugger::slugify($text))->toBe($slug);
})->with([
    ['¿Qué es un pozo séptico?', 'que-es-un-pozo-septico'],
    ['Baños portátiles en Medellín', 'banos-portatiles-en-medellin'],
    ['Pingüino & <em>Ñandú</em>', 'pinguino-nandu'],
    ['  Paso 1: cotiza  ', 'paso-1-cotiza'],
    ['¡¿?!', ''],
]);
