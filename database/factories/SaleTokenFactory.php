<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\LoyaltyCard;
use App\Models\SaleToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SaleToken>
 */
class SaleTokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $loyaltyCard = LoyaltyCard::factory();

        return [
            'loyalty_card_id' => $loyaltyCard,
            'business_id' => fn (array $attributes) => LoyaltyCard::find($attributes['loyalty_card_id'])?->business_id
                ?? Business::factory(),
            'created_by' => User::factory()->negocio(),
            'purchase_amount' => null,
            'notes' => null,
            'expires_at' => now()->addMinutes(5),
        ];
    }

    public function forLoyaltyCard(LoyaltyCard $card): static
    {
        return $this->state(fn (): array => [
            'business_id' => $card->business_id,
            'loyalty_card_id' => $card->getKey(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }
}
