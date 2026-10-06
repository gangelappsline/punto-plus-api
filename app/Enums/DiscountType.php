<?php

namespace App\Enums;

enum DiscountType: string
{
    case Percentage = 'percentage';
    case FixedAmount = 'fixed_amount';
    case FreeItem = 'free_item';
    case Gift = 'gift';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Porcentaje de descuento',
            self::FixedAmount => 'Importe fijo de descuento',
            self::FreeItem => 'Producto gratis',
            self::Gift => 'Regalo / obsequio',
        };
    }
}
