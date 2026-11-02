<?php

namespace App\Http\Requests\Promotion;

use App\Enums\DiscountType;
use App\Enums\PromotionScheduleType;
use App\Models\Promotion;
use App\Support\PromotionSchedule;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Actualización parcial de una promoción.
 *
 * El tipo de programación se resuelve con el enviado, con el ya guardado o con
 * el deducido de las fechas/horas enviadas. Sólo se exigen los campos del tipo
 * resultante cuando la petición toca la programación: una promoción de tiempo
 * indefinido nunca necesita fechas.
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
        $touchingSchedule = PromotionSchedule::touches($this->all());

        $needsDates = $touchingSchedule && $scheduleType === PromotionScheduleType::DateRange;
        $needsTimes = $touchingSchedule && $scheduleType === PromotionScheduleType::TimeRange;

        return [
            'title' => ['sometimes', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'terms' => ['sometimes', 'nullable', 'string', 'max:2000'],

            // Banner en base64 (data URI opcional). `remove_banner` lo elimina.
            'banner' => ['sometimes', 'nullable', 'string'],
            'remove_banner' => ['sometimes', 'boolean'],

            'loyalty_card_id' => ['sometimes', 'nullable', 'uuid', 'exists:loyalty_cards,id'],
            'discount_type' => ['sometimes', Rule::enum(DiscountType::class)],
            'discount_value' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'code' => ['sometimes', 'nullable', 'string', 'max:32'],

            // ---- Programación ----
            'schedule_type' => ['sometimes', Rule::enum(PromotionScheduleType::class)],
            'days_of_week' => ['sometimes', 'nullable', 'array', 'min:1', 'max:7'],
            'days_of_week.*' => ['integer', 'distinct', 'between:1,7'],
            'start_time' => $this->timeRules('start_time', $needsTimes),
            'end_time' => $this->timeRules('end_time', $needsTimes),
            'starts_at' => $this->dateRules('starts_at', $needsDates),
            'ends_at' => array_merge($this->dateRules('ends_at', $needsDates), [$this->endsAtAfterStartsAt()]),

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
     * Al cambiar el tipo, el campo es obligatorio; si la promoción ya lo tenía
     * guardado no hace falta volver a enviarlo.
     *
     * @return array<int, mixed>
     */
    private function dateRules(string $field, bool $needsDates): array
    {
        if ($needsDates) {
            return [$this->requiredWhenMissing($field), 'date'];
        }

        return ['sometimes', 'nullable', 'date'];
    }

    /**
     * @return array<int, mixed>
     */
    private function timeRules(string $field, bool $needsTimes): array
    {
        $regex = 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/';

        if ($needsTimes) {
            return [$this->requiredWhenMissing($field), 'string', $regex];
        }

        return ['sometimes', 'nullable', 'string', $regex];
    }

    /**
     * Obligatorio sólo si la promoción tampoco lo tenía guardado.
     */
    private function requiredWhenMissing(string $field): mixed
    {
        return Rule::requiredIf(
            fn (): bool => blank($this->input($field)) && blank($this->promotion()?->{$field}),
        );
    }

    private function promotion(): ?Promotion
    {
        $promotion = $this->route('promotion');

        return $promotion instanceof Promotion ? $promotion : null;
    }

    private function resolvedScheduleType(): PromotionScheduleType
    {
        return PromotionSchedule::resolveType(
            $this->input('schedule_type'),
            $this->all(),
            $this->promotion()?->schedule_type,
        );
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
