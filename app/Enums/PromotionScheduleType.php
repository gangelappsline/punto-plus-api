<?php

namespace App\Enums;

/**
 * Tipo de programación de una promoción.
 *
 * - `indefinite`: sin fechas; se muestra siempre (o sólo los días indicados). Es el
 *   tipo que se deduce cuando el formulario no envía `schedule_type` ni fechas.
 * - `date_range`: se muestra dentro de un rango de fechas (festividades, campañas).
 * - `time_range`: se muestra ciertos días de la semana y a cierta hora (happy hour).
 */
enum PromotionScheduleType: string
{
    case Indefinite = 'indefinite';
    case DateRange = 'date_range';
    case TimeRange = 'time_range';

    public function label(): string
    {
        return match ($this) {
            self::Indefinite => 'Tiempo indefinido',
            self::DateRange => 'Por rango de fechas',
            self::TimeRange => 'Por rango de hora',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Indefinite => 'Sin fecha de inicio ni de fin; se muestra siempre o los días indicados.',
            self::DateRange => 'Sólo aparece entre las fechas de inicio y fin.',
            self::TimeRange => 'Aparece los días indicados, dentro de un rango de hora (por ejemplo, hora feliz).',
        };
    }

    public function requiresDateRange(): bool
    {
        return $this === self::DateRange;
    }

    public function requiresTimeRange(): bool
    {
        return $this === self::TimeRange;
    }

    public function requiresDaysOfWeek(): bool
    {
        return $this === self::Indefinite || $this === self::TimeRange;
    }
}
