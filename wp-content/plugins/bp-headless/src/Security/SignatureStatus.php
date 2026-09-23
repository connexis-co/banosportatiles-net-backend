<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Security;

enum SignatureStatus: string
{
    case Valid = 'valid';
    case Missing = 'missing';
    case Expired = 'expired';
    case Invalid = 'invalid';

    public function isValid(): bool
    {
        return $this === self::Valid;
    }

    public function message(): string
    {
        return match ($this) {
            self::Valid => 'Firma válida.',
            self::Missing => 'Faltan los encabezados X-BP-Signature o X-BP-Timestamp.',
            self::Expired => 'La marca de tiempo está fuera de la ventana permitida (±5 min).',
            self::Invalid => 'La firma no es válida.',
        };
    }
}
