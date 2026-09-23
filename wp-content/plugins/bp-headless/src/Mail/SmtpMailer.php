<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Mail;

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * SMTP transport for every wp_mail() (password resets, notices, leads) configured only with constants,
 * no mail plugin: BP_SMTP_HOST, BP_SMTP_PORT, BP_SMTP_USER, BP_SMTP_PASS, BP_SMTP_FROM, BP_SMTP_FROM_NAME
 * and the optional BP_SMTP_SECURE override (tls|ssl|none).
 *
 * Production: Brevo, smtp-relay.brevo.com:587 with STARTTLS. Local: Mailpit with STARTTLS + AUTH.
 */
final class SmtpMailer implements Hookable
{
    public const TIMEOUT = 15;

    public function __construct(private readonly Config $config) {}

    public function register(): void
    {
        add_action('phpmailer_init', [$this, 'configure']);
        add_filter('wp_mail_from', [$this, 'from']);
        add_filter('wp_mail_from_name', [$this, 'fromName']);
    }

    public function configure(mixed $mailer): void
    {
        $smtp = $this->config->smtp();
        if ($smtp['host'] === '' || ! $mailer instanceof \PHPMailer\PHPMailer\PHPMailer) {
            return;
        }

        $encryption = self::encryption($smtp['secure'], $smtp['port']);

        $mailer->isSMTP();
        $mailer->Host = $smtp['host'];
        $mailer->Port = $smtp['port'];
        $mailer->SMTPAuth = $smtp['user'] !== '';
        $mailer->Username = $smtp['user'];
        $mailer->Password = $smtp['pass'];
        $mailer->SMTPSecure = in_array($encryption, ['tls', 'ssl'], true) ? $encryption : '';
        $mailer->SMTPAutoTLS = $encryption === 'auto';
        $mailer->Timeout = self::TIMEOUT;

        // Envelope sender (Return-Path) aligned with From for SPF/DMARC.
        if ($smtp['from'] !== '' && is_email($smtp['from']) !== false) {
            $mailer->Sender = $smtp['from'];
        }

        // Local Mailpit uses a self-signed certificate; never relaxed outside the local environment.
        if ($this->config->isLocal()) {
            $mailer->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        }
    }

    /**
     * Encryption for PHPMailer: "tls" (STARTTLS), "ssl" (implicit TLS), "none", or "auto"
     * (opportunistic STARTTLS). Without an explicit BP_SMTP_SECURE: 465 → ssl, 587 → tls, other → auto.
     */
    public static function encryption(string $secure, int $port): string
    {
        return match (strtolower(trim($secure))) {
            'tls', 'starttls' => 'tls',
            'ssl', 'smtps' => 'ssl',
            'none', 'off', 'false', '0' => 'none',
            default => match ($port) {
                465 => 'ssl',
                587 => 'tls',
                default => 'auto',
            },
        };
    }

    public function from(string $from): string
    {
        $configured = $this->config->smtp()['from'];

        return ($configured !== '' && is_email($configured) !== false) ? $configured : $from;
    }

    public function fromName(string $name): string
    {
        $configured = $this->config->smtp()['from_name'];
        if ($configured !== '') {
            return $configured;
        }
        $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);

        return $site !== '' ? $site : $name;
    }
}
