<?php

namespace App\Enums;

enum StampSource: string
{
    case Scan = 'scan';
    case Manual = 'manual';
    case Import = 'import';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Scan => 'Escaneo de QR',
            self::Manual => 'Registro manual',
            self::Import => 'Importación',
            self::Adjustment => 'Ajuste del negocio',
        };
    }
}
