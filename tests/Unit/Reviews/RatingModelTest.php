<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Reviews\Blacklist;
use BanosPortatiles\Headless\Reviews\RatingFlags;
use BanosPortatiles\Headless\Reviews\RatingPolicy;
use BanosPortatiles\Headless\Reviews\RatingSettings;
use BanosPortatiles\Headless\Reviews\RatingSummary;
use BanosPortatiles\Headless\Reviews\ReviewNormalizer;
use BanosPortatiles\Headless\Reviews\ReviewRecord;

/** Test-only record (never seeded): rating, text and local date. */
function record(int $id, int $rating, string $content = '', string $date = '2026-09-20 10:00', bool $approved = true, string $author = 'Ana María Pérez'): ReviewRecord
{
    return new ReviewRecord($id, $rating, $author, '', $content, $approved, new DateTimeImmutable($date, new DateTimeZone('America/Bogota')));
}

it('summarizes approved ratings: count, 1-decimal average, distribution, text reviews and last update', function (): void {
    $summary = RatingSummary::build(new RatingFlags(true, true), [
        record(1, 5, 'Excelente guía, muy completa.', '2026-09-21 09:00'),
        record(2, 4),
        record(3, 4, '', '2026-09-22 18:30'),
        record(4, 2, 'Pendiente de moderación', '2026-09-23 08:00', approved: false),
        record(5, 0), // no valid rating: ignored
    ]);

    expect($summary)->toBe([
        'stars' => true,
        'reviews' => true,
        'count' => 3,
        'average' => 4.3,
        'best' => 5,
        'worst' => 1,
        'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 2, 5 => 1],
        'reviewCount' => 1,
        'updated' => '2026-09-22T18:30:00-05:00',
    ])->and(json_encode($summary['distribution']))->toBe('{"1":0,"2":0,"3":0,"4":2,"5":1}');
});

it('starts at zero without an update date (honest empty state)', function (): void {
    $summary = RatingSummary::build(new RatingFlags(true, false), []);

    expect($summary['count'])->toBe(0)
        ->and($summary['average'])->toBe(0.0)
        ->and($summary)->not->toHaveKey('updated')
        ->and(RatingSummary::label($summary))->toBe('Sin votos')
        ->and(RatingSummary::label(['count' => 23, 'average' => 4.66]))->toBe('★ 4,7 · 23');
});

it('normalizes a review with initials, plain text and the owner response, never the email', function (): void {
    $record = new ReviewRecord(
        id: 7,
        rating: 5,
        author: ' Ana  María Pérez ',
        title: 'Muy <b>útil</b>',
        content: "Primer párrafo.\r\n\r\n\r\nSegundo <script>x</script>párrafo.",
        approved: true,
        date: new DateTimeImmutable('2026-09-20 10:00', new DateTimeZone('America/Bogota')),
        response: '<p>Gracias.</p><p>Saludos.</p>',
        responseDate: new DateTimeImmutable('2026-09-21 08:00', new DateTimeZone('America/Bogota')),
        voter: str_repeat('a', 64),
        ip: '203.0.113.9',
    );

    $review = ReviewNormalizer::normalize($record, 'BañosPortátiles.net');

    expect($review)->toBe([
        'id' => 7,
        'author' => 'Ana María Pérez',
        'initials' => 'AP',
        'rating' => 5,
        'title' => 'Muy útil',
        'content' => "Primer párrafo.\n\nSegundo párrafo.",
        'date' => '2026-09-20T10:00:00-05:00',
        'response' => ['content' => "Gracias.\n\nSaludos.", 'date' => '2026-09-21T08:00:00-05:00', 'author' => 'BañosPortátiles.net'],
    ])->and(json_encode($review))->not->toContain('203.0.113.9')
        ->and(json_encode($review))->not->toContain('@');
});

it('derives initials from the first and last word', function (string $name, string $initials): void {
    expect(ReviewNormalizer::initials($name))->toBe($initials);
})->with([
    ['Ana', 'A'],
    ['josé pérez', 'JP'],
    ['María del Carmen López', 'ML'],
    ['Ñandú-Óscar', 'ÑÓ'],
    ['', 'A'],
    ['!!!', 'A'],
]);

it('omits optional keys of a review without title or response and names anonymous authors', function (): void {
    $review = ReviewNormalizer::normalize(record(8, 3, 'Texto suficiente para una opinión.', author: ''), 'Marca');

    expect(array_keys($review))->toBe(['id', 'author', 'initials', 'rating', 'content', 'date'])
        ->and($review['author'])->toBe('Anónimo')
        ->and($review['initials'])->toBe('A');
});

it('maps templates to rating types and keeps self-serving pages off by default', function (): void {
    $settings = RatingSettings::defaults();

    expect(RatingPolicy::typeFor('hub-servicio'))->toBe('servicios')
        ->and(RatingPolicy::typeFor('servicio'))->toBe('servicios')
        ->and(RatingPolicy::typeFor('ciudad'))->toBe('ciudades')
        ->and(RatingPolicy::typeFor('equipo'))->toBe('equipos')
        ->and(RatingPolicy::typeFor('post'))->toBe('blog')
        ->and(RatingPolicy::typeFor('landing'))->toBe('otras')
        ->and(RatingPolicy::typeFor('home'))->toBeNull()
        ->and(RatingPolicy::resolve($settings, 'post', null))->toEqual(new RatingFlags(true, true))
        ->and(RatingPolicy::resolve($settings, 'servicio', null))->toEqual(new RatingFlags(true, false))
        ->and(RatingPolicy::resolve($settings, 'landing', null))->toEqual(RatingFlags::off());

    foreach (RatingPolicy::EXCLUDED_TEMPLATES as $template) {
        expect(RatingPolicy::resolve($settings, $template, null)->any())->toBeFalse();
    }
});

