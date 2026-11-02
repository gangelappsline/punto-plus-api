<?php

namespace App\Enums;

/**
 * Tipo de tarjeta de fidelidad de un negocio.
 *
 * - `visits`: el cliente acumula una visita por cada compra/escaneo y, al llegar
 *   al número de visitas configurado, obtiene la recompensa del ciclo.
 * - `spend`: cada cierto monto de consumo se suman puntos; las recompensas se
 *   desbloquean por umbrales de puntos (puede haber varios niveles).
 */
enum LoyaltyCardType: string
{
    case Visits = 'visits';
    case Spend = 'spend';

    public function label(): string
    {
        return match ($this) {
            self::Visits => 'Por cantidad de visitas',
            self::Spend => 'Por consumo (puntos)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Visits => 'El cliente suma una visita por compra y recibe la recompensa al completar las visitas requeridas.',
            self::Spend => 'El cliente suma puntos según el monto de su consumo y los canjea por recompensas de distintos niveles.',
        };
    }

    public function isVisits(): bool
    {
        return $this === self::Visits;
    }

    public function isSpend(): bool
    {
        return $this === self::Spend;
    }

    /** Unidad con la que progresa la tarjeta. */
    public function progressUnit(): string
    {
        return match ($this) {
            self::Visits => 'visits',
            self::Spend => 'points',
        };
    }

    /** Columna de `rewards` que define el umbral de la recompensa. */
    public function rewardThresholdColumn(): string
    {
        return match ($this) {
            self::Visits => 'required_stamps',
            self::Spend => 'points_required',
        };
    }
}
