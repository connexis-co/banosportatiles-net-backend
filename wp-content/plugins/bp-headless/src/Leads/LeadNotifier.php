<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Leads;

use BanosPortatiles\Headless\Config;

/**
 * Emails every new lead to the notification address of "Ajustes del sitio".
 */
final class LeadNotifier
{
    public function __construct(private readonly Config $config) {}

    public function notify(int $leadId, LeadData $lead, string $reference): bool
    {
        $to = $this->config->leadsNotifyEmail();
        if ($to === '') {
            update_post_meta($leadId, LeadRepository::META_PREFIX.'mail', 'skipped');

            return false;
        }

        $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $subject = sprintf('[%s] Nuevo lead: %s%s', $site, $lead->servicio ?? 'cotización', $lead->ciudad !== null ? ' en '.$lead->ciudad : '');
        $lines = [
            'Nuevo lead recibido desde '.$this->config->frontendUrl(),
            '',
            'Nombre: '.$lead->nombre,
            'Teléfono: '.$lead->telefono,
            'Email: '.($lead->email ?? '—'),
            'Ciudad: '.($lead->ciudad ?? '—'),
            'Servicio: '.($lead->servicio ?? '—'),
            'Fecha del evento: '.($lead->fechaEvento ?? '—'),
            'Cantidad: '.($lead->cantidad ?? '—'),
            'Página: '.($lead->pagina ?? '—'),
            '',
            'Mensaje:',
            $lead->mensaje ?? '—',
            '',
            'Referencia: '.$reference,
            'Ver en el CMS: '.admin_url('post.php?post='.$leadId.'&action=edit'),
        ];
        if ($lead->utm !== []) {
            $lines[] = 'UTM: '.http_build_query($lead->utm, '', ', ');
        }

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        if ($lead->email !== null) {
            $name = trim((string) preg_replace('/[\r\n<>"]+/', ' ', $lead->nombre));
            $headers[] = sprintf('Reply-To: %s <%s>', $name, $lead->email);
        }

        $sent = wp_mail($to, $subject, implode("\n", $lines), $headers);
        update_post_meta($leadId, LeadRepository::META_PREFIX.'mail', $sent ? 'sent' : 'failed');

        return $sent;
    }
}
