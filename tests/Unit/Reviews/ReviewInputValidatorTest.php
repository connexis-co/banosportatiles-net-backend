<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Reviews\ReviewInputValidator;

const TEST_VOTER = 'b94d27b9934d3e08a52e52d7da7dabfac484efe37a5380ee9088f7ace2efcde9';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function reviewInput(array $overrides = []): array
{
    return $overrides + [
        'uri' => '/blog/pozo-septico-guia/',
        'rating' => 4,
        'title' => 'Muy clara',
        'content' => 'La guía me ayudó a entender cada cuánto limpiar el pozo.',
        'name' => 'Ana Pérez',
        'email' => 'Ana@Example.com',
        'consent' => true,
        'voter' => strtoupper(TEST_VOTER),
        'ip' => '203.0.113.9',
        'ua' => 'Mozilla/5.0',
    ];
}

it('accepts a quick vote and normalizes it', function (): void {
    $result = (new ReviewInputValidator)->vote(['uri' => 'blog//pozo-septico-guia', 'rating' => '5', 'voter' => TEST_VOTER, 'ip' => '2001:db8::1', 'country' => 'co']);

    expect($result->isValid())->toBeFalse() // the uri must start with "/"
        ->and($result->errors)->toHaveKey('uri');

    $ok = (new ReviewInputValidator)->vote(['uri' => '/blog//pozo-septico-guia', 'rating' => '5', 'voter' => TEST_VOTER, 'ip' => '2001:db8::1', 'country' => 'co']);
    expect($ok->isValid())->toBeTrue()
        ->and($ok->uri)->toBe('/blog/pozo-septico-guia/')
        ->and($ok->rating)->toBe(5)
        ->and($ok->voter?->country)->toBe('CO')
        ->and($ok->voter?->ip)->toBe('2001:db8::1');
});

it('rejects invalid ratings, voters and IPs', function (mixed $rating, string $voter, string $ip, array $fields): void {
    $result = (new ReviewInputValidator)->vote(['uri' => '/x/', 'rating' => $rating, 'voter' => $voter, 'ip' => $ip]);

    expect($result->isValid())->toBeFalse()
        ->and(array_keys($result->errors))->toBe($fields);
})->with([
    'zero' => [0, TEST_VOTER, '203.0.113.9', ['rating']],
    'six' => [6, TEST_VOTER, '203.0.113.9', ['rating']],
    'decimal' => [4.5, TEST_VOTER, '203.0.113.9', ['rating']],
    'decimal string' => ['4.5', TEST_VOTER, '203.0.113.9', ['rating']],
    'short voter' => [3, 'abc', '203.0.113.9', ['voter']],
    'bad ip' => [3, TEST_VOTER, '999.1.1.1', ['ip']],
]);

it('accepts a review and lowercases the email', function (): void {
    $result = (new ReviewInputValidator)->review(reviewInput());

    expect($result->isValid())->toBeTrue()
        ->and($result->submission?->email)->toBe('ana@example.com')
        ->and($result->submission?->rating)->toBe(4)
        ->and($result->voter?->voter)->toBe(TEST_VOTER);
});

it('enforces the review rules with Spanish messages per field', function (array $overrides, string $field): void {
    $result = (new ReviewInputValidator)->review(reviewInput($overrides));

    expect($result->isValid())->toBeFalse()
        ->and($result->errors)->toHaveKey($field)
        ->and($result->submission)->toBeNull();
})->with([
    'content too short' => [['content' => 'Muy bien.'], 'content'],
    'content too long' => [['content' => str_repeat('a', 2001)], 'content'],
    'three urls' => [['content' => 'Mira https://a.co/x, www.b.com y c.info/y para más detalles.'], 'content'],
    'name too short' => [['name' => 'A'], 'name'],
    'name too long' => [['name' => str_repeat('n', 61)], 'name'],
    'name with url' => [['name' => 'Visita http://spam.example'], 'name'],
    'invalid email' => [['email' => 'ana@'], 'email'],
    'missing email' => [['email' => ''], 'email'],
    'consent string' => [['consent' => 'true'], 'consent'],
    'consent false' => [['consent' => false], 'consent'],
    'title too long' => [['title' => str_repeat('t', 121)], 'title'],
]);

it('allows up to two links in a review', function (): void {
    expect((new ReviewInputValidator)->review(reviewInput(['content' => 'Fuente: https://www.minambiente.gov.co/ y www.car.gov.co en la guía.']))->isValid())->toBeTrue()
        ->and(ReviewInputValidator::countUrls('https://a.co/x www.b.com c.info/y d.com'))->toBe(4)
        ->and(ReviewInputValidator::countUrls('Sin enlaces, solo 3.5 estrellas.'))->toBe(0);
});
