<?php

namespace App\Http\Requests\Promotion;

use App\Enums\DiscountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        return [
            'title' => ['sometimes', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'terms' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'loyalty_card_id' => ['sometimes', 'nullable', 'uuid', 'exists:loyalty_cards,id'],
            'discount_type' => ['sometimes', Rule::enum(DiscountType::class)],
            'discount_value' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'max_redemptions' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
