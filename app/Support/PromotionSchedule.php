<?php

namespace App\Support;

use App\Enums\PromotionScheduleType;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Normaliza los campos de programación de una promoción para que la base de
 * datos guarde sólo lo que aplica al `schedule_type` elegido.
 */
class PromotionSchedule
{
    /**
     * Campos que describen la programación de una promoción.
     *
     * @var list<string>
     */
    public const FIELDS = ['schedule_type', 'days_of_week', 'start_time', 'end_time', 'starts_at', 'ends_at'];

    /**
     * ¿La petición envía algún campo de programación?
     *
     * @param  array<string, mixed>  $data
     */
    public static function touches(array $data): bool
    {
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deduce el tipo de programación cuando el front no lo envía.
     *
     * El formulario del negocio no siempre manda `schedule_type`: se deduce de
     * los datos que sí manda. Sin fechas ni horas la promoción es de tiempo
     * indefinido (por eso no se le exigen fechas), con fechas es por rango de
     * fechas y con horas es por rango de hora.
     *
     * @param  array<string, mixed>  $data
     */
    public static function resolveType(mixed $explicit, array $data, mixed $fallback = null): PromotionScheduleType
    {
        $type = $explicit instanceof PromotionScheduleType
            ? $explicit
            : PromotionScheduleType::tryFrom(is_string($explicit) ? $explicit : '');

        if ($type !== null) {
            return $type;
        }

        if (filled($data['start_time'] ?? null) || filled($data['end_time'] ?? null)) {
            return PromotionScheduleType::TimeRange;
        }

        if (filled($data['starts_at'] ?? null) || filled($data['ends_at'] ?? null)) {
            return PromotionScheduleType::DateRange;
        }

        return $fallback instanceof PromotionScheduleType ? $fallback : PromotionScheduleType::Indefinite;
    }

    /**
     * Atributos de programación listos para guardar.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function attributes(array $data, PromotionScheduleType $type): array
    {
        $days = self::days($data['days_of_week'] ?? []);

        $attributes = [
            'schedule_type' => $type->value,
            'days_of_week' => $days === [] ? null : $days,
            'starts_at' => null,
            'ends_at' => null,
            'start_time' => null,
            'end_time' => null,
        ];

        switch ($type) {
            case PromotionScheduleType::DateRange:
                $attributes['starts_at'] = self::startOfDay($data['starts_at'] ?? null);
                $attributes['ends_at'] = self::endOfDay($data['ends_at'] ?? null);
                $attributes['days_of_week'] = null;
                break;

            case PromotionScheduleType::TimeRange:
                $attributes['start_time'] = self::time($data['start_time'] ?? null);
                $attributes['end_time'] = self::time($data['end_time'] ?? null);
                break;

            case PromotionScheduleType::Indefinite:
            default:
                // Sin fechas ni horas: sólo días de la semana.
                break;
        }

        return $attributes;
    }

    /**
     * Días ISO-8601 (1 = lunes … 7 = domingo), únicos y ordenados.
     *
     * @param  mixed  $days
     * @return array<int, int>
     */
    public static function days(mixed $days): array
    {
        $days = array_map('intval', array_filter((array) $days, fn ($day): bool => $day !== null && $day !== ''));
        $days = array_values(array_unique(array_filter($days, fn (int $day): bool => $day >= 1 && $day <= 7)));

        sort($days);

        return $days;
    }

    public static function startOfDay(mixed $value): ?Carbon
    {
        return self::parse($value)?->startOfDay();
    }

    public static function endOfDay(mixed $value): ?Carbon
    {
        return self::parse($value)?->endOfDay()->startOfSecond();
    }

    /**
     * Hora en formato HH:MM:SS (columna TIME).
     */
    public static function time(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $parts = explode(':', trim($value));
        $hour = (int) ($parts[0] ?? 0);
        $minute = (int) ($parts[1] ?? 0);

        return sprintf('%02d:%02d:00', $hour, $minute);
    }

    private static function parse(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
