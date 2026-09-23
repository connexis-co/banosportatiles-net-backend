<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Leads;

/**
 * Client IP helpers. REMOTE_ADDR is the only trusted source (the origin should restore the real IP
 * behind Cloudflare at the web-server level); X-BP-Client-IP is honoured only on HMAC-signed requests.
 */
final class ClientIp
{
    public static function network(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? trim($_SERVER['REMOTE_ADDR']) : '';

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }

    public static function forwarded(?string $header): ?string
    {
        $ip = trim((string) $header);

        return ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) ? $ip : null;
    }
}
