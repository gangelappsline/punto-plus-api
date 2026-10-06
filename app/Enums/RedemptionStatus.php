<?php

namespace App\Enums;

enum RedemptionStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente de validación en el negocio',
            self::Completed => 'Canjeado',
            self::Rejected => 'Rechazado por el negocio',
            self::Cancelled => 'Cancelado por el cliente',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
