<?php

namespace App\Http\Requests\Promotion;

use App\Enums\DiscountType;
use App\Enums\PromotionScheduleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de una promoción.
 *
 * `schedule_type` decide qué programación es obligatoria:
 *  - `indefinite`: `days_of_week` (1–7); sin fechas ni horas.
 *  - `date_range`: `starts_at` y `ends_at`.
 *  - `time_range`: `days_of_week`, `start_time` y `end_time`.
 */
class StorePromotionRequest extends FormRequest
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
        $scheduleType = $this->input('schedule_type') ?? PromotionScheduleType::DateRange->value;
        $usesDays = in_array($scheduleType, [
            PromotionScheduleType::Indefinite->value,
            PromotionScheduleType::TimeRange->value,
        ], true);
        $isDateRange = $scheduleType === PromotionScheduleType::DateRange->value;
        $isTimeRange = $scheduleType === PromotionScheduleType::TimeRange->value;

        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'loyalty_card_id' => ['nullable', 'uuid', 'exists:loyalty_cards,id'],
            'discount_type' => ['required', Rule::enum(DiscountType::class)],
            'discount_value' => [
                'nullable',
                'numeric',
                'min:0',
                'max:999999.99',
                Rule::requiredIf(fn (): bool => in_array($this->input('discount_type'), [
                    DiscountType::Percentage->value,
                    DiscountType::FixedAmount->value,
                ], true)),
            ],
            'code' => ['nullable', 'string', 'max:32'],

            // ---- Programación ----
            'schedule_type' => ['nullable', Rule::enum(PromotionScheduleType::class)],
            'days_of_week' => [
                'nullable',
                'array',
                'min:1',
                'max:7',
                Rule::requiredIf($usesDays),
            ],
            'days_of_week.*' => ['integer', 'distinct', 'between:1,7'],
            'start_time' => [
                'nullable',
                'string',
                'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/',
                Rule::requiredIf($isTimeRange),
            ],
            'end_time' => [
                'nullable',
                'string',
                'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/',
                Rule::requiredIf($isTimeRange),
            ],
            'starts_at' => ['nullable', 'date', Rule::requiredIf($isDateRange)],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at', Rule::requiredIf($isDateRange)],

            'is_active' => ['sometimes', 'boolean'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'discount_value.required' => 'Indica el valor del descuento (porcentaje o importe).',
            'ends_at.after_or_equal' => 'La fecha de fin no puede ser anterior a la de inicio.',
            'schedule_type.enum' => 'La programación debe ser `indefinite`, `date_range` o `time_range`.',
            'days_of_week.required' => 'Indica al menos un día de la semana (1 = lunes … 7 = domingo).',
            'days_of_week.*.between' => 'Los días van del 1 (lunes) al 7 (domingo).',
            'starts_at.required' => 'Un rango de fechas necesita fecha de inicio.',
            'ends_at.required' => 'Un rango de fechas necesita fecha de fin.',
            'start_time.required' => 'Un rango de hora necesita hora de inicio (HH:MM).',
            'end_time.required' => 'Un rango de hora necesita hora de fin (HH:MM).',
            'start_time.regex' => 'La hora debe tener formato HH:MM (24 h).',
            'end_time.regex' => 'La hora debe tener formato HH:MM (24 h).',
        ];
    }
}
