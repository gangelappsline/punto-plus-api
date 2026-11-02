<?php

namespace App\Enums;

enum StampSource: string
{
    case Scan = 'scan';
    case Manual = 'manual';
    case Import = 'import';
    case Adjustment = 'adjustment';

    /** El cliente validó un token de venta generado por el negocio. */
    case Token = 'token';

    public function label(): string
    {
        return match ($this) {
            self::Scan => 'Escaneo de QR',
            self::Manual => 'Registro manual',
            self::Import => 'Importación',
            self::Adjustment => 'Ajuste del negocio',
            self::Token => 'Token de venta',
        };
    }
}
