<?php

namespace App\Http\Requests\Promotion;

use App\Enums\DiscountType;
use App\Enums\PromotionScheduleType;
use App\Support\PromotionSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de una promoción.
 *
 * `schedule_type` es opcional: si no llega se deduce de los datos enviados.
 *  - sin fechas ni horas → `indefinite` (tiempo indefinido, no se exigen fechas);
 *  - con `starts_at`/`ends_at` → `date_range` (ambas fechas obligatorias);
 *  - con `start_time`/`end_time` → `time_range` (ambas horas obligatorias).
 *
 * `days_of_week` es siempre opcional: vacío significa todos los días.
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
        return $this->rulesFor($this->resolvedScheduleType());
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesFor(PromotionScheduleType $scheduleType): array
    {
        $needsDates = $scheduleType === PromotionScheduleType::DateRange;
        $needsTimes = $scheduleType === PromotionScheduleType::TimeRange;

        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'terms' => ['nullable', 'string', 'max:2000'],

            // Banner en base64 (data URI opcional): se guarda como imagen y se
            // persiste su ruta en `image_path`.
            'banner' => ['sometimes', 'nullable', 'string'],
            'remove_banner' => ['sometimes', 'boolean'],

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
            'days_of_week' => ['sometimes', 'nullable', 'array', 'min:1', 'max:7'],
            'days_of_week.*' => ['integer', 'distinct', 'between:1,7'],
            'start_time' => [
                'nullable',
                'string',
                'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/',
                Rule::requiredIf($needsTimes),
            ],
            'end_time' => [
                'nullable',
                'string',
                'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/',
                Rule::requiredIf($needsTimes),
            ],
            'starts_at' => ['nullable', 'date', Rule::requiredIf($needsDates)],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at', Rule::requiredIf($needsDates)],

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
            'days_of_week.*.between' => 'Los días van del 1 (lunes) al 7 (domingo).',
            'starts_at.required' => 'Un rango de fechas necesita fecha de inicio (déjala vacía para una promoción de tiempo indefinido).',
            'ends_at.required' => 'Un rango de fechas necesita fecha de fin (déjala vacía para una promoción de tiempo indefinido).',
            'start_time.required' => 'Un rango de hora necesita hora de inicio (HH:MM).',
            'end_time.required' => 'Un rango de hora necesita hora de fin (HH:MM).',
            'start_time.regex' => 'La hora debe tener formato HH:MM (24 h).',
            'end_time.regex' => 'La hora debe tener formato HH:MM (24 h).',
            'banner.string' => 'El banner debe ser una imagen en base64 o una URL.',
        ];
    }

    /**
     * Tipo de programación efectivo: el enviado o el deducido de las fechas/horas.
     */
    private function resolvedScheduleType(): PromotionScheduleType
    {
        return PromotionSchedule::resolveType($this->input('schedule_type'), $this->all());
    }
}
