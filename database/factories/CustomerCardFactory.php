<?php

namespace Database\Factories;

use App\Enums\CardStatus;
use App\Models\LoyaltyCard;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CustomerCard>
 */
class CustomerCardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $loyaltyCard = LoyaltyCard::factory();

        return [
            'user_id' => User::factory()->cliente(),
            'loyalty_card_id' => $loyaltyCard,
            'business_id' => fn (array $attributes) => LoyaltyCard::find($attributes['loyalty_card_id'])?->business_id
                ?? \App\Models\Business::factory(),
            'code' => strtoupper(Str::random(10)),
            'stamps_count' => 0,
            'total_stamps_earned' => 0,
            'rewards_earned' => 0,
            'rewards_redeemed' => 0,
            'status' => CardStatus::Active,
            'last_stamp_at' => null,
        ];
    }

    public function forCard(LoyaltyCard $card, int $stamps = 0): static
    {
        return $this->state(fn (): array => [
            'loyalty_card_id' => $card->getKey(),
            'business_id' => $card->business_id,
            'stamps_count' => $stamps,
            'total_stamps_earned' => $stamps,
            'status' => $stamps >= $card->required_stamps ? CardStatus::Completed : CardStatus::Active,
            'last_stamp_at' => $stamps > 0 ? now() : null,
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (): array => ['user_id' => $user->getKey()]);
    }
}
