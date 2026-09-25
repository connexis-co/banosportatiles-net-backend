<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Rest\Controllers;

use BanosPortatiles\Headless\Leads\ClientIp;
use BanosPortatiles\Headless\Leads\LeadNotifier;
use BanosPortatiles\Headless\Leads\LeadRepository;
use BanosPortatiles\Headless\Leads\LeadValidator;
use BanosPortatiles\Headless\Leads\LeadWebhook;
use BanosPortatiles\Headless\Leads\RateLimiter;
use BanosPortatiles\Headless\Security\SignedRequestGuard;

/**
 * POST /bp/v1/leads — server-to-server from the Astro Action (Zod + Turnstile already passed there).
 *
 * 1. authorize(): SignedRequestGuard — secret (503) → failed-auth throttle (429) → HMAC + ±5 min (401) → replay (401).
 * 2. handle(): JSON (400) → validation (422) → per-client throttle (429) → lead + email + webhook (201).
 *    Attribution (origen, servicio_uri, referrer, landing, utm_*, click ids) never causes a 422: fields with a
 *    wrong format are dropped and listed in the 201 response as "ignored".
 */
final class LeadsController
{
    public const MAX_LEADS_PER_WINDOW = 5;

    public const WINDOW = 600;

    public function __construct(
        private readonly SignedRequestGuard $guard,
        private readonly LeadValidator $validator,
        private readonly LeadRepository $repository,
        private readonly LeadNotifier $notifier,
        private readonly LeadWebhook $webhook,
        private readonly RateLimiter $limiter,
    ) {}

    public function authorize(\WP_REST_Request $request): true|\WP_Error
    {
        return $this->guard->authorize($request, 'bp_leads_disabled', 'El endpoint de leads no está configurado (BP_LEADS_SECRET).');
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $payload = json_decode($request->get_body(), true);
        if (! is_array($payload)) {
            return new \WP_Error('bp_invalid_json', 'El cuerpo debe ser un objeto JSON.', ['status' => 400]);
        }

        $result = $this->validator->validate($payload);
        if (! $result->isValid() || $result->lead === null) {
            return new \WP_Error('bp_invalid_lead', 'Hay campos inválidos.', ['status' => 422, 'errors' => $result->errors]);
        }

        $clientIp = ClientIp::forwarded($request->get_header('x_bp_client_ip')) ?? ClientIp::network();
        $limit = $this->limiter->hit('leads', $clientIp, self::MAX_LEADS_PER_WINDOW, self::WINDOW);
        if (! $limit->allowed) {
            $response = new \WP_REST_Response([
                'code' => 'bp_rate_limited',
                'message' => 'Recibimos varias solicitudes seguidas. Intenta de nuevo en unos minutos.',
                'data' => ['status' => 429, 'retry_after' => $limit->retryAfter],
            ], 429);
            $response->header('Retry-After', (string) $limit->retryAfter);

            return $response;
        }

        $created = $this->repository->create($result->lead, [
            'ip_hash' => hash_hmac('sha256', $clientIp, wp_salt('nonce')),
            'user_agent' => (string) ($request->get_header('x_bp_user_agent') ?? $request->get_header('user_agent') ?? ''),
        ]);
        if ($created instanceof \WP_Error) {
            return new \WP_Error('bp_lead_not_saved', 'No pudimos guardar la solicitud.', ['status' => 500]);
        }

        $this->notifier->notify($created['id'], $result->lead, $created['reference']);
        $this->webhook->schedule($created['id']);
        do_action('bp_headless/lead_created', $created['id'], $result->lead);

        // "ignored": attribution fields dropped because of their format (the lead is saved anyway).
        $response = new \WP_REST_Response(['ok' => true, 'reference' => $created['reference']] + ($result->ignored !== [] ? ['ignored' => $result->ignored] : []), 201);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }
}
