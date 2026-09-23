<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Rest\Controllers;

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Leads\ClientIp;
use BanosPortatiles\Headless\Leads\LeadNotifier;
use BanosPortatiles\Headless\Leads\LeadRepository;
use BanosPortatiles\Headless\Leads\LeadValidator;
use BanosPortatiles\Headless\Leads\LeadWebhook;
use BanosPortatiles\Headless\Leads\RateLimiter;
use BanosPortatiles\Headless\Security\HmacSigner;

/**
 * POST /bp/v1/leads — server-to-server from the Astro Action (Zod + Turnstile already passed there).
 *
 * 1. authorize(): secret configured (503) → failed-auth throttle per IP (429) → HMAC + ±5 min (401) → replay (401).
 * 2. handle(): JSON (400) → validation (422) → per-client throttle (429) → lead + email + webhook (201).
 */
final class LeadsController
{
    public const MAX_LEADS_PER_WINDOW = 5;

    public const MAX_FAILED_AUTH_PER_WINDOW = 20;

    public const WINDOW = 600;

    public function __construct(
        private readonly Config $config,
        private readonly LeadValidator $validator,
        private readonly LeadRepository $repository,
        private readonly LeadNotifier $notifier,
        private readonly LeadWebhook $webhook,
        private readonly RateLimiter $limiter,
    ) {}

    public function authorize(\WP_REST_Request $request): true|\WP_Error
    {
        $secret = $this->config->leadsSecret();
        if ($secret === '') {
            return new \WP_Error('bp_leads_disabled', 'El endpoint de leads no está configurado (BP_LEADS_SECRET).', ['status' => 503]);
        }

        $ip = ClientIp::network();
        if ($this->limiter->tooMany('leads_auth_fail', $ip, self::MAX_FAILED_AUTH_PER_WINDOW)) {
            return new \WP_Error('bp_rate_limited', 'Demasiados intentos fallidos. Intenta más tarde.', ['status' => 429]);
        }

        $signature = (string) $request->get_header('x_bp_signature');
        $status = (new HmacSigner($secret))->verify(
            $request->get_body(),
            $signature,
            $request->get_header('x_bp_timestamp'),
            time()
        );

        if (! $status->isValid()) {
            $this->limiter->hit('leads_auth_fail', $ip, self::MAX_FAILED_AUTH_PER_WINDOW, self::WINDOW);

            return new \WP_Error('bp_invalid_signature', $status->message(), ['status' => 401]);
        }

        $nonce = 'bp_lead_sig_'.md5(strtolower($signature));
        if (get_transient($nonce) !== false) {
            return new \WP_Error('bp_replayed_request', 'Esta solicitud ya fue procesada.', ['status' => 401]);
        }
        set_transient($nonce, 1, 2 * HmacSigner::TOLERANCE);

        return true;
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

        $response = new \WP_REST_Response(['ok' => true, 'reference' => $created['reference']], 201);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }
}
