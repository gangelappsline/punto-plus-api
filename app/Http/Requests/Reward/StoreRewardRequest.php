<?php

namespace App\Http\Requests\Reward;

use App\Enums\RewardType;
use App\Models\LoyaltyCard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de una recompensa dentro de una tarjeta.
 *
 * El umbral depende del tipo de tarjeta:
 *  - tarjeta por visitas → `required_stamps` (si se omite, se usa el de la tarjeta);
 *  - tarjeta por consumo → `points_required` (obligatorio).
 */
class StoreRewardRequest extends FormRequest
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
        $card = $this->card();
        $isSpend = $card?->isSpend() ?? false;

        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'reward_type' => ['nullable', Rule::enum(RewardType::class)],
            'value' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],

            'required_stamps' => [
                'nullable',
                'integer',
                'min:'.config('punto_plus.cards.min_required_stamps'),
                'max:'.config('punto_plus.cards.max_required_stamps'),
            ],
            'points_required' => [
                'nullable',
                'integer',
                'min:'.config('punto_plus.cards.min_points_required'),
                'max:'.config('punto_plus.cards.max_points_required'),
                Rule::requiredIf($isSpend),
            ],

            'stock' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'points_required.required' => 'Esta tarjeta es por consumo: indica los puntos que desbloquean la recompensa.',
        ];
    }

    private function card(): ?LoyaltyCard
    {
        $card = $this->route('loyaltyCard');

        return $card instanceof LoyaltyCard ? $card : null;
    }
}
