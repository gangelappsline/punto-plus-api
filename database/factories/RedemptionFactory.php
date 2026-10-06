<?php

namespace Database\Factories;

use App\Enums\RedemptionStatus;
use App\Models\CustomerCard;
use App\Models\Reward;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Redemption>
 */
class RedemptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_card_id' => CustomerCard::factory(),
            'reward_id' => Reward::factory(),
            'business_id' => fn (array $attributes) => CustomerCard::find($attributes['customer_card_id'])?->business_id,
            'user_id' => fn (array $attributes) => CustomerCard::find($attributes['customer_card_id'])?->user_id,
            'code' => 'PP-'.strtoupper(Str::random(8)),
            'status' => RedemptionStatus::Pending,
            'stamps_used' => 1,
            'redeemed_at' => now(),
        ];
    }

    public function forCard(CustomerCard $card, Reward $reward): static
    {
        return $this->state(fn (): array => [
            'customer_card_id' => $card->getKey(),
            'reward_id' => $reward->getKey(),
            'business_id' => $card->business_id,
            'user_id' => $card->user_id,
            'stamps_used' => (int) $reward->required_stamps,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => RedemptionStatus::Completed,
            'approved_at' => now(),
        ]);
    }
}
