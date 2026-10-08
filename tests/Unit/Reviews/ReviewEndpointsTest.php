<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Cache\ResponseCache;
use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Leads\RateLimiter;
use BanosPortatiles\Headless\Rest\Controllers\RatingsController;
use BanosPortatiles\Headless\Rest\Controllers\ReviewsController;
use BanosPortatiles\Headless\Reviews\RatingService;
use BanosPortatiles\Headless\Reviews\ReviewInputValidator;
use BanosPortatiles\Headless\Reviews\ReviewRecord;
use BanosPortatiles\Headless\Security\HmacSigner;
use BanosPortatiles\Headless\Security\SignedRequestGuard;
use BanosPortatiles\Headless\Tests\Fakes\FakeFieldReader;
use BanosPortatiles\Headless\Tests\Fakes\FakeNodeLocator;
use BanosPortatiles\Headless\Tests\Fakes\FakeReviewsGateway;
use Brain\Monkey\Functions;

const ENDPOINT_SECRET = 'endpoint-test-secret-0123456789';
const VOTER_A = '1111111111111111111111111111111111111111111111111111111111111111';
const VOTER_B = '2222222222222222222222222222222222222222222222222222222222222222';

beforeEach(function (): void {
    putenv('BP_LEADS_SECRET='.ENDPOINT_SECRET);
    $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
    $this->transients = [];
    Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
    Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
        $this->transients[$key] = $value;

        return true;
    });
    Functions\when('get_page_template_slug')->alias(fn (WP_Post $post): string => $post->ID === 30 ? 'home' : 'servicio');
    Functions\when('get_bloginfo')->justReturn('BañosPortátiles.net');

    $this->gateway = new FakeReviewsGateway;
    $this->nodes = new FakeNodeLocator([
        '/blog/pozo-septico-guia/' => new WP_Post(['ID' => 9, 'post_type' => 'post', 'post_name' => 'pozo-septico-guia']),
        '/alquiler-de-banos-portatiles/' => new WP_Post(['ID' => 11, 'post_type' => 'page', 'post_name' => 'alquiler-de-banos-portatiles']),
        '/' => new WP_Post(['ID' => 30, 'post_type' => 'page', 'post_name' => 'inicio']),
    ]);
    $this->makeRatings = fn (array $site = []): RatingService => new RatingService($this->gateway, new FakeFieldReader(['bp_site' => $site]));
    $this->ratings = ($this->makeRatings)();
    $guard = new SignedRequestGuard(new Config, new RateLimiter);
    $this->votes = new RatingsController(new ResponseCache, $this->ratings, $this->nodes, $guard, new ReviewInputValidator, new RateLimiter);
    $this->reviews = new ReviewsController(new ResponseCache, $this->ratings, $this->nodes, $guard, new ReviewInputValidator, new RateLimiter);
});

afterEach(function (): void {
    putenv('BP_LEADS_SECRET');
    unset($_SERVER['REMOTE_ADDR']);
});

/**
 * @param  array<string, mixed>  $payload
 */
function signedRequest(string $route, array $payload, ?string $signature = null, ?int $timestamp = null): WP_REST_Request
{
    $body = (string) json_encode($payload);
    $timestamp ??= time();
    $request = new WP_REST_Request('POST', '/bp/v1'.$route);
    $request->set_body($body);
    $request->set_header('X-BP-Timestamp', (string) $timestamp);
    $request->set_header('X-BP-Signature', $signature ?? (new HmacSigner(ENDPOINT_SECRET))->sign($body, $timestamp));

    return $request;
}

/** @return array<string, mixed> */
function vote(string $uri = '/blog/pozo-septico-guia/', int $rating = 5, string $voter = VOTER_A, string $ip = '203.0.113.9'): array
{
    return ['uri' => $uri, 'rating' => $rating, 'voter' => $voter, 'ip' => $ip, 'ua' => 'Mozilla/5.0 (test)', 'country' => 'CO'];
}

/** @return array<string, mixed> */
function opinion(string $uri = '/blog/pozo-septico-guia/', string $voter = VOTER_A): array
{
    return [
        'uri' => $uri, 'rating' => 4, 'title' => 'Clara', 'content' => 'Texto de prueba con más de veinte caracteres.',
        'name' => 'Persona de prueba', 'email' => 'prueba@example.com', 'consent' => true, 'voter' => $voter, 'ip' => '203.0.113.9',
    ];
}

