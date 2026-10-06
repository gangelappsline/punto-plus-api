<?php

namespace Database\Factories;

use App\Enums\RewardType;
use App\Models\LoyaltyCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Reward>
 */
class RewardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'loyalty_card_id' => LoyaltyCard::factory(),
            'business_id' => fn (array $attributes) => LoyaltyCard::find($attributes['loyalty_card_id'])?->business_id,
            'name' => 'Café gratis',
            'description' => fake()->sentence(8),
            'reward_type' => RewardType::FreeProduct,
            'value' => null,
            'required_stamps' => fn (array $attributes) => (int) (LoyaltyCard::find($attributes['loyalty_card_id'])?->required_stamps ?? 10),
            'stock' => null,
            'is_active' => true,
        ];
    }

    public function forCard(LoyaltyCard $card, ?int $requiredStamps = null): static
    {
        return $this->state(fn (): array => [
            'loyalty_card_id' => $card->getKey(),
            'business_id' => $card->business_id,
            'required_stamps' => $requiredStamps ?? $card->required_stamps,
        ]);
    }
}
