<?php

namespace App\Enums;

enum RewardType: string
{
    case FreeProduct = 'free_product';
    case Discount = 'discount';
    case Gift = 'gift';
    case Experience = 'experience';

    public function label(): string
    {
        return match ($this) {
            self::FreeProduct => 'Producto gratis',
            self::Discount => 'Descuento',
            self::Gift => 'Regalo',
            self::Experience => 'Experiencia',
        };
    }
}
