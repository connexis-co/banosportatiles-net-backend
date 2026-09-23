<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless;

/**
 * Runtime configuration. Precedence: PHP constant (wp-config.php) → environment variable → site option.
 */
final class Config
{
    public const DEFAULT_FRONTEND_URL = 'https://banosportatiles.net';

    /** ACF options page storage prefix ("post_id" of the "Ajustes del sitio" page). */
    public const OPTIONS_ID = 'bp_site';

    public function frontendUrl(): string
    {
        $url = $this->read('BP_FRONTEND_URL');

        return rtrim($url !== '' ? $url : self::DEFAULT_FRONTEND_URL, '/');
    }

    public function frontendOrigin(): string
    {
        return self::originOf($this->frontendUrl()) ?? $this->frontendUrl();
    }

    public function frontendHost(): string
    {
        return strtolower((string) parse_url($this->frontendUrl(), PHP_URL_HOST));
    }

    public function leadsSecret(): string
    {
        return $this->read('BP_LEADS_SECRET');
    }

    /**
     * Secret for preview tokens. Falls back to a key derived from the WordPress salts, which is
     * enough because tokens are only verified by this same WordPress install.
     */
    public function previewSecret(): string
    {
        $secret = $this->read('BP_PREVIEW_SECRET');

        return $secret !== '' ? $secret : hash_hmac('sha256', 'bp-headless-preview', wp_salt('auth'));
    }

    public function deployHookUrl(): string
    {
        $url = $this->read('BP_DEPLOY_HOOK_URL');
        if ($url === '') {
            $url = $this->option('deploy_hook_url');
        }

        return self::validUrl($url);
    }

    public function deployHookSource(): string
    {
        if ($this->read('BP_DEPLOY_HOOK_URL') !== '') {
            return 'wp-config';
        }

        return $this->option('deploy_hook_url') !== '' ? 'ajustes' : 'none';
    }

    public function leadsWebhookUrl(): string
    {
        $url = $this->read('BP_LEADS_WEBHOOK_URL');

        return self::validUrl($url !== '' ? $url : $this->option('forms_leads_webhook_url'));
    }

    public function leadsNotifyEmail(): string
    {
        foreach ([$this->option('forms_leads_email'), $this->option('contact_email'), (string) get_option('admin_email')] as $email) {
            if ($email !== '' && is_email($email) !== false) {
                return $email;
            }
        }

        return '';
    }

    /**
     * Origins allowed by CORS on the REST API: the public front, extra BP_CORS_ORIGINS and the CMS itself.
     *
     * @return list<string>
     */
    public function corsOrigins(): array
    {
        $origins = [$this->frontendOrigin(), self::originOf((string) home_url('/'))];
        foreach (explode(',', $this->read('BP_CORS_ORIGINS')) as $origin) {
            $origins[] = self::originOf(trim($origin));
        }

        return array_values(array_unique(array_filter($origins, static fn (?string $o): bool => $o !== null && $o !== '')));
    }

    /**
     * @return array{host: string, port: int, user: string, pass: string, secure: string, from: string}
     */
    public function smtp(): array
    {
        return [
            'host' => $this->read('BP_SMTP_HOST'),
            'port' => (int) ($this->read('BP_SMTP_PORT') ?: 587),
            'user' => $this->read('BP_SMTP_USER'),
            'pass' => $this->read('BP_SMTP_PASS'),
            'secure' => $this->read('BP_SMTP_SECURE'),
            'from' => $this->read('BP_SMTP_FROM'),
        ];
    }

    public function isLocal(): bool
    {
        return wp_get_environment_type() === 'local';
    }

    /** Reads a raw value stored by the ACF options page (option name "bp_site_{name}"). */
    public function option(string $name): string
    {
        $value = get_option(self::OPTIONS_ID.'_'.$name, '');

        return is_scalar($value) ? trim((string) $value) : '';
    }

    public static function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return strtolower($parts['scheme'].'://'.$parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private static function validUrl(string $url): string
    {
        $url = trim($url);

        return ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $url) === 1) ? $url : '';
    }

    private function read(string $name): string
    {
        if (defined($name)) {
            $value = constant($name);

            return is_scalar($value) ? trim((string) $value) : '';
        }

        $env = getenv($name);

        return is_string($env) ? trim($env) : '';
    }
}
