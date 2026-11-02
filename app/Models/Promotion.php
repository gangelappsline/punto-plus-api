<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\PromotionScheduleType;
use App\Support\Media;
use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Promoción publicada por un negocio (puede estar ligada a una tarjeta).
 *
 * Se programa de tres formas (`schedule_type`):
 *  - `indefinite`: sin fecha de fin; sólo aparece ciertos días de la semana.
 *  - `date_range`: sólo dentro del rango `starts_at`–`ends_at`.
 *  - `time_range`: ciertos días de la semana, dentro de `start_time`–`end_time`
 *    (la franja puede cruzar la medianoche, típico de la hora feliz).
 */
#[Fillable([
    'business_id', 'loyalty_card_id', 'title', 'description', 'image_path', 'terms',
    'discount_type', 'discount_value', 'code',
    'schedule_type', 'days_of_week', 'start_time', 'end_time',
    'starts_at', 'ends_at',
    'is_active', 'max_redemptions', 'redemptions_count',
])]
class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Nombres de los días en ISO-8601 (1 = lunes … 7 = domingo).
     *
     * @var array<int, string>
     */
    public const WEEKDAYS = [
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado',
        7 => 'Domingo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'schedule_type' => PromotionScheduleType::class,
            'days_of_week' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'max_redemptions' => 'integer',
            'redemptions_count' => 'integer',
        ];
    }

    // -----------------------------------------------------------------
    // Relaciones
    // -----------------------------------------------------------------

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function loyaltyCard(): BelongsTo
    {
        return $this->belongsTo(LoyaltyCard::class);
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /**
     * Publicadas y que no han terminado por fecha (prefiltro para SQL).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public function scopeForBusiness(Builder $query, Business|string $business): Builder
    {
        return $query->where('business_id', $business instanceof Business ? $business->getKey() : $business);
    }

    public function scopeOfScheduleType(Builder $query, PromotionScheduleType|string|null $type): Builder
    {
        if (blank($type)) {
            return $query;
        }

        return $query->where('schedule_type', $type instanceof PromotionScheduleType ? $type->value : $type);
    }

    // -----------------------------------------------------------------
    // Accesores y helpers
    // -----------------------------------------------------------------

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => Media::url($this->image_path));
    }

    /**
     * Días configurados como enteros ISO-8601.
     *
     * @return array<int, int>
     */
    protected function weekdays(): Attribute
    {
        return Attribute::get(function (): array {
            $days = array_map('intval', (array) ($this->days_of_week ?? []));
            $days = array_values(array_unique(array_filter($days, fn (int $day): bool => $day >= 1 && $day <= 7)));

            sort($days);

            return $days;
        });
    }

    /**
     * @return array<int, string>
     */
    protected function weekdayLabels(): Attribute
    {
        return Attribute::get(fn (): array => array_map(
            static fn (int $day): string => self::WEEKDAYS[$day] ?? (string) $day,
            $this->weekdays,
        ));
    }

    /**
     * Resumen de la programación para la app.
     *
     * @return array<string, mixed>
     */
    protected function schedule(): Attribute
    {
        return Attribute::get(fn (): array => [
            'type' => $this->schedule_type->value,
            'type_label' => $this->schedule_type->label(),
            'days_of_week' => $this->weekdays,
            'days_of_week_labels' => $this->weekday_labels,
            'start_time' => self::formatTime($this->start_time),
            'end_time' => self::formatTime($this->end_time),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'is_available_now' => $this->isAvailableNow(),
        ]);
    }

    /**
     * Comprueba la programación completa (tipo, días, fechas y horas).
     */
    public function isAvailableNow(?Carbon $at = null): bool
    {
        $at ??= now();

        if (! $this->is_active || $this->hasReachedLimit()) {
            return false;
        }

        return match ($this->schedule_type) {
            PromotionScheduleType::DateRange => $this->isWithinDateRange($at),
            PromotionScheduleType::Indefinite => $this->isAllowedWeekday($at),
            PromotionScheduleType::TimeRange => $this->isWithinTimeWindow($at)
                && $this->isAllowedWeekday($this->scheduleDay($at)),
        };
    }

    /**
     * Alias histórico (el resource sigue exponiendo `is_active_now`).
     */
    public function isActiveNow(): bool
    {
        return $this->isAvailableNow();
    }

    public function isIndefinite(): bool
    {
        return $this->schedule_type === PromotionScheduleType::Indefinite;
    }

    public function isDateRange(): bool
    {
        return $this->schedule_type === PromotionScheduleType::DateRange;
    }

    public function isTimeRange(): bool
    {
        return $this->schedule_type === PromotionScheduleType::TimeRange;
    }

    public function hasReachedLimit(): bool
    {
        return $this->max_redemptions !== null
            && $this->redemptions_count >= $this->max_redemptions;
    }

    // -----------------------------------------------------------------
    // Interno de la programación
    // -----------------------------------------------------------------

    private function isWithinDateRange(Carbon $at): bool
    {
        $date = $at->copy()->startOfDay();

        if ($this->starts_at !== null && $date->lt($this->starts_at->copy()->startOfDay())) {
            return false;
        }

        if ($this->ends_at !== null && $date->gt($this->ends_at->copy()->endOfDay())) {
            return false;
        }

        return true;
    }

    private function isAllowedWeekday(Carbon $at): bool
    {
        $days = $this->weekdays;

        return $days === [] || in_array($at->isoWeekday(), $days, true);
    }

    private function isWithinTimeWindow(Carbon $at): bool
    {
        $start = self::minutesOfDay($this->start_time);
        $end = self::minutesOfDay($this->end_time);

        // Sin horas configuradas la promoción aplica todo el día.
        if ($start === null || $end === null) {
            return true;
        }

        $current = $at->hour * 60 + $at->minute;

        if ($start <= $end) {
            return $current >= $start && $current <= $end;
        }

        // La franja cruza la medianoche (p. ej. 20:00 → 02:00).
        return $current >= $start || $current <= $end;
    }

    /**
     * Día al que pertenece la franja horaria (si cruza medianoche, la de ayer).
     */
    private function scheduleDay(Carbon $at): Carbon
    {
        $start = self::minutesOfDay($this->start_time);
        $end = self::minutesOfDay($this->end_time);

        if ($start === null || $end === null || $start <= $end) {
            return $at->copy();
        }

        $current = $at->hour * 60 + $at->minute;

        return $current <= $end ? $at->copy()->subDay() : $at->copy();
    }

    /**
     * Minutos desde medianoche de una columna TIME ('HH:MM' o 'HH:MM:SS').
     */
    public static function minutesOfDay(mixed $time): ?int
    {
        if ($time instanceof Carbon) {
            return $time->hour * 60 + $time->minute;
        }

        if (! is_string($time) || trim($time) === '') {
            return null;
        }

        $parts = explode(':', trim($time));

        if (count($parts) < 2) {
            return null;
        }

        return ((int) $parts[0] * 60) + (int) $parts[1];
    }

    /**
     * Hora en formato HH:MM para la respuesta.
     */
    public static function formatTime(mixed $time): ?string
    {
        $minutes = self::minutesOfDay($time);

        if ($minutes === null) {
            return null;
        }

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
