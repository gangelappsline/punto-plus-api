<?php

namespace App\Http\Requests\Reward;

use App\Enums\RewardType;
use App\Models\LoyaltyCard;
use App\Models\Reward;
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
        $isSpend = $this->card()?->isSpend() ?? false;
        $minPoints = config('punto_plus.cards.min_points_required');
        $maxPoints = config('punto_plus.cards.max_points_required');

        // En una tarjeta por consumo el umbral en puntos no puede faltar.
        $pointsRules = match (true) {
            ! $isSpend => ['sometimes', 'nullable', 'integer', 'min:'.$minPoints, 'max:'.$maxPoints],
            $this->reward()?->points_required === null => ['required', 'integer', 'min:'.$minPoints, 'max:'.$maxPoints],
            default => ['sometimes', 'integer', 'min:'.$minPoints, 'max:'.$maxPoints],
        };

        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'reward_type' => ['sometimes', Rule::enum(RewardType::class)],
            'value' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99'],

            'required_stamps' => [
                'sometimes',
                'nullable',
                'integer',
                'min:'.config('punto_plus.cards.min_required_stamps'),
                'max:'.config('punto_plus.cards.max_required_stamps'),
            ],
            'points_required' => $pointsRules,

            'stock' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function reward(): ?Reward
    {
        $reward = $this->route('reward');

        return $reward instanceof Reward ? $reward : null;
    }

    private function card(): ?LoyaltyCard
    {
        return $this->reward()?->loyaltyCard;
    }
}