it('applies the per-post override and the global switch', function (): void {
    $settings = RatingSettings::defaults();
    $off = RatingSettings::fromOption(['enabled' => false]);

    expect(RatingPolicy::resolve($settings, 'servicio', ['stars' => 'no', 'reviews' => 'yes']))->toEqual(new RatingFlags(false, true))
        ->and(RatingPolicy::resolve($settings, 'home', ['stars' => 'yes', 'reviews' => 'inherit']))->toEqual(new RatingFlags(true, false))
        ->and(RatingPolicy::resolve($off, 'post', ['stars' => 'yes', 'reviews' => 'yes']))->toEqual(RatingFlags::off());
});

it('reads the site settings with defaults and exposes the /site shape', function (): void {
    $settings = RatingSettings::fromOption([
        'enabled' => true,
        'types' => ['equipos' => ['stars' => true, 'reviews' => true], 'blog' => ['stars' => false, 'reviews' => '0']],
        'min_count_for_schema' => '3',
        'auto_approve_reviews' => false,
        'texts' => ['stars_title' => 'Califica este servicio', 'consent' => ''],
    ]);

    $site = $settings->toSite(true);

    expect($site['enabled'])->toBeTrue()
        ->and($site['types']['equipos'])->toBe(['stars' => true, 'reviews' => true])
        ->and($site['types']['blog'])->toBe(['stars' => false, 'reviews' => false])
        ->and($site['types']['servicios'])->toBe(['stars' => true, 'reviews' => false])
        ->and($site['minCountForSchema'])->toBe(3)
        ->and($site['autoApproveReviews'])->toBeFalse()
        ->and(array_keys($site['texts']))->toBe(array_keys(RatingSettings::TEXT_FIELDS))
        ->and($site['texts']['starsTitle'])->toBe('Califica este servicio')
        ->and($site['texts']['consent'])->toBe(RatingSettings::DEFAULT_TEXTS['consent'])
        ->and($settings->toSite(false)['enabled'])->toBeFalse()
        ->and(RatingSettings::fromOption(['min_count_for_schema' => 0])->minCountForSchema)->toBe(1)
        ->and(RatingSettings::fromOption(['minCountForSchema' => 2, 'autoApproveReviews' => true]))->toMatchObject(['minCountForSchema' => 2, 'autoApproveReviews' => true]);
});

it('matches the blacklist like Site Reviews (case-insensitive substrings, one per line)', function (): void {
    $entries = "spam.example\n\n  198.51.100.7 \n".str_repeat('x', 300);

    expect(Blacklist::matches($entries, "Hola\nVisita SPAM.example ya"))->toBeTrue()
        ->and(Blacklist::matches($entries, '198.51.100.7'))->toBeTrue()
        ->and(Blacklist::matches($entries, 'Opinión normal 203.0.113.5'))->toBeFalse()
        ->and(Blacklist::matches('', 'algo'))->toBeFalse();
});

it('turns review and response HTML into plain text with line breaks', function (string $html, string $text): void {
    expect(ReviewNormalizer::plainText($html))->toBe($text);
})->with([
    'br' => ['Gracias por comentar.<br>Saludos.', "Gracias por comentar.\nSaludos."],
    'br variants' => ['Uno<br/>Dos<BR />Tres', "Uno\nDos\nTres"],
    'paragraphs' => ['<p>Gracias por comentar.</p><p>Saludos.</p>', "Gracias por comentar.\n\nSaludos."],
    'entities' => ['Baños &amp; lavamanos &lt;3 &quot;VIP&quot;', 'Baños & lavamanos <3 "VIP"'],
    'windows newlines' => ["Línea 1\r\nLínea 2\r\n\r\n\r\n\r\nLínea 3", "Línea 1\nLínea 2\n\nLínea 3"],
    'other html' => ['<strong>Muy</strong> <a href="https://x.co">bien</a><script>alert(1)</script>', 'Muy bien'],
]);

it('keeps paragraphs and line breaks in the owner response stored by Site Reviews', function (): void {
    $allowed = BanosPortatiles\Headless\Reviews\ReviewsWriteGuard::allowParagraphs(['a' => ['href' => true], 'strong' => []]);

    expect($allowed)->toHaveKeys(['a', 'strong', 'p', 'br'])
        ->and(BanosPortatiles\Headless\Reviews\ReviewsWriteGuard::allowParagraphs(null))->toBe(['p' => [], 'br' => []]);

    $review = ReviewNormalizer::normalize(new ReviewRecord(3, 4, 'Ana', '', "Primera línea\nSegunda línea", true, new DateTimeImmutable('2026-09-20'), response: '<p>Gracias por comentar.</p><p>Saludos.</p>'), 'Marca');

    expect($review['content'])->toBe("Primera línea\nSegunda línea")
        ->and($review['response']['content'])->toBe("Gracias por comentar.\n\nSaludos.");
});
