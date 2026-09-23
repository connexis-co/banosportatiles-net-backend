<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Leads;

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Content\Taxonomies;

/**
 * Emails each lead (HTML + text) through wp_mail → phpmailer_init → Brevo SMTP (BP_SMTP_*).
 * A failed send never loses the lead: it stays stored with status "email_failed" and the error,
 * ready for `wp bp leads resend <id>`.
 *
 * Meta: _bp_lead_status (email_sent | email_failed | email_disabled | email_skipped),
 *       _bp_lead_email_error, _bp_lead_email_attempts, _bp_lead_email_sent_at.
 */
final class LeadNotifier
{
    public const STATUS_SENT = 'email_sent';

    public const STATUS_FAILED = 'email_failed';

    public const STATUS_DISABLED = 'email_disabled';

    public const STATUS_SKIPPED = 'email_skipped';

    public function __construct(private readonly Config $config) {}

    public function notify(int $leadId, LeadData $lead, string $reference): string
    {
        if (! $this->config->leadsEmailEnabled()) {
            return $this->record($leadId, self::STATUS_DISABLED, '');
        }

        $to = $this->config->leadsEmailTo();
        if ($to === []) {
            return $this->record($leadId, self::STATUS_SKIPPED, 'Sin destinatario (BP_LEADS_EMAIL_TO).');
        }

        $email = new LeadEmail($lead, [
            'reference' => $reference,
            'created' => (int) get_post_time('U', true, $leadId),
            'ciudad' => $this->ciudadName($lead->ciudad),
            'origin' => $lead->pagina !== null ? $this->config->frontendUrl().$lead->pagina : null,
            'admin' => admin_url('post.php?post='.$leadId.'&action=edit'),
            'site' => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
        ]);

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        foreach ($this->config->leadsEmailCc() as $cc) {
            $headers[] = 'Cc: '.$cc;
        }
        if ($lead->email !== null) {
            $name = trim((string) preg_replace('/[\r\n<>",;]+/', ' ', $lead->nombre));
            $headers[] = sprintf('Reply-To: %s <%s>', $name, $lead->email);
        }

        $error = '';
        $captureError = static function (\WP_Error $failure) use (&$error): void {
            $error = $failure->get_error_message();
        };
        $plainText = static function (mixed $mailer) use ($email): void {
            if ($mailer instanceof \PHPMailer\PHPMailer\PHPMailer) {
                $mailer->AltBody = $email->text();
            }
        };

        add_action('wp_mail_failed', $captureError);
        add_action('phpmailer_init', $plainText, 20);
        try {
            $sent = wp_mail($to, $email->subject(), $email->html(), $headers);
        } finally {
            remove_action('wp_mail_failed', $captureError);
            remove_action('phpmailer_init', $plainText, 20);
        }

        if (! $sent) {
            $error = $error !== '' ? $error : 'wp_mail() devolvió false.';
            error_log(sprintf('[bp-headless] Lead #%d: no se pudo enviar el email (%s). Reintenta con: wp bp leads resend %d', $leadId, $error, $leadId));

            return $this->record($leadId, self::STATUS_FAILED, $error);
        }

        update_post_meta($leadId, LeadRepository::META_PREFIX.'email_sent_at', current_time('mysql'));

        return $this->record($leadId, self::STATUS_SENT, '');
    }

    private function record(int $leadId, string $status, string $error): string
    {
        $attempts = (int) LeadRepository::meta($leadId, 'email_attempts');
        update_post_meta($leadId, LeadRepository::META_PREFIX.'status', $status);
        update_post_meta($leadId, LeadRepository::META_PREFIX.'email_error', $error);
        if (in_array($status, [self::STATUS_SENT, self::STATUS_FAILED], true)) {
            update_post_meta($leadId, LeadRepository::META_PREFIX.'email_attempts', (string) ($attempts + 1));
        }

        return $status;
    }

    private function ciudadName(?string $ciudad): ?string
    {
        if ($ciudad === null) {
            return null;
        }
        $term = get_term_by('slug', sanitize_title($ciudad), Taxonomies::CIUDAD);

        return $term instanceof \WP_Term ? html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $ciudad;
    }
}