it('authorizes only fresh, correctly signed requests (401 / 503 / replay)', function (): void {
    $valid = signedRequest('/ratings', vote());

    expect($this->votes->authorize($valid))->toBeTrue()
        ->and($this->votes->authorize($valid)->get_error_code())->toBe('bp_replayed_request')
        ->and($this->votes->authorize(signedRequest('/ratings', vote(), 'sha256=deadbeef'))->get_error_data())->toBe(['status' => 401])
        ->and($this->reviews->authorize(signedRequest('/reviews', opinion(), null, time() - 600))->get_error_code())->toBe('bp_invalid_signature');

    putenv('BP_LEADS_SECRET');
    expect($this->votes->authorize(signedRequest('/ratings', vote()))->get_error_data())->toBe(['status' => 503]);
});

it('throttles repeated failed signatures per network IP (429)', function (): void {
    for ($i = 0; $i < SignedRequestGuard::MAX_FAILED_AUTH_PER_WINDOW; $i++) {
        $this->votes->authorize(signedRequest('/ratings', vote(), 'sha256=bad'.$i));
    }

    expect($this->votes->authorize(signedRequest('/ratings', vote()))->get_error_data())->toBe(['status' => 429]);
});

it('creates a quick vote once per voter and node (201, then 200 duplicate)', function (): void {
    $created = $this->votes->vote(signedRequest('/ratings', vote()));
    $again = $this->votes->vote(signedRequest('/ratings', vote(rating: 1)));

    expect($created->get_status())->toBe(201)
        ->and($created->get_data())->toMatchArray(['ok' => true, 'created' => true])
        ->and($created->get_data()['summary'])->toMatchArray(['uri' => '/blog/pozo-septico-guia/', 'id' => 9, 'count' => 1, 'average' => 5.0])
        ->and($again->get_status())->toBe(200)
        ->and($again->get_data())->toMatchArray(['ok' => true, 'created' => false, 'duplicate' => true])
        ->and($again->get_data()['summary']['count'])->toBe(1)
        ->and($this->gateway->calls)->toBe(['createVote'])
        ->and($created->get_headers()['Cache-Control'])->toBe('no-store');
});

it('answers 422 with errors per field, 404 for unknown pages and 403 where ratings are off', function (): void {
    $invalid = $this->votes->vote(signedRequest('/ratings', ['uri' => '/x/', 'rating' => 9, 'voter' => 'x', 'ip' => 'nope']));
    $missing = $this->votes->vote(signedRequest('/ratings', vote('/no-existe/')));
    $home = $this->votes->vote(signedRequest('/ratings', vote('/')));

    expect($invalid->get_status())->toBe(422)
        ->and(array_keys($invalid->get_data()['errors']))->toBe(['rating', 'voter', 'ip'])
        ->and($missing->get_status())->toBe(404)
        ->and($home->get_status())->toBe(403)
        ->and($home->get_data()['code'])->toBe('ratings_disabled')
        ->and($this->gateway->records)->toBe([]);
});

it('limits votes per visitor IP (429 + Retry-After)', function (): void {
    $last = null;
    for ($i = 0; $i <= RatingsController::MAX_VOTES_PER_WINDOW; $i++) {
        $last = $this->votes->vote(signedRequest('/ratings', vote(voter: hash('sha256', 'votante-'.$i))));
    }

    expect($last?->get_status())->toBe(429)
        ->and($last?->get_headers())->toHaveKey('Retry-After')
        ->and(count($this->gateway->records))->toBe(RatingsController::MAX_VOTES_PER_WINDOW);
});

it('answers 503 when Site Reviews is not active', function (): void {
    $this->gateway->isAvailable = false;

    expect($this->votes->vote(signedRequest('/ratings', vote()))->get_status())->toBe(503)
        ->and($this->reviews->create(signedRequest('/reviews', opinion()))->get_status())->toBe(503);
});

