<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Leads;

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Contracts\Hookable;
use BanosPortatiles\Headless\Security\HmacSigner;

/**
 * Optional outgoing webhook per lead, delivered by WP-Cron (never blocks the API response) and
 * signed with the same HMAC scheme as the incoming requests.
 */
final class LeadWebhook implements Hookable
{
    public const HOOK = 'bp_headless_lead_webhook';

    public function __construct(private readonly Config $config) {}

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'deliver'], 10, 1);
    }

    public function schedule(int $leadId): void
    {
        if ($this->config->leadsWebhookUrl() === '') {
            update_post_meta($leadId, LeadRepository::META_PREFIX.'webhook', 'skipped');

            return;
        }
        wp_schedule_single_event(time(), self::HOOK, [$leadId]);
        update_post_meta($leadId, LeadRepository::META_PREFIX.'webhook', 'scheduled');
    }

    public function deliver(int $leadId): void
    {
        $url = $this->config->leadsWebhookUrl();
        if ($url === '' || get_post_type($leadId) !== 'lead') {
            return;
        }

        $fields = ['nombre', 'telefono', 'email', 'ciudad', 'servicio', 'mensaje', 'fecha_evento', 'cantidad', 'pagina'];
        $lead = [];
        foreach ($fields as $field) {
            $lead[$field] = LeadRepository::meta($leadId, $field);
        }
        $utm = json_decode(LeadRepository::meta($leadId, 'utm'), true);
        $lead['utm'] = is_array($utm) ? $utm : [];
        $lead['consentimiento_comercial'] = LeadRepository::meta($leadId, 'consentimiento_comercial') === '1';

        $body = (string) wp_json_encode([
            'event' => 'lead.created',
            'reference' => LeadRepository::meta($leadId, 'ref'),
            'created_at' => (string) get_post_time(DATE_ATOM, true, $leadId),
            'lead' => $lead,
            'admin_url' => admin_url('post.php?post='.$leadId.'&action=edit'),
        ]);
        $timestamp = time();

        $response = wp_remote_post($url, [
            'timeout' => 10,
            'user-agent' => 'bp-headless/'.\BanosPortatiles\Headless\VERSION,
            'reject_unsafe_urls' => ! $this->config->isLocal(),
            'headers' => [
                'Content-Type' => 'application/json',
                'X-BP-Timestamp' => (string) $timestamp,
                'X-BP-Signature' => (new HmacSigner($this->config->leadsSecret()))->sign($body, $timestamp),
            ],
            'body' => $body,
        ]);

        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        update_post_meta($leadId, LeadRepository::META_PREFIX.'webhook', $code >= 200 && $code < 300 ? 'delivered' : 'failed:'.$code);
    }
}
