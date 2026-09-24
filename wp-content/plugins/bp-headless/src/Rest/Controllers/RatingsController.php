<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Rest\Controllers;

use BanosPortatiles\Headless\Cache\ResponseCache;
use BanosPortatiles\Headless\Leads\RateLimiter;
use BanosPortatiles\Headless\Rest\ErrorResponse;
use BanosPortatiles\Headless\Reviews\RatingService;
use BanosPortatiles\Headless\Reviews\ReviewInputValidator;
use BanosPortatiles\Headless\Routing\NodeLocator;
use BanosPortatiles\Headless\Security\SignedRequestGuard;
use BanosPortatiles\Headless\Support\DbLock;

/**
 * Star ratings (contract §3.3).
 *
 *   GET  /ratings            → [{uri, id, ...rating}] of every node with ratings enabled (build and reports)
 *   GET  /ratings?uri=/x/    → {uri, id, ...rating} · 404 · 403 {code: ratings_disabled}
 *   POST /ratings (HMAC)     → {uri, rating, voter, ip, ua?, country?}
 *                              201 {ok, created: true, summary} · 200 {ok, created: false, duplicate: true, summary}
 *                              403 disabled/rejected · 404 · 422 {errors} · 429 · 503
 */
final class RatingsController
{
    public const MAX_VOTES_PER_WINDOW = 30;

    public const WINDOW = 600;

    public function __construct(
        private readonly ResponseCache $cache,
        private readonly RatingService $ratings,
        private readonly NodeLocator $nodes,
        private readonly SignedRequestGuard $guard,
        private readonly ReviewInputValidator $validator,
        private readonly RateLimiter $limiter,
    ) {}

    public function index(\WP_REST_Request $request): \WP_REST_Response
    {
        $uri = trim((string) $request->get_param('uri'));
        if ($uri === '') {
            return new \WP_REST_Response($this->cache->remember('ratings:all', $this->all(...)));
        }

        $post = $this->nodes->find($uri);
        if ($post === null) {
            return ErrorResponse::make('bp_not_found', 'No existe contenido publicado en esa URI.', 404);
        }
        $entry = $this->cache->remember('ratings:'.$post->ID, fn (): array => $this->ratings->entry($post, $this->nodes->uriOf($post) ?? $uri) ?? []);
        if ($entry === []) {
            return ErrorResponse::make('ratings_disabled', 'Las valoraciones no están habilitadas en esta página.', 403);
        }

        return new \WP_REST_Response($entry);
    }

    public function authorize(\WP_REST_Request $request): true|\WP_Error
    {
        return $this->guard->authorize($request, 'bp_reviews_disabled', 'Las valoraciones no están configuradas (BP_LEADS_SECRET).');
    }

    public function vote(\WP_REST_Request $request): \WP_REST_Response
    {
        $payload = json_decode($request->get_body(), true);
        if (! is_array($payload)) {
            return ErrorResponse::make('bp_invalid_json', 'El cuerpo debe ser un objeto JSON.', 400);
        }
        $input = $this->validator->vote($payload);
        if (! $input->isValid() || $input->voter === null) {
            return ErrorResponse::invalid($input->errors);
        }
        $limit = $this->limiter->hit('ratings', $input->voter->ip, self::MAX_VOTES_PER_WINDOW, self::WINDOW);
        if (! $limit->allowed) {
            return ErrorResponse::rateLimited($limit->retryAfter);
        }
        if (! $this->ratings->available()) {
            return ErrorResponse::make('reviews_unavailable', 'Las valoraciones no están disponibles.', 503);
        }
        $post = $this->nodes->find($input->uri);
        if ($post === null) {
            return ErrorResponse::make('bp_not_found', 'No existe contenido publicado en esa URI.', 404);
        }
        if (! $this->ratings->flags($post)->stars) {
            return ErrorResponse::make('ratings_disabled', 'Las valoraciones no están habilitadas en esta página.', 403);
        }

        $uri = $this->nodes->uriOf($post) ?? $input->uri;
        $gateway = $this->ratings->gateway();
        $lock = 'vote:'.$post->ID.':'.$input->voter->voter;
        if (! DbLock::acquire($lock)) {
            return ErrorResponse::rateLimited(5);
        }
        try {
            $since = time() - RatingService::DEDUP_DAYS * DAY_IN_SECONDS;
            if ($gateway->findByVoter($post->ID, $input->voter->voter, $since) !== null) {
                return self::json(['ok' => true, 'created' => false, 'duplicate' => true, 'summary' => $this->ratings->entry($post, $uri)], 200);
            }
            $screen = $gateway->screen($input->voter);
            if ($screen === 'reject') {
                return ErrorResponse::make('rejected', 'No pudimos registrar tu calificación.', 403);
            }
            $created = $gateway->createVote($post->ID, $input->rating, $input->voter, $screen === null);
            if ($created instanceof \WP_Error) {
                return ErrorResponse::fromWpError($created);
            }
        } finally {
            DbLock::release($lock);
        }

        return self::json(['ok' => true, 'created' => true, 'summary' => $this->ratings->entry($post, $uri)], 201);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function all(): array
    {
        $entries = [];
        foreach ($this->nodes->all() as $post) {
            $uri = $this->nodes->uriOf($post);
            $entry = $uri !== null ? $this->ratings->entry($post, $uri) : null;
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }
        usort($entries, static fn (array $a, array $b): int => strcmp((string) $a['uri'], (string) $b['uri']));

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function json(array $data, int $status): \WP_REST_Response
    {
        $response = new \WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }
}
