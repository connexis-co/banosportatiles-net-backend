<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Leads;

/**
 * Pure builder for the lead notification email: subject, HTML body and plain-text alternative.
 * Times are shown in Colombia (America/Bogota) whatever the server or site timezone.
 */
final class LeadEmail
{
    public const TIMEZONE = 'America/Bogota';

    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /**
     * Context: ciudad = display name of the city term (when the slug matches one); origin = absolute URL
     * of the page where the form was sent; created = unix timestamp of the lead; front = public site URL
     * (links of servicio_uri and landing).
     *
     * @param  array{reference: string, created: int, ciudad: ?string, origin: ?string, admin: string, site: string, front?: string}  $context
     */
    public function __construct(private readonly LeadData $lead, private readonly array $context) {}

    public function subject(): string
    {
        $ciudad = $this->ciudad();

        return 'Nueva cotización: '.($this->lead->servicio ?? 'solicitud de cotización')
            .($ciudad !== null ? ' en '.$ciudad : '').' — '.$this->lead->nombre;
    }

    public function html(): string
    {
        $rows = '';
        foreach ($this->rows() as [$label, $value, $url]) {
            $content = $url !== null
                ? '<a href="'.self::e($url).'">'.self::e($value).'</a>'
                : nl2br(self::e($value), false);
            $rows .= '<tr><th align="left" valign="top" style="padding:6px 12px 6px 0;color:#555;font-weight:600;white-space:nowrap">'
                .self::e($label).'</th><td style="padding:6px 0">'.$content.'</td></tr>';
        }

        return '<!doctype html><html lang="es-CO"><body style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#1a1a1a">'
            .'<h2 style="margin:0 0 12px">'.self::e($this->subject()).'</h2>'
            .'<table cellspacing="0" cellpadding="0" style="border-collapse:collapse">'.$rows.'</table>'
            .'<p style="margin-top:20px;color:#666;font-size:13px">'.self::e($this->context['site'])
            .' · Datos personales tratados según la Ley 1581 de 2012.</p></body></html>';
    }

    public function text(): string
    {
        $lines = [$this->subject(), ''];
        foreach ($this->rows() as [$label, $value]) {
            $lines[] = $label.': '.$value;
        }

        return implode("\n", $lines);
    }

    public static function formatDate(int $timestamp): string
    {
        $date = (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone(self::TIMEZONE));

        return sprintf(
            '%d de %s de %s, %s (hora de Colombia)',
            (int) $date->format('j'),
            self::MONTHS[(int) $date->format('n') - 1],
            $date->format('Y'),
            str_replace(['am', 'pm'], ['a. m.', 'p. m.'], $date->format('g:i a'))
        );
    }

    /**
     * @return list<array{0: string, 1: string, 2: ?string}> label, value, optional link
     */
    private function rows(): array
    {
        $lead = $this->lead;
        $utm = [];
        foreach ($lead->utm as $key => $value) {
            $utm[] = $key.'='.$value;
        }

        $rows = [
            ['Nombre', $lead->nombre, null],
            ['Teléfono', $lead->telefono, 'tel:'.$lead->telefono],
            ['WhatsApp', 'Abrir chat', 'https://wa.me/'.ltrim($lead->telefono, '+')],
            ['Email', $lead->email ?? '—', $lead->email !== null ? 'mailto:'.$lead->email : null],
            ['Ciudad', $this->ciudad() ?? '—', null],
            ['Servicio', $lead->servicio ?? '—', null],
            ['Fecha del evento', $lead->fechaEvento ?? '—', null],
            ['Cantidad', $lead->cantidad !== null ? (string) $lead->cantidad : '—', null],
            ['Mensaje', $lead->mensaje ?? '—', null],
            ['URL de origen', $this->context['origin'] ?? '—', $this->context['origin']],
            ...$this->attributionRows(),
            ['UTM', $utm !== [] ? implode(', ', $utm) : '—', null],
            ['Tratamiento de datos', 'Aceptado (Ley 1581 de 2012)', null],
            ['Comunicaciones comerciales', $lead->consentimientoComercial ? 'Sí, acepta' : 'No', null],
            ['Recibido', self::formatDate($this->context['created']), null],
            ['Referencia', $this->context['reference'], null],
            ['Ver en el CMS', $this->context['admin'], $this->context['admin']],
        ];

        return $rows;
    }

    /**
     * CTA location, service page, landing and referrer (only the ones sent) and the acquisition channel.
     *
     * @return list<array{0: string, 1: string, 2: ?string}>
     */
    private function attributionRows(): array
    {
        $attribution = $this->lead->attribution();
        $front = rtrim($this->context['front'] ?? '', '/');
        $rows = [];
        if (isset($attribution['origen'])) {
            $rows[] = ['Botón', LeadAttribution::origin($attribution['origen']), null];
        }
        foreach (['servicio_uri' => 'Servicio (página)', 'landing' => 'Primera página de la visita'] as $key => $label) {
            if (isset($attribution[$key])) {
                $rows[] = [$label, $attribution[$key], $front !== '' ? $front.$attribution[$key] : null];
            }
        }
        if (isset($attribution['referrer'])) {
            $rows[] = ['Llegó desde', $attribution['referrer'], $attribution['referrer']];
        }
        $rows[] = ['Canal', LeadAttribution::channel($attribution), null];

        return $rows;
    }

    private function ciudad(): ?string
    {
        return $this->context['ciudad'] ?? $this->lead->ciudad;
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
