<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless;

/**
 * Runtime configuration. Precedence: PHP constant (wp-config.php) → environment variable → site option.
 */
final class Config
{
    public const DEFAULT_FRONTEND_URL = 'https://banosportatiles.net';

    public const DEFAULT_LEADS_EMAIL_TO = 'contacto@banosportatiles.net';

    public const DEFAULT_LEADS_EMAIL_CC = 'connexis.co@gmail.com';

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

    /**
     * Lead email recipients: BP_LEADS_EMAIL_TO → "Ajustes del sitio → Formularios" → contacto@banosportatiles.net.
     *
     * @return list<string>
     */
    public function leadsEmailTo(): array
    {
        foreach ([$this->read('BP_LEADS_EMAIL_TO'), $this->option('forms_leads_email'), self::DEFAULT_LEADS_EMAIL_TO] as $value) {
            $emails = self::emails($value);
            if ($emails !== []) {
                return $emails;
            }
        }

        return [];
    }

    /**
     * Copies: BP_LEADS_EMAIL_CC (comma separated; "none" disables) → connexis.co@gmail.com. Never repeats a TO address.
     *
     * @return list<string>
     */
    public function leadsEmailCc(): array
    {
        $value = $this->read('BP_LEADS_EMAIL_CC');
        if (strtolower($value) === 'none') {
            return [];
        }
        $to = array_map('strtolower', $this->leadsEmailTo());

        return array_values(array_filter(
            self::emails($value !== '' ? $value : self::DEFAULT_LEADS_EMAIL_CC),
            static fn (string $email): bool => ! in_array(strtolower($email), $to, true)
        ));
    }

    /**
     * Valid, unique addresses from a comma/semicolon separated list.
     *
     * @return list<string>
     */
    public static function emails(string $list): array
    {
        $emails = [];
        foreach (preg_split('/[,;]+/', $list) ?: [] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[strtolower($candidate)] = $candidate;
            }
        }

        return array_values($emails);
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
     * SMTP transport for wp_mail (Brevo in production: smtp-relay.brevo.com:587 + STARTTLS).
     *
     * @return array{host: string, port: int, user: string, pass: string, secure: string, from: string, from_name: string}
     */
    public function smtp(): array
    {
        $port = (int) $this->read('BP_SMTP_PORT');

        return [
            'host' => $this->read('BP_SMTP_HOST'),
            'port' => $port > 0 ? $port : 587,
            'user' => $this->read('BP_SMTP_USER'),
            'pass' => $this->read('BP_SMTP_PASS'),
            'secure' => strtolower($this->read('BP_SMTP_SECURE')),
            'from' => $this->read('BP_SMTP_FROM'),
            'from_name' => $this->read('BP_SMTP_FROM_NAME'),
        ];
    }

    /**
     * BP_LEADS_EMAIL (default true). Set it to false when the Astro Worker already emails each lead,
     * so POST /bp/v1/leads only stores it and duplicates are avoided.
     */
    public function leadsEmailEnabled(): bool
    {
        return $this->flag('BP_LEADS_EMAIL', true);
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

    /** Boolean setting: true/false constants, or "1|0|true|false|yes|no|on|off" strings (empty = default). */
    private function flag(string $name, bool $default): bool
    {
        if (defined($name)) {
            $value = constant($name);
            if (is_bool($value)) {
                return $value;
            }
            if (is_int($value)) {
                return $value !== 0;
            }
        }

        $raw = strtolower($this->read($name));
        if ($raw === '') {
            return $default;
        }

        return ! in_array($raw, ['0', 'false', 'no', 'off'], true);
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
