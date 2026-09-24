<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Security;

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Leads\ClientIp;
use BanosPortatiles\Headless\Leads\RateLimiter;

/**
 * permission_callback of the server-to-server endpoints signed by the Astro Worker (POST /leads, /ratings,
 * /reviews). One secret (BP_LEADS_SECRET), one scheme (HmacSigner) and one set of rules:
 *
 *   secret configured (503) → failed-auth throttle per network IP (429) → HMAC + ±5 min (401) → replay (401).
 *
 * The failed-auth counter and the one-time signature cache are shared by every signed endpoint.
 */
final class SignedRequestGuard
{
    public const MAX_FAILED_AUTH_PER_WINDOW = 20;

    public const WINDOW = 600;

    public const FAILED_AUTH_BUCKET = 'leads_auth_fail';

    public const NONCE_PREFIX = 'bp_lead_sig_';

    public function __construct(
        private readonly Config $config,
        private readonly RateLimiter $limiter,
    ) {}

    public function authorize(\WP_REST_Request $request, string $disabledCode, string $disabledMessage): true|\WP_Error
    {
        $secret = $this->config->leadsSecret();
        if ($secret === '') {
            return new \WP_Error($disabledCode, $disabledMessage, ['status' => 503]);
        }

        $ip = ClientIp::network();
        if ($this->limiter->tooMany(self::FAILED_AUTH_BUCKET, $ip, self::MAX_FAILED_AUTH_PER_WINDOW)) {
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
            $this->limiter->hit(self::FAILED_AUTH_BUCKET, $ip, self::MAX_FAILED_AUTH_PER_WINDOW, self::WINDOW);

            return new \WP_Error('bp_invalid_signature', $status->message(), ['status' => 401]);
        }

        $nonce = self::NONCE_PREFIX.md5(strtolower($signature));
        if (get_transient($nonce) !== false) {
            return new \WP_Error('bp_replayed_request', 'Esta solicitud ya fue procesada.', ['status' => 401]);
        }
        set_transient($nonce, 1, 2 * HmacSigner::TOLERANCE);

        return true;
    }
}
