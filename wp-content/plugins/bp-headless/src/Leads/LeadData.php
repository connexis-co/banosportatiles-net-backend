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
    ) {}

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
        ];
    }
}
