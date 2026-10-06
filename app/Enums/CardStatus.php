<?php

namespace App\Enums;

enum CardStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Expired = 'expired';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'En progreso',
            self::Completed => 'Completada (recompensa disponible)',
            self::Expired => 'Caducada',
            self::Blocked => 'Bloqueada',
        };
    }

    public function isUsable(): bool
    {
        return in_array($this, [self::Active, self::Completed], true);
    }
}
