<?php

namespace App\Http\Requests\Promotion;

use App\Enums\DiscountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
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
            'ends_at.after' => 'La fecha de fin debe ser posterior a la de inicio.',
        ];
    }
}
