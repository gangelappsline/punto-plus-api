<?php

namespace App\Enums;

/**
 * Vigencia del ciclo de una tarjeta de visitas (o de puntos).
 *
 * - `unlimited`: el cliente puede completar el ciclo cuando quiera.
 * - `days`: el ciclo dura N días desde que se inicia (alta o reinicio).
 */
enum CardPeriodType: string
{
    case Unlimited = 'unlimited';
    case Days = 'days';

    public function label(): string
    {
        return match ($this) {
            self::Unlimited => 'Sin límite de tiempo',
            self::Days => 'En un lapso de días',
        };
    }

    public function requiresDays(): bool
    {
        return $this === self::Days;
    }
}
