<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Mail;

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * Optional SMTP transport for wp_mail (BP_SMTP_HOST, BP_SMTP_PORT, BP_SMTP_USER, BP_SMTP_PASS,
 * BP_SMTP_SECURE=tls|ssl, BP_SMTP_FROM). Locally it points to Mailpit.
 */
final class SmtpMailer implements Hookable
{
    public function __construct(private readonly Config $config) {}

    public function register(): void
    {
        add_action('phpmailer_init', [$this, 'configure']);
        add_filter('wp_mail_from', [$this, 'from']);
        add_filter('wp_mail_from_name', static fn (string $name): string => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES) ?: $name);
    }

    public function configure(mixed $mailer): void
    {
        $smtp = $this->config->smtp();
        if ($smtp['host'] === '' || ! $mailer instanceof \PHPMailer\PHPMailer\PHPMailer) {
            return;
        }

        $mailer->isSMTP();
        $mailer->Host = $smtp['host'];
        $mailer->Port = $smtp['port'];
        $mailer->SMTPAuth = $smtp['user'] !== '';
        $mailer->Username = $smtp['user'];
        $mailer->Password = $smtp['pass'];
        $mailer->SMTPSecure = in_array($smtp['secure'], ['tls', 'ssl'], true) ? $smtp['secure'] : '';
        $mailer->SMTPAutoTLS = $smtp['secure'] !== '';
        $mailer->Timeout = 10;
    }

    public function from(string $from): string
    {
        $configured = $this->config->smtp()['from'];

        return ($configured !== '' && is_email($configured) !== false) ? $configured : $from;
    }
}
