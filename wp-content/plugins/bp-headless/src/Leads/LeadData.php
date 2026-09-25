<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Leads;

/**
 * A validated, sanitized quote request.
 */
final readonly class LeadData
{
    /**
     * @param  array<string, string>  $utm
     */
    public function __construct(
        public string $nombre,
        public string $telefono,
        public ?string $email = null,
        public ?string $ciudad = null,
        public ?string $servicio = null,
        public ?string $mensaje = null,
        public ?string $fechaEvento = null,
        public ?int $cantidad = null,
        public ?string $pagina = null,
        public array $utm = [],
        public bool $consentimiento = true,
        public bool $consentimientoComercial = false,
        public ?string $origen = null,
        public ?string $servicioUri = null,
        public ?string $referrer = null,
        public ?string $landing = null,
    ) {}

    /**
     * Attribution as stored and shown (contract names): origen, servicio_uri, referrer, landing, utm_* and
     * click ids. Empty values are left out.
     *
     * @return array<string, string>
     */
    public function attribution(): array
    {
        $values = array_filter([
            'origen' => $this->origen,
            'servicio_uri' => $this->servicioUri,
            'referrer' => $this->referrer,
            'landing' => $this->landing,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        return $values + LeadAttribution::fromUtm($this->utm);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'nombre' => $this->nombre,
            'telefono' => $this->telefono,
            'email' => $this->email,
            'ciudad' => $this->ciudad,
            'servicio' => $this->servicio,
            'mensaje' => $this->mensaje,
            'fecha_evento' => $this->fechaEvento,
            'cantidad' => $this->cantidad,
            'pagina' => $this->pagina,
            'utm' => $this->utm,
            'consentimiento' => $this->consentimiento,
            'consentimiento_comercial' => $this->consentimientoComercial,
            'origen' => $this->origen,
            'servicio_uri' => $this->servicioUri,
            'referrer' => $this->referrer,
            'landing' => $this->landing,
        ];
    }
}