it('stores a review pending moderation and rejects a second one from the same voter (409)', function (): void {
    $first = $this->reviews->create(signedRequest('/reviews', opinion()));
    $second = $this->reviews->create(signedRequest('/reviews', opinion()));

    expect($first->get_status())->toBe(201)
        ->and($first->get_data())->toMatchArray(['ok' => true, 'status' => 'pending'])
        ->and($second->get_status())->toBe(409)
        ->and($second->get_data()['code'])->toBe('duplicate');
});

it('turns the voter\'s quick vote into the review instead of rating twice', function (): void {
    $this->votes->vote(signedRequest('/ratings', vote(rating: 2)));
    $voteId = array_key_first($this->gateway->records);

    $review = $this->reviews->create(signedRequest('/reviews', opinion()));

    expect($review->get_status())->toBe(201)
        ->and($review->get_data())->toBe(['ok' => true, 'id' => $voteId, 'status' => 'pending', 'updated' => true])
        ->and($this->gateway->calls)->toBe(['createVote', 'upgradeVote'])
        ->and($this->gateway->records)->toHaveCount(1)
        ->and($this->gateway->records[$voteId]->approved)->toBeFalse()
        ->and($this->gateway->records[$voteId]->rating)->toBe(4);
});

it('respects reviews disabled per type, auto-approval and the blacklist', function (): void {
    $disabled = new ReviewsController(new ResponseCache, ($this->makeRatings)(['ratings' => ['types' => ['servicios' => ['reviews' => false]]]]), $this->nodes, new SignedRequestGuard(new Config, new RateLimiter), new ReviewInputValidator, new RateLimiter);
    $service = $disabled->create(signedRequest('/reviews', opinion('/alquiler-de-banos-portatiles/')));
    expect($service->get_status())->toBe(403)
        ->and($service->get_data()['code'])->toBe('reviews_disabled');

    $auto = new ReviewsController(new ResponseCache, ($this->makeRatings)(['ratings' => ['auto_approve_reviews' => true]]), $this->nodes, new SignedRequestGuard(new Config, new RateLimiter), new ReviewInputValidator, new RateLimiter);
    expect($auto->create(signedRequest('/reviews', opinion(voter: VOTER_B)))->get_data()['status'])->toBe('approved');

    $this->gateway->screenResult = 'reject';
    expect($this->reviews->create(signedRequest('/reviews', opinion()))->get_status())->toBe(403);

    $this->gateway->screenResult = 'unapprove';
    $held = new ReviewsController(new ResponseCache, ($this->makeRatings)(['ratings' => ['auto_approve_reviews' => true]]), $this->nodes, new SignedRequestGuard(new Config, new RateLimiter), new ReviewInputValidator, new RateLimiter);
    expect($held->create(signedRequest('/reviews', opinion(voter: hash('sha256', 'otro'))))->get_data()['status'])->toBe('pending');
});

it('embeds only approved reviews with text in the node, newest first', function (): void {
    $tz = new DateTimeZone('America/Bogota');
    $this->gateway->add(new ReviewRecord(1, 5, 'Ana', '', '', true, new DateTimeImmutable('2026-09-01', $tz), voter: VOTER_A, postIds: [9]));
    $this->gateway->add(new ReviewRecord(2, 4, 'Luis Gómez', 'Bien', 'Opinión aprobada con texto suficiente.', true, new DateTimeImmutable('2026-09-02', $tz), voter: VOTER_B, postIds: [9]));
    $this->gateway->add(new ReviewRecord(3, 1, 'Spam', '', 'Opinión pendiente que no se publica.', false, new DateTimeImmutable('2026-09-03', $tz), postIds: [9]));

    $node = $this->ratings->forNode($this->nodes->find('/blog/pozo-septico-guia/'));

    expect($node['rating'])->toMatchArray(['count' => 2, 'average' => 4.5, 'reviewCount' => 1])
        ->and($node['reviews'])->toHaveCount(1)
        ->and($node['reviews'][0])->toMatchArray(['id' => 2, 'author' => 'Luis Gómez', 'initials' => 'LG'])
        ->and($this->ratings->forNode($this->nodes->find('/')))->toBe([])
        ->and($this->ratings->forNode($this->nodes->find('/alquiler-de-banos-portatiles/')))->toHaveKey('reviews');
});
