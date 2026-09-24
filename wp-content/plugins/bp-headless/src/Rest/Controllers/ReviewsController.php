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
 * Reviews with text and rating (contract §3.3).
 *
 *   GET  /reviews?uri=/x/&page=1&per_page=10 → Review[] + X-WP-Total / X-WP-TotalPages (max. 50 per page)
 *   POST /reviews (HMAC) → {uri, rating, title?, content, name, email, consent: true, voter, ip, ua?}
 *        201 {ok, id, status: pending|approved[, updated: true]} · 409 {code: duplicate} · 403 · 404 · 422 {errors} · 429 · 503
 *
 * A voter who already left a quick vote on the node gets that vote turned into the review (same record, back to
 * moderation) instead of a second rating.
 */
final class ReviewsController
{
    public const MAX_REVIEWS_PER_WINDOW = 5;

    public const WINDOW = 600;

    public const MAX_PER_PAGE = 50;

    public const DEFAULT_PER_PAGE = 10;

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
        $page = max(1, (int) ($request->get_param('page') ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($request->get_param('per_page') ?? self::DEFAULT_PER_PAGE)));

        $post = $uri !== '' ? $this->nodes->find($uri) : null;
        if ($post === null) {
            return ErrorResponse::make('bp_not_found', 'No existe contenido publicado en esa URI.', 404);
        }
        if (! $this->ratings->flags($post)->reviews) {
            return ErrorResponse::make('reviews_disabled', 'Las opiniones no están habilitadas en esta página.', 403);
        }

        $result = $this->cache->remember(
            sprintf('reviews:%d:%d:%d', $post->ID, $page, $perPage),
            fn (): array => $this->ratings->page($post, $page, $perPage)
        );
        $response = new \WP_REST_Response($result['items']);
        $response->header('X-WP-Total', (string) $result['total']);
        $response->header('X-WP-TotalPages', (string) $result['pages']);

        return $response;
    }

    public function authorize(\WP_REST_Request $request): true|\WP_Error
    {
        return $this->guard->authorize($request, 'bp_reviews_disabled', 'Las opiniones no están configuradas (BP_LEADS_SECRET).');
    }

    public function create(\WP_REST_Request $request): \WP_REST_Response
    {
        $payload = json_decode($request->get_body(), true);
        if (! is_array($payload)) {
            return ErrorResponse::make('bp_invalid_json', 'El cuerpo debe ser un objeto JSON.', 400);
        }
        $input = $this->validator->review($payload);
        if (! $input->isValid() || $input->voter === null || $input->submission === null) {
            return ErrorResponse::invalid($input->errors);
        }
        $limit = $this->limiter->hit('reviews', $input->voter->ip, self::MAX_REVIEWS_PER_WINDOW, self::WINDOW);
        if (! $limit->allowed) {
            return ErrorResponse::rateLimited($limit->retryAfter);
        }
        if (! $this->ratings->available()) {
            return ErrorResponse::make('reviews_unavailable', 'Las opiniones no están disponibles.', 503);
        }
        $post = $this->nodes->find($input->uri);
        if ($post === null) {
            return ErrorResponse::make('bp_not_found', 'No existe contenido publicado en esa URI.', 404);
        }
        if (! $this->ratings->flags($post)->reviews) {
            return ErrorResponse::make('reviews_disabled', 'Las opiniones no están habilitadas en esta página.', 403);
        }

        $gateway = $this->ratings->gateway();
        $lock = 'vote:'.$post->ID.':'.$input->voter->voter;
        if (! DbLock::acquire($lock)) {
            return ErrorResponse::rateLimited(5);
        }
        try {
            $existing = $gateway->findByVoter($post->ID, $input->voter->voter, time() - RatingService::DEDUP_DAYS * DAY_IN_SECONDS);
            if ($existing !== null && $existing->hasText()) {
                return ErrorResponse::make('duplicate', 'Ya recibimos tu opinión sobre esta página.', 409);
            }
            $screen = $gateway->screen($input->voter, $input->submission);
            if ($screen === 'reject') {
                return ErrorResponse::make('rejected', 'No pudimos registrar tu opinión.', 403);
            }
            $approved = $screen === null && $this->ratings->settings()->autoApproveReviews;

            if ($existing !== null) {
                $result = $gateway->upgradeVote($existing->id, $input->submission, $input->voter, $approved);
                if ($result instanceof \WP_Error) {
                    return ErrorResponse::fromWpError($result);
                }

                return self::created($existing->id, $approved, true);
            }

            $id = $gateway->createReview($post->ID, $input->submission, $input->voter, $approved);
            if ($id instanceof \WP_Error) {
                return ErrorResponse::fromWpError($id);
            }

            return self::created($id, $approved, false);
        } finally {
            DbLock::release($lock);
        }
    }

    private static function created(int $id, bool $approved, bool $updated): \WP_REST_Response
    {
        $body = ['ok' => true, 'id' => $id, 'status' => $approved ? 'approved' : 'pending'];
        if ($updated) {
            $body['updated'] = true;
        }
        $response = new \WP_REST_Response($body, 201);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }
}
