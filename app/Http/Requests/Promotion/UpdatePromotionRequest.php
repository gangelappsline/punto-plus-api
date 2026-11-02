<?php

namespace App\Http\Requests\Promotion;

use App\Enums\DiscountType;
use App\Enums\PromotionScheduleType;
use App\Models\Promotion;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Actualización parcial de una promoción.
 *
 * Si se envía `schedule_type`, deben enviarse también los campos que ese tipo
 * necesita; si no, sólo se validan los campos presentes.
 */
class UpdatePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $scheduleType = $this->resolvedScheduleType();
        $changingSchedule = $this->has('schedule_type');
        $usesDays = in_array($scheduleType, [
            PromotionScheduleType::Indefinite,
            PromotionScheduleType::TimeRange,
        ], true);
        $isDateRange = $scheduleType === PromotionScheduleType::DateRange;
        $isTimeRange = $scheduleType === PromotionScheduleType::TimeRange;

        // Al cambiar de programación los campos del nuevo tipo son obligatorios;
        // en el resto de los casos basta con validarlos si vienen.
        $daysRules = $changingSchedule && $usesDays
            ? ['required', 'array', 'min:1', 'max:7']
            : ['sometimes', 'nullable', 'array', 'min:1', 'max:7'];

        $timeRules = function (string $regex) use ($changingSchedule, $isTimeRange): array {
            return $changingSchedule && $isTimeRange
                ? ['required', 'string', 'regex:'.$regex]
                : ['sometimes', 'nullable', 'string', 'regex:'.$regex];
        };

        $startTimeRules = $timeRules('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/');
        $endTimeRules = $timeRules('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/');

        $startDateRules = $changingSchedule && $isDateRange
            ? ['required', 'date']
            : ['sometimes', 'nullable', 'date'];

        return [
            'title' => ['sometimes', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'terms' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'loyalty_card_id' => ['sometimes', 'nullable', 'uuid', 'exists:loyalty_cards,id'],
            'discount_type' => ['sometimes', Rule::enum(DiscountType::class)],
            'discount_value' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'code' => ['sometimes', 'nullable', 'string', 'max:32'],

            // ---- Programación ----
            'schedule_type' => ['sometimes', Rule::enum(PromotionScheduleType::class)],
            'days_of_week' => $daysRules,
            'days_of_week.*' => ['integer', 'distinct', 'between:1,7'],
            'start_time' => $startTimeRules,
            'end_time' => $endTimeRules,
            'starts_at' => $startDateRules,
            'ends_at' => array_merge($startDateRules, [$this->endsAtAfterStartsAt()]),

            'is_active' => ['sometimes', 'boolean'],
            'max_redemptions' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ends_at.after_or_equal' => 'La fecha de fin no puede ser anterior a la de inicio.',
            'schedule_type.enum' => 'La programación debe ser `indefinite`, `date_range` o `time_range`.',
            'days_of_week.required' => 'Indica al menos un día de la semana (1 = lunes … 7 = domingo).',
            'start_time.required' => 'Un rango de hora necesita hora de inicio (HH:MM).',
            'end_time.required' => 'Un rango de hora necesita hora de fin (HH:MM).',
        ];
    }

    private function promotion(): ?Promotion
    {
        $promotion = $this->route('promotion');

        return $promotion instanceof Promotion ? $promotion : null;
    }

    private function resolvedScheduleType(): PromotionScheduleType
    {
        return PromotionScheduleType::tryFrom((string) $this->input('schedule_type'))
            ?? $this->promotion()?->schedule_type
            ?? PromotionScheduleType::DateRange;
    }

    /**
     * `ends_at` no puede ser anterior a `starts_at` (el enviado o el ya guardado).
     */
    private function endsAtAfterStartsAt(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null) {
                return;
            }

            $startsAt = $this->input('starts_at');

            if ($startsAt === null || $startsAt === '') {
                $startsAt = $this->promotion()?->starts_at?->toIso8601String();
            }

            if ($startsAt === null || $startsAt === '') {
                return;
            }

            try {
                $start = Carbon::parse($startsAt);
                $end = Carbon::parse($value);
            } catch (Throwable) {
                return;
            }

            if ($end->lt($start)) {
                $fail('La fecha de fin no puede ser anterior a la de inicio.');
            }
        };
    }
}
