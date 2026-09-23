<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Security;

use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * Security headers for wp-admin and wp-login.php.
 */
final class SecurityHeaders implements Hookable
{
    public function register(): void
    {
        add_action('admin_init', [$this, 'send'], 1);
        add_action('login_init', [$this, 'send'], 1);
    }

    public function send(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header("Content-Security-Policy: frame-ancestors 'self'");
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        if (is_ssl()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}
