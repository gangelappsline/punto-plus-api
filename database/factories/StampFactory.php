<?php

namespace Database\Factories;

use App\Enums\StampSource;
use App\Models\CustomerCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Stamp>
 */
class StampFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_card_id' => CustomerCard::factory(),
            'business_id' => fn (array $attributes) => CustomerCard::find($attributes['customer_card_id'])?->business_id,
            'loyalty_card_id' => fn (array $attributes) => CustomerCard::find($attributes['customer_card_id'])?->loyalty_card_id,
            'registered_by' => null,
            'source' => StampSource::Scan,
            'purchase_amount' => fake()->randomFloat(2, 2, 40),
            'notes' => null,
            'stamped_at' => now(),
        ];
    }

    public function forCard(CustomerCard $card, ?StampSource $source = null): static
    {
        return $this->state(fn (): array => [
            'customer_card_id' => $card->getKey(),
            'business_id' => $card->business_id,
            'loyalty_card_id' => $card->loyalty_card_id,
            'source' => $source ?? StampSource::Scan,
        ]);
    }
}
