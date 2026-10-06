<?php

namespace App\Http\Requests\Reward;

use App\Enums\RewardType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRewardRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'reward_type' => ['sometimes', Rule::enum(RewardType::class)],
            'value' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'required_stamps' => [
                'sometimes',
                'integer',
                'min:'.config('punto_plus.cards.min_required_stamps'),
                'max:'.config('punto_plus.cards.max_required_stamps'),
            ],
            'stock' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
